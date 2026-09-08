<?php
/**
 * When translation happens: on publish, on update, and on a schedule.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks changed content stale, and works through what is outstanding in the background.
 *
 * ⛔⛔ **NOTHING IS TRANSLATED IN THE REQUEST THAT SAVED THE POST.** A model call takes
 * seconds and 57 of them take minutes; doing that inside `save_post` means the editor's
 * Update button hangs, PHP's execution limit kills it half way, and the customer's own
 * publishing workflow is now the slowest thing on their site. `CLAUDE.md` §2.9 and §2.16 say
 * this outright: long work is not done in a request path. So a save marks rows stale — one
 * cheap UPDATE — and a scheduled worker does the translating.
 *
 * ⛔⛔ **AND THE WORKER HAS NO WORK CAP, ONLY A CONCURRENCY ONE** (`CLAUDE.md` §2.10). A
 * batch size bounds how much is done per RUN, and the run reschedules itself immediately
 * while work remains — so 10,000 outstanding strings are finished today rather than
 * truncated at 500 and forgotten. A bound that protects the provider is allowed; a bound
 * that drops the tail of the work list is the defect that rule exists to stop.
 *
 * ⭐ `source change → mark stale → re-translate` is `CLAUDE.md` §2.19's content lifecycle,
 * and staleness is decided by a hash of the text rather than by a timestamp — pressing
 * Update without changing a word must not re-buy 57 translations.
 */
final class Zinn_Translate_Queue {

	/**
	 * The cron hook the worker runs on.
	 */
	public const HOOK = 'zinn_translate_work';

	/**
	 * The recurring hook that keeps the inventory in step with the site.
	 */
	public const SWEEP_HOOK = 'zinn_translate_sweep';

	/**
	 * Register the lifecycle hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'save_post', array( $this, 'post_saved' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'term_saved' ), 20, 3 );
		add_action( 'created_term', array( $this, 'term_saved' ), 20, 3 );
		add_action( self::HOOK, array( $this, 'work' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}

	/**
	 * Make sure the recurring sweep is scheduled.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP_HOOK );
		}
	}

	/**
	 * Remove the schedule — on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * A post was saved: mark whatever changed stale, and start a run.
	 *
	 * @param int          $post_id The post.
	 * @param WP_Post|null $post    The post object.
	 * @param bool         $update  True when this was an update rather than a create.
	 * @return void
	 */
	public function post_saved( $post_id, $post = null, $update = false ): void {
		unset( $update );
		if ( ! Zinn_Translate_Options::flag( 'auto_translate' ) ) {
			return;
		}
		// ⛔ Autosaves and revisions are not the post. Translating an autosave would buy
		// words the customer has not decided to publish, and would do it every 60 seconds
		// while they are typing.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			// ⛔ An unpublished post is not on the site. If it was published and has been
			// un-published, its rows are retired by the sweep rather than here, so that a
			// momentary status change during an edit does not destroy finished work.
			return;
		}
		$ref    = $post->post_type . ':' . $post->ID;
		$fields = array(
			'title'   => $post->post_title,
			'body'    => $post->post_content,
			'excerpt' => $post->post_excerpt,
		);
		if ( Zinn_Translate_Options::flag( 'translate_slugs' ) ) {
			$fields['slug'] = $post->post_name;
		}
		foreach ( $fields as $field => $text ) {
			if ( '' !== trim( (string) $text ) ) {
				Zinn_Translate_Store::mark_stale( $ref, $field, Zinn_Translate_Store::hash( (string) $text ) );
			}
		}
		self::kick();
	}

	/**
	 * A post was deleted: forget its translations.
	 *
	 * ⛔ `CLAUDE.md` §2.52 — a re-key is a DELETE plus an INSERT and only the INSERT is
	 * automatic. Without this the table keeps 57 rows for every page the customer ever
	 * deleted, and the worker keeps offering them for re-translation for ever.
	 *
	 * @param int          $post_id The post.
	 * @param WP_Post|null $post    The post object.
	 * @return void
	 */
	public function post_deleted( $post_id, $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : '';
		if ( '' === $type ) {
			return;
		}
		Zinn_Translate_Store::forget( $type . ':' . (int) $post_id );
	}

	/**
	 * A term was created or edited.
	 *
	 * @param int    $term_id  The term.
	 * @param int    $tt_id    Its term-taxonomy id.
	 * @param string $taxonomy Its taxonomy.
	 * @return void
	 */
	public function term_saved( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		unset( $tt_id, $taxonomy );
		if ( ! Zinn_Translate_Options::flag( 'auto_translate' ) || ! Zinn_Translate_Options::flag( 'translate_terms' ) ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$ref = 'term:' . $term->term_id;
		Zinn_Translate_Store::mark_stale( $ref, 'name', Zinn_Translate_Store::hash( $term->name ) );
		if ( '' !== trim( $term->description ) ) {
			Zinn_Translate_Store::mark_stale( $ref, 'description', Zinn_Translate_Store::hash( $term->description ) );
		}
		self::kick();
	}

	/**
	 * Ask for a worker run soon, without scheduling a hundred of them.
	 *
	 * @return void
	 */
	public static function kick(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		// ⛔ A short delay rather than `spawn_cron()` now. Publishing ten posts in a minute
		// must not start ten workers competing for the same rows — one run picks up all of
		// the work, and the run reschedules itself while any remains.
		wp_schedule_single_event( time() + 30, self::HOOK );
	}

	/**
	 * Keep the inventory in step with the site, and push it to Zinn Digital®.
	 *
	 * @return array<string, int> What the sweep did.
	 */
	public function sweep(): array {
		if ( ! Zinn_Translate_Options::can_translate() ) {
			return array(
				'documents' => 0,
				'retired'   => 0,
				'pushed'    => 0,
			);
		}
		// ⛔ In the sweep, not in a render: this downloads from wordpress.org.
		Zinn_Translate_Locale_Switch::ensure_packs();
		Zinn_Translate_Locale_Switch::ensure_component_packs();
		$inventory = Zinn_Translate_Collector::inventory();
		$retired   = Zinn_Translate_Store::retire_absent( Zinn_Translate_Collector::refs( $inventory ) );
		$pushed    = 0;
		if ( 'byo' !== Zinn_Translate_Options::text( 'mode' ) && Zinn_Translate_Options::is_connected() ) {
			try {
				$result = Zinn_Translate_Provider_Factory::zinn()->submit( $inventory );
				$pushed = (int) ( $result['fields_registered'] ?? 0 );
				Zinn_Translate_Status::clear();
			} catch ( Zinn_Translate_Provider_Error $error ) {
				Zinn_Translate_Status::record( $error );
			}
		}
		self::kick();
		return array(
			'documents' => count( $inventory ),
			'retired'   => $retired,
			'pushed'    => $pushed,
		);
	}

	/**
	 * Translate a batch of what is outstanding, then reschedule if more remains.
	 *
	 * @return array<string, int> What this run did.
	 */
	public function work(): array {
		$done    = 0;
		$locales = Zinn_Translate_Options::locales();
		if ( array() === $locales || ! Zinn_Translate_Options::can_translate() ) {
			return array(
				'translated' => 0,
				'remaining'  => 0,
			);
		}
		$batch       = max( 1, (int) Zinn_Translate_Options::get( 'batch_size', 20 ) );
		$inventory   = Zinn_Translate_Collector::inventory();
		$outstanding = self::outstanding( $inventory, $locales );
		$remaining   = 0;

		foreach ( $outstanding as $locale => $groups ) {
			foreach ( $groups as $kind => $items ) {
				if ( $done >= $batch ) {
					$remaining += count( $items );
					continue;
				}
				$slice      = array_slice( $items, 0, $batch - $done, true );
				$remaining += count( $items ) - count( $slice );
				$done      += self::translate_slice( $slice, (string) $locale, (string) $kind );
			}
		}

		if ( $remaining > 0 ) {
			// ⛔⛔ RESCHEDULED IMMEDIATELY WHILE WORK REMAINS. This is what makes the batch a
			// concurrency bound rather than a work bound: nothing is dropped, it is only
			// paced. A run that finished its slice and stopped would leave a large site
			// permanently part-translated, with a screen reporting success.
			wp_schedule_single_event( time() + 60, self::HOOK );
		}
		return array(
			'translated' => $done,
			'remaining'  => $remaining,
		);
	}

	/**
	 * Everything that still needs translating, grouped by locale and by markup.
	 *
	 * ⛔⛔ **Grouped by KIND — prose, markup, slug — because the instruction a provider is
	 * given differs for all three and one of the differences is fatal.** Mixing a plain title
	 * into a batch declared as HTML is how a translated title comes back wrapped in a `<p>`
	 * tag. Mixing a SLUG into the prose batch is worse and was measured on a real site: the
	 * prose instruction says "preserve URLs verbatim", the model reads `about` as a URL,
	 * obeys, and returns it unchanged in all 57 languages — with nothing red anywhere,
	 * because an unchanged string is a valid translation as far as any check here can tell.
	 *
	 * @param array<int, array<string, mixed>> $inventory The site's inventory.
	 * @param string[]                         $locales   The locales to fill.
	 * @return array<string, array<string, array<string, array{text: string, hash: string}>>> The work.
	 */
	private static function outstanding( array $inventory, array $locales ): array {
		$work = array();
		foreach ( $locales as $locale ) {
			foreach ( $inventory as $document ) {
				$ref = (string) $document['doc_type'] . ':' . (string) $document['remote_id'];
				foreach ( (array) $document['fields'] as $field ) {
					$name = (string) $field['name'];
					$text = (string) $field['text'];
					$hash = Zinn_Translate_Store::hash( $text );
					if ( ! Zinn_Translate_Store::needs( $ref, $name, $locale, $hash ) ) {
						continue;
					}
					$work[ $locale ][ self::kind_of( $field ) ][ $ref . '#' . $name ] = array(
						'text' => $text,
						'hash' => $hash,
					);
				}
			}
		}
		return $work;
	}

	/**
	 * Which prompt one field needs.
	 *
	 * @param array<string, mixed> $field A collected field.
	 * @return string One of `Zinn_Translate_Prompt`'s KIND_ constants.
	 */
	private static function kind_of( array $field ): string {
		if ( ! empty( $field['is_slug'] ) ) {
			return Zinn_Translate_Prompt::KIND_SLUG;
		}
		return empty( $field['is_html'] ) ? Zinn_Translate_Prompt::KIND_TEXT : Zinn_Translate_Prompt::KIND_HTML;
	}

	/**
	 * Translate one slice and store what comes back.
	 *
	 * @param array<string, array{text: string, hash: string}> $slice   The work.
	 * @param string                                           $locale  The target language.
	 * @param string                                           $kind    Prose, markup or slug.
	 * @return int How many strings were stored.
	 */
	private static function translate_slice( array $slice, string $locale, string $kind ): int {
		if ( array() === $slice ) {
			return 0;
		}
		$strings = array();
		foreach ( $slice as $key => $item ) {
			$strings[ $key ] = $item['text'];
		}
		try {
			$translated = Zinn_Translate_Provider_Factory::current()->translate(
				$strings,
				Zinn_Translate_Options::source_locale(),
				$locale,
				$kind
			);
			Zinn_Translate_Status::clear();
		} catch ( Zinn_Translate_Provider_Error $error ) {
			// ⛔ Recorded for the settings screen and then STOPPED. Continuing to the next
			// locale after a credential failure would make 57 identical failing calls and,
			// on a rate-limited key, could get it blocked.
			Zinn_Translate_Status::record( $error );
			return 0;
		}
		$stored = 0;
		foreach ( $translated as $key => $text ) {
			if ( ! isset( $slice[ $key ] ) ) {
				continue;
			}
			$parts = explode( '#', (string) $key );
			$field = (string) array_pop( $parts );
			$ref   = implode( '#', $parts );
			if ( Zinn_Translate_Store::put( $ref, $field, $locale, $text, $slice[ $key ]['hash'] ) ) {
				++$stored;
			}
		}
		if ( $stored > 0 ) {
			Zinn_Translate_Router::flush();
		}
		return $stored;
	}

	/**
	 * How many strings are outstanding right now, for the settings screen.
	 *
	 * @return int The count.
	 */
	public static function outstanding_count(): int {
		$locales = Zinn_Translate_Options::locales();
		if ( array() === $locales ) {
			return 0;
		}
		$total = 0;
		foreach ( self::outstanding( Zinn_Translate_Collector::inventory(), $locales ) as $groups ) {
			foreach ( $groups as $items ) {
				$total += count( $items );
			}
		}
		return $total;
	}
}

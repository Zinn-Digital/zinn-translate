<?php
/**
 * Where translated strings live on the customer's own server.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One table, one row per (object, field, locale).
 *
 * ⛔⛔ **THIS PLUGIN NOW WRITES TO THE DATABASE, AND THE EARLIER REFUSAL TO WAS RIGHT FOR
 * ITS TIME.** Version 1.1.x stored nothing and rendered a bundle fetched from Zinn Digital®
 * on every request; deactivating it left the site byte-identical to what it was. That is a
 * genuinely good property and it was given up deliberately, for three things it made
 * impossible:
 *
 * 1. **A manual override that survives re-translation.** An override is a fact about this
 *    site that only this site knows. There is nowhere else to put it.
 * 2. **Standalone operation.** A site with no Zinn account has no bundle to fetch, so the
 *    translations must live here or nowhere.
 * 3. **Serving a page when we are unreachable.** A cache with a TTL fails closed after
 *    fifteen minutes; a table does not. Our outage stops being the customer's outage.
 *
 * ⛔ What has NOT changed: no duplicated posts, no shadow post type, no taxonomy. The
 * customer's own content is never touched, so uninstalling drops one table and the site is
 * exactly what it was. A customer who stops paying loses translations, never their words.
 *
 * ── ⛔ On `WordPress.DB.DirectDatabaseQuery` ──────────────────────────────────────────
 * Every query here is direct and prepared. There is no core API for a plugin's own table,
 * so the sniff's suggestion does not apply; the caching it asks about is the object cache
 * wrapper in :meth:`for_locale`, which is where a page render reads from.
 */
final class Zinn_Translate_Store {

	/**
	 * Bumped whenever the schema changes, so `maybe_install` knows to run `dbDelta` again.
	 */
	private const SCHEMA_VERSION = 1;

	private const SCHEMA_OPTION = 'zinn_translate_schema';

	private const CACHE_GROUP = 'zinn_translate';

	/**
	 * The field holding a document's translated URL segment.
	 */
	public const FIELD_SLUG = 'slug';

	/**
	 * The field holding translated URL segments this document USED to have.
	 */
	public const FIELD_SLUG_HISTORY = 'slug_history';

	/**
	 * How many superseded slugs are kept per document per language.
	 *
	 * ⛔ Bounded, because this is a list that only ever grows and it is written on every
	 * re-translation. Five is enough to cover a customer trying a few wordings; unbounded
	 * would be a row that grows for the life of the site with no one watching it.
	 */
	private const SLUG_HISTORY = 5;

	/**
	 * A translation produced by a machine, current against its source.
	 */
	public const STATUS_CURRENT = 'current';

	/**
	 * The source text changed after this translation was made.
	 *
	 * ⛔ Still SERVED. Slightly old French beats English, and beats a paragraph blanking
	 * itself mid-edit. Staleness decides what gets re-translated next, never what renders.
	 */
	public const STATUS_STALE = 'stale';

	/**
	 * A human typed this and it is not to be overwritten.
	 */
	public const STATUS_OVERRIDE = 'override';

	/**
	 * The table name, with this install's prefix.
	 *
	 * @return string Fully prefixed table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zinn_translations';
	}

	/**
	 * Create or upgrade the table if it is not current.
	 *
	 * ⛔ Called on `plugins_loaded` and not only on activation. A plugin updated by an
	 * automatic background update, by WP-CLI, or by copying files over does not re-run its
	 * activation hook — so a schema change that only ran there would leave a working site
	 * with a table one version behind and no error until the first query against a column
	 * that is not there.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Create the table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// ⛔ `object_ref` is `<doc_type>:<remote_id>` and NOT a post id column, because the
		// things this plugin translates are not all posts: a term, a menu item, a product
		// attribute and a widget all need a row and none of them has a post id. A typed
		// column per kind of thing would be five tables and five code paths.
		//
		// ⛔ `source_hash` rather than a timestamp. "Has the source changed?" is a question
		// about the TEXT; a timestamp answers "was it saved?", which is true every time
		// somebody opens the editor and presses Update without changing a word — and each
		// of those would re-buy 57 translations of an unchanged paragraph.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_ref VARCHAR(191) NOT NULL,
			field VARCHAR(64) NOT NULL,
			locale VARCHAR(12) NOT NULL,
			text LONGTEXT NOT NULL,
			source_hash CHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(12) NOT NULL DEFAULT 'current',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY zinn_tr_unique (object_ref, field, locale),
			KEY zinn_tr_locale (locale, status),
			KEY zinn_tr_field (field, locale)
		) {$collate};";
		dbDelta( $sql );
	}

	/**
	 * Drop the table. Called from `uninstall.php` only.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from $wpdb->prefix and a literal; it cannot be parameterised and contains no user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * The hash of a source string — what "has this changed?" is asked against.
	 *
	 * @param string $text Source text.
	 * @return string A 40-character hex digest.
	 */
	public static function hash( string $text ): string {
		return sha1( $text );
	}

	/**
	 * Store one translation.
	 *
	 * ⛔⛔ **An override is never overwritten by a machine.** This is the whole promise of
	 * the override feature: a customer who corrects a product name has corrected it for
	 * good, and the next re-translation must not silently undo their work. The guard is
	 * here, at the single write path, rather than in each caller — a rule reimplemented per
	 * caller is one chance per caller to forget it, and the caller that forgets fails
	 * silently and in the direction that destroys the customer's edit.
	 *
	 * @param string $object_ref  `<doc_type>:<remote_id>`.
	 * @param string $field       Field name.
	 * @param string $locale      Language code.
	 * @param string $text        The translated text.
	 * @param string $source_hash Hash of the source this was made from.
	 * @param bool   $is_override True when a human typed this.
	 * @return bool True when a row was written, false when an override was protected.
	 */
	public static function put(
		string $object_ref,
		string $field,
		string $locale,
		string $text,
		string $source_hash = '',
		bool $is_override = false
	): bool {
		global $wpdb;
		if ( ! $is_override ) {
			$existing = self::row( $object_ref, $field, $locale );
			if ( null !== $existing && self::STATUS_OVERRIDE === $existing['status'] ) {
				return false;
			}
		}
		if ( self::FIELD_SLUG === $field ) {
			self::remember_old_slug( $object_ref, $locale, $text );
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; there is no core API for it. The read path caches in for_locale().
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"INSERT INTO {$table} (object_ref, field, locale, text, source_hash, status, updated_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE text = VALUES(text), source_hash = VALUES(source_hash),
				 status = VALUES(status), updated_at = VALUES(updated_at)",
				$object_ref,
				$field,
				$locale,
				$text,
				$source_hash,
				$is_override ? self::STATUS_OVERRIDE : self::STATUS_CURRENT,
				current_time( 'mysql', true )
			)
		);
		self::flush_cache( $locale );
		return true;
	}

	/**
	 * Keep the slug a document is about to stop using, so its old URL still resolves.
	 *
	 * ⛔⛔ **A RE-TRANSLATION CHANGES SLUGS, AND WITHOUT THIS EVERY INDEXED TRANSLATED URL
	 * 404s WHEN IT DOES.** Measured on a real site: re-translating one page moved its German
	 * slug from `ueber-uns` to `info` and its Arabic from `an` to `hawl`, and both previous
	 * addresses — which are the ones in Google's index and in anybody's bookmarks — became
	 * dead. Nothing was red: the new URLs worked perfectly, the sitemap was correct, and the
	 * only symptom was traffic quietly arriving at a 404.
	 *
	 * ⭐ The old value is kept in the reverse slug index, so the router resolves it to the
	 * same document and the redirect that already exists for the untranslated form sends it
	 * on to the current one. One mechanism, two causes.
	 *
	 * @param string $object_ref `<doc_type>:<remote_id>`.
	 * @param string $locale     Language code.
	 * @param string $new_slug   The slug about to be stored.
	 * @return void
	 */
	private static function remember_old_slug( string $object_ref, string $locale, string $new_slug ): void {
		$existing = self::row( $object_ref, self::FIELD_SLUG, $locale );
		$old      = null === $existing ? '' : trim( $existing['text'] );
		if ( '' === $old || trim( $new_slug ) === $old ) {
			return;
		}
		$history = self::row( $object_ref, self::FIELD_SLUG_HISTORY, $locale );
		$kept    = null === $history ? array() : array_filter( explode( "\n", $history['text'] ) );
		array_unshift( $kept, $old );
		$kept = array_slice( array_values( array_unique( $kept ) ), 0, self::SLUG_HISTORY );

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; a write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"INSERT INTO {$table} (object_ref, field, locale, text, source_hash, status, updated_at)
				 VALUES (%s, %s, %s, %s, '', %s, %s)
				 ON DUPLICATE KEY UPDATE text = VALUES(text), updated_at = VALUES(updated_at)",
				$object_ref,
				self::FIELD_SLUG_HISTORY,
				$locale,
				implode( "\n", $kept ),
				self::STATUS_CURRENT,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * One stored row, or null.
	 *
	 * @param string $object_ref `<doc_type>:<remote_id>`.
	 * @param string $field      Field name.
	 * @param string $locale     Language code.
	 * @return array<string, string>|null The row, or null when there is none.
	 */
	public static function row( string $object_ref, string $field, string $locale ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; single-row admin read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"SELECT text, source_hash, status FROM {$table}
				 WHERE object_ref = %s AND field = %s AND locale = %s",
				$object_ref,
				$field,
				$locale
			),
			ARRAY_A
		);
		return is_array( $row ) ? array_map( 'strval', $row ) : null;
	}

	/**
	 * Every translation this site holds in one locale, keyed `<object_ref>` => field => text.
	 *
	 * ⛔⛔ **ONE query per request, cached, and that is not an optimisation.** A page renders
	 * dozens of strings — a title, a body, an excerpt, a menu of twelve labels, a sidebar,
	 * ten product names in a grid. A per-string query would be an N+1 on somebody else's
	 * server, and the plugin would be the reason their site got slower after they bought a
	 * translation for it.
	 *
	 * ⛔ Rows of EVERY status are returned, override and stale included. Staleness decides
	 * what is re-translated; it never decides what renders.
	 *
	 * @param string $locale Language code.
	 * @return array<string, array<string, string>> Fields keyed by object ref.
	 */
	public static function for_locale( string $locale ): array {
		$key    = 'store_' . $locale;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; the result IS the cache being populated on the next line.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"SELECT object_ref, field, text FROM {$table} WHERE locale = %s AND text <> ''",
				$locale
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['object_ref'] ][ (string) $row['field'] ] = (string) $row['text'];
		}
		wp_cache_set( $key, $out, self::CACHE_GROUP, 300 );
		return $out;
	}

	/**
	 * Every translation in one locale, keyed by the HASH of the text it was made from.
	 *
	 * ⭐⭐ **"Translate this string wherever it appears", without knowing whose string it is.**
	 * Most of the plugin looks a translation up by object — this page's title, that term's
	 * name — because it knows what it is rendering. Some things are handed a bare string with
	 * no owner attached: a value inside a schema graph, a label in a block a theme built. For
	 * those, hashing what is on the page and asking whether we translated exactly that text is
	 * the only lookup that cannot mis-assign.
	 *
	 * ⛔ It is CONTENT-ADDRESSED and that is the safety property. The alternative — assuming
	 * that whatever is being rendered belongs to the current post — put the page's title into
	 * a schema graph's `WebSite` node, where the SITE's name belongs. It looked right in every
	 * other node, which is exactly why it shipped.
	 *
	 * ⛔ Overrides are absent from this index: they are stored with an empty `source_hash`
	 * because a human typed them rather than a machine translating a known source. They are
	 * still served by every by-object lookup, which is the path that matters for them.
	 *
	 * @param string $locale Language code.
	 * @return array<string, string> Source hash => translation.
	 */
	public static function by_source_hash( string $locale ): array {
		$key    = 'byhash_' . $locale;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT source_hash, text FROM {$table}
			 WHERE locale = %s AND source_hash <> '' AND text <> ''";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefixed table name plus one bound value.
		$prepared = $wpdb->prepare( $sql, $locale );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; the result IS the cache being populated below.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source_hash'] ] = (string) $row['text'];
		}
		wp_cache_set( $key, $out, self::CACHE_GROUP, 300 );
		return $out;
	}

	/**
	 * Mark every locale's translation of one field stale, unless it is an override.
	 *
	 * ⭐ This is §2.19's *"source change → mark stale → re-translate"* in one statement.
	 * ⛔ It is scoped by the CURRENT source hash: rows already made from the new text are
	 * left alone, so re-saving a post without editing it marks nothing and buys nothing.
	 *
	 * @param string $object_ref  `<doc_type>:<remote_id>`.
	 * @param string $field       Field name.
	 * @param string $source_hash Hash of the source as it is NOW.
	 * @return int How many rows were marked.
	 */
	public static function mark_stale( string $object_ref, string $field, string $source_hash ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; a write.
		$count = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"UPDATE {$table} SET status = %s WHERE object_ref = %s AND field = %s
				 AND source_hash <> %s AND status <> %s",
				self::STATUS_STALE,
				$object_ref,
				$field,
				$source_hash,
				self::STATUS_OVERRIDE
			)
		);
		self::flush_cache();
		return (int) $count;
	}

	/**
	 * Delete every row for documents the site no longer has.
	 *
	 * ⛔⛔ **`CLAUDE.md` §2.52 — a re-key is a DELETE plus an INSERT and only the INSERT is
	 * automatic.** Without this the table grows with the customer's edit history for ever,
	 * every deleted page keeps 57 rows, and — worse — the queue keeps offering those rows
	 * for re-translation, so the customer pays to translate pages that do not exist.
	 *
	 * @param string[] $keep_refs Every object ref the site still has.
	 * @return int How many rows were removed.
	 */
	public static function retire_absent( array $keep_refs ): int {
		global $wpdb;
		if ( array() === $keep_refs ) {
			// ⛔⛔ An EMPTY keep-list deletes nothing, and that is not timidity. "The site
			// has no content" and "the collector could not run" produce the same empty
			// array, and only one of them means the rows should go (§2.44). Refusing costs
			// a few stale rows; obeying costs every translation the site has.
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $keep_refs ), '%s' ) );
		// ⛔ The IN() list is a generated run of `%s` placeholders and NOTHING else — every
		// value is bound by `prepare()`. The count comes from `count( $keep_refs )` and the
		// arguments from the same array, so the two cannot disagree.
		$sql = "DELETE FROM {$table} WHERE object_ref NOT IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- As above: the statement is a prefixed table name plus generated `%s` placeholders, and every value is bound here.
		$prepared = $wpdb->prepare( $sql, ...array_values( $keep_refs ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; the argument is the prepared statement from the line above.
		$count = $wpdb->query( $prepared );
		self::flush_cache();
		return (int) $count;
	}

	/**
	 * Delete every row for one document — used when a post is deleted.
	 *
	 * @param string $object_ref `<doc_type>:<remote_id>`.
	 * @return int How many rows were removed.
	 */
	public static function forget( string $object_ref ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; a write.
		$count = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
				"DELETE FROM {$table} WHERE object_ref = %s",
				$object_ref
			)
		);
		self::flush_cache();
		return (int) $count;
	}

	/**
	 * How many rows the site holds, by status, for one locale or all of them.
	 *
	 * ⛔ Reported per status rather than as a percentage. "94% complete" is the figure that
	 * let this platform believe a million translations were being served when none of them
	 * were; a count of what is current, what is stale and what is overridden is a fact.
	 *
	 * @param string $locale A language code, or an empty string for every locale.
	 * @return array<string, int> Counts keyed by status.
	 */
	public static function counts( string $locale = '' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' === $locale ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; an admin screen read.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; no user input.
				"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; an admin screen read.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
					"SELECT status, COUNT(*) AS total FROM {$table} WHERE locale = %s GROUP BY status",
					$locale
				),
				ARRAY_A
			);
		}
		$out = array(
			self::STATUS_CURRENT  => 0,
			self::STATUS_STALE    => 0,
			self::STATUS_OVERRIDE => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Rows for the manual-override screen, newest first.
	 *
	 * @param string $locale Language code, or empty for all.
	 * @param string $search Text to match in the source ref or the translation.
	 * @param int    $limit  Page size.
	 * @param int    $offset Rows to skip.
	 * @return array<int, array<string, string>> The rows.
	 */
	public static function browse( string $locale, string $search, int $limit, int $offset ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $locale ) {
			$where[]  = 'locale = %s';
			$params[] = $locale;
		}
		if ( '' !== $search ) {
			$where[]  = '(object_ref LIKE %s OR text LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = max( 1, $limit );
		$params[] = max( 0, $offset );
		$clause   = implode( ' AND ', $where );
		// ⛔ `$clause` is built from string LITERALS in this function and placeholders only —
		// no caller input reaches it. The values are appended to `$params` in the same order
		// the placeholders were added, which is why the two are built side by side above.
		$sql = "SELECT object_ref, field, locale, text, status FROM {$table}
			 WHERE {$clause} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- As above: the WHERE clause is literals and placeholders only, and every value is bound here.
		$prepared = $wpdb->prepare( $sql, ...$params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table; the argument is the prepared statement from the line above.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		return array_map(
			static function ( $row ): array {
				return array_map( 'strval', (array) $row );
			},
			(array) $rows
		);
	}

	/**
	 * Rows still needing a machine translation, for the queue worker.
	 *
	 * ⛔ Overrides are excluded by construction: they are the one status that must never be
	 * offered for re-translation.
	 *
	 * @param string $object_ref `<doc_type>:<remote_id>`.
	 * @param string $field      Field name.
	 * @param string $locale     Language code.
	 * @param string $hash       The source hash as it is now.
	 * @return bool True when this field needs translating in this locale.
	 */
	public static function needs( string $object_ref, string $field, string $locale, string $hash ): bool {
		$row = self::row( $object_ref, $field, $locale );
		if ( null === $row ) {
			return true;
		}
		if ( self::STATUS_OVERRIDE === $row['status'] ) {
			return false;
		}
		return $row['source_hash'] !== $hash || '' === trim( $row['text'] );
	}

	/**
	 * Drop the cached read for one locale, or for all of them.
	 *
	 * @param string $locale Language code, or empty for every locale.
	 * @return void
	 */
	public static function flush_cache( string $locale = '' ): void {
		if ( '' !== $locale ) {
			wp_cache_delete( 'store_' . $locale, self::CACHE_GROUP );
			wp_cache_delete( 'byhash_' . $locale, self::CACHE_GROUP );
			return;
		}
		foreach ( Zinn_Translate_Locales::codes() as $code ) {
			wp_cache_delete( 'store_' . $code, self::CACHE_GROUP );
			wp_cache_delete( 'byhash_' . $code, self::CACHE_GROUP );
		}
	}
}

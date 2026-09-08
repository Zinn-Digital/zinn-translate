<?php
/**
 * Per-language sitemaps, and the `llms.txt` files an answer engine reads.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes one sitemap per language, an index over them, and per-language `llms.txt`.
 *
 * ⛔⛔ **OUR OWN ROUTES, NEVER AN INJECTION INTO SOMEBODY ELSE'S SITEMAP.** Yoast, Rank
 * Math and All in One SEO each build `/sitemap_index.xml` and each treats it as theirs. A
 * plugin that filters extra URLs into another plugin's sitemap breaks on that plugin's next
 * release, and the breakage is silent: the sitemap still validates, it just stops carrying
 * the translated URLs, and nobody notices until traffic falls. So these live at
 * `/zinn-sitemap-index.xml` and `/zinn-sitemap-<locale>.xml`, they are announced in `robots.txt`
 * alongside whatever else is there, and both can be submitted independently.
 *
 * ⭐ Core's own sitemap provider is extended as well when nothing else owns it, because a
 * site with no SEO plugin should not have to know any of this.
 *
 * ── ⛔ Why `llms.txt` is here and not in its own class ────────────────────────────────
 * It answers the same question a sitemap does — *"what does this site publish, in this
 * language, at what address?"* — from the same list, and the two going out of step is
 * exactly the drift `CLAUDE.md` §2.18 is written against. One producer, two renderings.
 */
final class Zinn_Translate_Sitemap {

	/**
	 * How many URLs one language's sitemap carries.
	 *
	 * ⛔ Below the 50,000 protocol limit by a wide margin and deliberately so: the limit is
	 * also 50 MB uncompressed, and a site with long translated slugs reaches the byte
	 * ceiling first. A sitemap over either limit is rejected WHOLE — not truncated — so the
	 * conservative number is the one that keeps a large site's URLs discoverable at all.
	 */
	private const MAX_URLS = 2000;

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register_rules' ), 1 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots' ), 20, 2 );
	}

	/**
	 * Add the sitemap and llms.txt rewrite rules.
	 *
	 * @return void
	 */
	public function register_rules(): void {
		// ⛔⛔ `zinn-sitemap-index.xml`, NOT `zinn-sitemap.xml`, AND THE NAME IS THE FIX.
		// Yoast registers a rewrite for its own per-type sitemaps whose pattern is `<type>` then
		// `-sitemap` then `.xml`, and `zinn-sitemap.xml` matches it with the type `zinn` — so
		// Yoast claimed the URL, found no such type, and answered 404. Measured:
		// `/zinn-sitemap.xml` was 200 on a site with no SEO plugin and 404 the moment Yoast
		// activated, while `/zinn-sitemap-fr.xml` kept working, because it ends `-fr.xml` and
		// does not match that pattern. Ending the index in `-index.xml` cannot match it either,
		// whatever order the rules end up in — which is a better guarantee than winning a
		// precedence race with somebody else's plugin on somebody else's site.
		add_rewrite_rule( '^zinn-sitemap-index\.xml$', 'index.php?zinn_sitemap=index', 'top' );
		add_rewrite_rule(
			'^zinn-sitemap-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.xml$',
			'index.php?zinn_sitemap=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^([a-z]{2,3}(?:-[a-z0-9]{2,8})?)/llms\.txt$',
			'index.php?zinn_llms=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^([a-z]{2,3}(?:-[a-z0-9]{2,8})?)/llms-full\.txt$',
			'index.php?zinn_llms=$matches[1]&zinn_llms_full=1',
			'top'
		);
		add_rewrite_rule( '^llms\.txt$', 'index.php?zinn_llms=source', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?zinn_llms=source&zinn_llms_full=1', 'top' );
	}

	/**
	 * Declare our query vars.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[] The vars with ours appended.
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'zinn_sitemap';
		$vars[] = 'zinn_llms';
		$vars[] = 'zinn_llms_full';
		return $vars;
	}

	/**
	 * Serve a sitemap or an llms file if this request is for one.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$sitemap = (string) get_query_var( 'zinn_sitemap', '' );
		$llms    = (string) get_query_var( 'zinn_llms', '' );
		if ( '' !== $sitemap ) {
			if ( ! Zinn_Translate_Options::flag( 'sitemaps' ) ) {
				return;
			}
			$this->render_sitemap( $sitemap );
		}
		if ( '' !== $llms ) {
			if ( ! Zinn_Translate_Options::flag( 'llms_txt' ) ) {
				return;
			}
			$full = (string) get_query_var( 'zinn_llms_full', '' );
			$this->render_llms( $llms, '' !== $full );
		}
	}

	/**
	 * Every sitemap URL a site owner should be able to see and check.
	 *
	 * ⭐ Public because the settings screen shows them. `CLAUDE.md` asked for the URLs to be
	 * SHOWN to the owner and for them to WORK, and a list built from the same function that
	 * routes them cannot disagree with what is served.
	 *
	 * @return array<string, string> Label => absolute URL.
	 */
	public static function urls(): array {
		$urls = array(
			__( 'Sitemap index', 'zinn-translate' ) => home_url( '/zinn-sitemap-index.xml' ),
		);
		foreach ( Zinn_Translate_Options::locales() as $locale ) {
			/* translators: %s: a language name, for example "French". */
			$label          = sprintf( __( '%s sitemap', 'zinn-translate' ), Zinn_Translate_Locales::name( $locale ) );
			$urls[ $label ] = home_url( '/zinn-sitemap-' . $locale . '.xml' );
		}
		if ( Zinn_Translate_Options::flag( 'llms_txt' ) ) {
			$urls[ __( 'LLM content map', 'zinn-translate' ) ] = home_url( '/llms.txt' );
			foreach ( Zinn_Translate_Options::locales() as $locale ) {
				/* translators: %s: a language name, for example "French". */
				$label          = sprintf( __( '%s LLM content map', 'zinn-translate' ), Zinn_Translate_Locales::name( $locale ) );
				$urls[ $label ] = home_url( '/' . $locale . '/llms.txt' );
			}
		}
		return $urls;
	}

	/**
	 * Render the index, or one locale's sitemap.
	 *
	 * @param string $which `index`, or a locale code.
	 * @return void
	 */
	private function render_sitemap( string $which ): void {
		$locales = Zinn_Translate_Options::locales();
		// Same shadowing as `render_llms` — see its note.
		$from_router = Zinn_Translate_Router::current();
		if ( '' !== $from_router && 'index' !== $which ) {
			$which = $from_router;
		}
		header( 'Content-Type: application/xml; charset=UTF-8' );
		// ⛔ `X-Robots-Tag: noindex` on the sitemap itself. A sitemap is an instruction to a
		// crawler, not a page for a reader, and an indexed sitemap is a thin page in search
		// results carrying every URL on the site.
		header( 'X-Robots-Tag: noindex, follow' );

		if ( 'index' === $which ) {
			echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach ( $locales as $locale ) {
				printf(
					"\t<sitemap><loc>%s</loc></sitemap>\n",
					esc_url( home_url( '/zinn-sitemap-' . $locale . '.xml' ) )
				);
			}
			echo '</sitemapindex>';
			exit;
		}

		if ( ! in_array( $which, $locales, true ) ) {
			// ⛔ 404 for a language the site does not publish, rather than an empty sitemap.
			// An empty but valid sitemap tells a crawler "this language has no pages", which
			// is a claim; a 404 tells it "there is nothing here", which is the truth.
			status_header( 404 );
			exit;
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
		echo 'xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
		foreach ( self::entries() as $entry ) {
			printf( "\t<url>\n\t\t<loc>%s</loc>\n", esc_url( self::localised( $entry['path'], $which ) ) );
			if ( '' !== $entry['modified'] ) {
				printf( "\t\t<lastmod>%s</lastmod>\n", esc_html( $entry['modified'] ) );
			}
			// ⛔⛔ The `xhtml:link` alternates are what make a per-language sitemap worth
			// having. A sitemap listing only one language's URLs tells a crawler those pages
			// exist; the alternates tell it they are the SAME page in different languages,
			// which is the half that stops them competing with each other.
			printf(
				"\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
				esc_attr( Zinn_Translate_Locales::hreflang( Zinn_Translate_Options::source_locale() ) ),
				esc_url( home_url( $entry['path'] ) )
			);
			foreach ( $locales as $locale ) {
				printf(
					"\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
					esc_attr( Zinn_Translate_Locales::hreflang( $locale ) ),
					esc_url( self::localised( $entry['path'], $locale ) )
				);
			}
			echo "\t</url>\n";
		}
		echo '</urlset>';
		exit;
	}

	/**
	 * Render `llms.txt` or `llms-full.txt` for one locale.
	 *
	 * ⭐ `CLAUDE.md` §2.18: an answer engine may not run JavaScript and may never fetch a
	 * sitemap, but it will read a plain-text content map. The translated one exists because
	 * a site that is available in Arabic and whose only machine-readable summary is in
	 * English will be quoted in English to Arabic-speaking askers.
	 *
	 * @param string $locale A locale code, or `source`.
	 * @param bool   $full   True for `llms-full.txt`, which carries the page text.
	 * @return void
	 */
	private function render_llms( string $locale, bool $full ): void {
		// ⛔⛔ **THE ROUTER'S LOCALE RULE MATCHES `/fr/llms.txt` FIRST, AND ITS RE-PARSE THEN
		// RESOLVES THE REMAINING `/llms.txt` TO THE *SOURCE* FILE.** Both sets of rules are
		// registered `top` and WordPress matches them in the order they were added, so the
		// router — which boots first — wins. The symptom was a file served at `/fr/llms.txt`
		// with French URLs in it and `Language: English` at the top: correct in the half a
		// reader notices and wrong in the half a machine reads. Asking the router what locale
		// this request is for fixes it from either route and does not depend on rule order,
		// which is the kind of thing that changes when somebody adds a third rewrite rule.
		$from_router = Zinn_Translate_Router::current();
		$code        = '' !== $from_router ? $from_router : ( 'source' === $locale ? '' : $locale );
		if ( '' !== $code && ! in_array( $code, Zinn_Translate_Options::locales(), true ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		$name  = (string) get_option( 'blogname', '' );
		$about = (string) get_option( 'blogdescription', '' );
		if ( '' !== $code ) {
			$name  = Zinn_Translate_Store::for_locale( $code )['site:options']['blogname'] ?? $name;
			$about = Zinn_Translate_Store::for_locale( $code )['site:options']['blogdescription'] ?? $about;
		}

		// ⛔⛔ **`esc_html()` IS THE WRONG ESCAPE FOR A `text/plain` BODY AND IT CORRUPTS IT.**
		// The response is not HTML, so there is no markup context to escape into: an
		// ampersand in a site's name came out as `&amp;` in a file whose whole audience is
		// machines reading plain text, and an answer engine would quote the company's name
		// with an entity in it. `zinn_translate_plain()` strips tags and control characters,
		// which is what "make this safe for a text file" actually means.
		echo '# ' . zinn_translate_plain( $name ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised for a text/plain body by zinn_translate_plain(); HTML escaping would corrupt it.
		if ( '' !== trim( $about ) ) {
			echo '> ' . zinn_translate_plain( $about ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- As above.
		}
		echo 'Language: ' . zinn_translate_plain( Zinn_Translate_Locales::name( '' === $code ? Zinn_Translate_Options::source_locale() : $code ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- As above.
		echo "## Pages\n\n";
		foreach ( self::entries() as $entry ) {
			$title = $entry['title'];
			if ( '' !== $code ) {
				$translated = Zinn_Translate_Store::for_locale( $code )[ $entry['ref'] ]['title'] ?? '';
				if ( '' !== $translated ) {
					$title = $translated;
				}
			}
			printf( "- [%s](%s)\n", zinn_translate_plain( $title ), esc_url_raw( self::localised( $entry['path'], $code ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain body; see the note above.
			if ( ! $full ) {
				continue;
			}
			$body = $entry['body'];
			if ( '' !== $code ) {
				$translated = Zinn_Translate_Store::for_locale( $code )[ $entry['ref'] ]['body'] ?? '';
				if ( '' !== $translated ) {
					$body = $translated;
				}
			}
			$text = trim( wp_strip_all_tags( $body ) );
			if ( '' !== $text ) {
				echo "\n" . zinn_translate_plain( $text ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain body; see the note above.
			}
		}
		exit;
	}

	/**
	 * The published pages both renderings are built from.
	 *
	 * @return array<int, array{ref: string, path: string, title: string, body: string, modified: string}> The entries.
	 */
	private static function entries(): array {
		$types   = Zinn_Translate_Options::get( 'post_types', array( 'post', 'page' ) );
		$query   = new WP_Query(
			array(
				'post_type'              => is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_URLS,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$entries = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			// ⛔⛔ `get_permalink()` IS FILTERED BY THIS PLUGIN'S OWN ROUTER, so inside a
			// translated request it already returns `/fr/a-propos/`. Building a sitemap from
			// that produced `/fr/fr/a-propos/` for every other language and, worse, silently
			// correct-looking output for the current one. The path is normalised back to the
			// site's own language here, once, so every URL in this file is derived from the
			// same base whichever locale asked for it.
			$raw       = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
			$current   = Zinn_Translate_Router::current();
			$stripped  = Zinn_Translate_Router::strip_prefix( '' === $raw ? '/' : $raw, $current );
			$source    = Zinn_Translate_Router::to_source_path( ltrim( $stripped, '/' ), $current );
			$path      = '' === trim( $source, '/' ) ? '/' : user_trailingslashit( '/' . trim( $source, '/' ) );
			$entries[] = array(
				'ref'      => $post->post_type . ':' . $post->ID,
				'path'     => '' === $path ? '/' : $path,
				'title'    => $post->post_title,
				'body'     => $post->post_content,
				'modified' => (string) get_post_modified_time( 'c', true, $post ),
			);
		}
		return $entries;
	}

	/**
	 * One source path as an absolute URL in one locale.
	 *
	 * @param string $path   A source path.
	 * @param string $locale A locale code, or empty for the source language.
	 * @return string The absolute URL.
	 */
	private static function localised( string $path, string $locale ): string {
		if ( '' === $locale ) {
			return home_url( $path );
		}
		return home_url( '/' . $locale . Zinn_Translate_Router::to_localised_path( $path, $locale ) );
	}

	/**
	 * Announce the sitemap index in `robots.txt`.
	 *
	 * ⛔ Appended, never replacing what is there. Another plugin's `Sitemap:` line is that
	 * plugin's to manage, and a robots.txt filter that returns only its own output is how a
	 * site loses its main sitemap the day it installs a second plugin.
	 *
	 * @param string $output The robots.txt WordPress built.
	 * @param string $is_public Whether the site is public.
	 * @return string The robots.txt with our sitemap announced.
	 */
	public function robots( $output, $is_public = '' ): string {
		if ( '1' !== (string) $is_public || ! Zinn_Translate_Options::flag( 'sitemaps' ) ) {
			return (string) $output;
		}
		if ( array() === Zinn_Translate_Options::locales() ) {
			return (string) $output;
		}
		return rtrim( (string) $output ) . "\nSitemap: " . home_url( '/zinn-sitemap-index.xml' ) . "\n";
	}
}

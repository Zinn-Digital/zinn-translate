<?php
/**
 * Locale-prefixed URLs, translated slugs, and getting a visitor to the right page.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the rewrite rules, resolves translated slugs, and localises every link.
 *
 * ⛔⛔ A PREFIX, NOT A SUBDOMAIN AND NOT A QUERY STRING, AND THE CHOICE IS AN SEO DECISION
 * RATHER THAN A TASTE ONE. A subdomain needs DNS and a certificate per language — 57 of each
 * on a customer's domain, which we cannot do without touching their DNS. A query string
 * (`?lang=fr`) is routinely treated as the same URL as the original by search engines, so the
 * translated version may never be indexed at all — which would make the whole product
 * invisible to exactly the audience it is bought for.
 *
 * ⛔ The rules are registered for the SERVED locales only. A rule for a language nobody
 * enabled would answer a URL the site does not publish, which is a thin duplicate page for a
 * crawler to find.
 */
class Zinn_Translate_Router {

	/**
	 * Reverse slug indexes, one per locale, built at most once per request.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $slug_index = array();

	/**
	 * This site's own address, resolved once without firing our own `home_url` filter.
	 *
	 * @var string|null
	 */
	private static ?string $home_base = null;

	/**
	 * Source slug => translated slug, per locale. Built at most once per request.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $forward_index = array();

	/**
	 * Register the rewrite rules and the request hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Priority 1: rules must exist before WordPress parses the request.
		add_action( 'init', array( $this, 'register_rules' ), 1 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'capture' ) );
		add_action( 'template_redirect', array( $this, 'redirect_untranslated_slug' ), 1 );
		add_filter( 'post_link', array( $this, 'localise_permalink' ), 20, 2 );
		add_filter( 'page_link', array( $this, 'localise_page_link' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'localise_permalink' ), 20, 2 );
		add_filter( 'term_link', array( $this, 'localise_term_link' ), 20, 3 );
		add_filter( 'home_url', array( $this, 'localise_home_url' ), 20, 2 );
	}

	/**
	 * The locale for the current request, or empty for the original language.
	 *
	 * @return string A language code, or an empty string.
	 */
	public static function current(): string {
		// ⛔⛔ **THE GUARD IS NOT DEFENSIVE PADDING — WITHOUT IT, INSTALLING YOAST FATALS THE
		// WHOLE SITE.** `get_query_var()` calls `$GLOBALS['wp_query']->get()`, and
		// `$wp_query` does not exist until `wp` runs. Yoast builds its sitemap renderer on
		// `plugins_loaded`, which calls `home_url()`, which fires this class's own
		// `home_url` filter, which asks for the locale — long before there is a query to ask.
		// Measured: `Call to a member function get() on null in wp-includes/query.php:29`,
		// a white screen on every request, front end and admin, the moment Yoast activates.
		//
		// ⭐ Found only by installing Yoast on a real site to check the compatibility this
		// plugin claims. Every route was green, the whole suite passed, and the estate had no
		// SEO plugin on it — so the one thing the compatibility layer exists for was the one
		// thing never exercised (`CLAUDE.md` §2.40: a control only sees where it is pointed).
		if ( ! isset( $GLOBALS['wp_query'] ) || ! $GLOBALS['wp_query'] instanceof WP_Query ) {
			return '';
		}
		$locale = get_query_var( 'zinn_locale', '' );
		return is_string( $locale ) ? $locale : '';
	}

	/**
	 * Add one rewrite rule per served locale.
	 *
	 * @return void
	 */
	public function register_rules(): void {
		$locales = Zinn_Translate_Options::locales();
		if ( array() === $locales ) {
			return;
		}
		// The alternation is built from validated codes only — `Options::locales()` refuses
		// anything that is not in the generated language table, precisely because this value
		// is interpolated into a regular expression that governs every URL on the site.
		$alternation = implode( '|', array_map( 'preg_quote', $locales ) );

		// ⛔ Two rules, and the order matters. The first sends `/fr/` to the front page; the
		// second sends `/fr/anything/` to whatever `/anything/` would have been. Registering
		// only the second leaves the translated home page 404ing, which is the single page
		// most likely to be linked.
		add_rewrite_rule( '^(' . $alternation . ')/?$', 'index.php?zinn_locale=$matches[1]', 'top' );
		add_rewrite_rule(
			'^(' . $alternation . ')/(.+?)/?$',
			'index.php?zinn_locale=$matches[1]&zinn_path=$matches[2]',
			'top'
		);
	}

	/**
	 * Declare the two query vars the rewrite rules set.
	 *
	 * @param string[] $vars Existing public query vars.
	 * @return string[] The vars with ours appended.
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'zinn_locale';
		$vars[] = 'zinn_path';
		return $vars;
	}

	/**
	 * Re-parse `zinn_path` as if the locale prefix had not been there.
	 *
	 * ⭐ This is what makes the plugin work without duplicating a single post. WordPress
	 * resolves `/fr/about/` by being handed `/about/` and doing exactly what it always does;
	 * the renderer then swaps the words. Nothing about permalinks, pagination, feeds or
	 * templates has to know that a translation exists.
	 *
	 * ⛔⛔ **The translated slug is turned back into the original BEFORE that re-parse.**
	 * `/fr/a-propos/` has to reach the same post as `/about/`, and WordPress has never heard
	 * of `a-propos` — there is no second post, by design. So each segment is looked up in
	 * this locale's slug index and rewritten to the source slug; a segment we have no
	 * translation for is passed through untouched, which is what makes a partially
	 * translated site keep working rather than 404 half of itself.
	 *
	 * @param WP $wp The request object WordPress is about to resolve.
	 * @return void
	 */
	public function capture( $wp ): void {
		if ( ! isset( $wp->query_vars['zinn_locale'] ) ) {
			return;
		}
		$locale = (string) $wp->query_vars['zinn_locale'];
		$path   = isset( $wp->query_vars['zinn_path'] ) ? (string) $wp->query_vars['zinn_path'] : '';
		unset( $wp->query_vars['zinn_path'] );

		$source = self::to_source_path( $path, $locale );

		$inner = new WP();
		// ⛔⛔ **A FRESH `WP` HAS ONLY CORE'S QUERY VARS, AND WITHOUT THIS LINE EVERY CUSTOM
		// POST TYPE SILENTLY 404s INTO THE BLOG.** `public_query_vars` is a plain property
		// that custom post types, custom taxonomies and plugins append to on the GLOBAL `$wp`
		// during `init` — `product`, `portfolio`, `event`, everything. A new instance never
		// received any of that, so `parse_request()` matched the right rewrite rule and then
		// threw away the variable it produced: measured on a real shop, `/fr/product/…`
		// answered 200 and rendered the blog home page. Nothing was red, the URL resolved,
		// the router looked correct, and the wrong page was served (`CLAUDE.md` §2.24).
		$inner->public_query_vars  = $wp->public_query_vars;
		$inner->private_query_vars = $wp->private_query_vars;
		$inner->extra_query_vars   = $wp->extra_query_vars;
		// ⛔ `$_SERVER['REQUEST_URI']` is what `WP::parse_request()` reads, so it is set to
		// the un-prefixed, un-translated path and restored immediately afterwards. Leaving
		// it rewritten would hand every later consumer — canonical, pagination, an analytics
		// plugin — a URL the visitor never asked for.
		$original_uri           = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$_SERVER['REQUEST_URI'] = '/' . ltrim( $source, '/' );
		$inner->parse_request( array() );
		$_SERVER['REQUEST_URI'] = $original_uri;

		$carried = $inner->query_vars;
		unset( $carried['zinn_locale'], $carried['zinn_path'] );
		$wp->query_vars = array_merge( $carried, array( 'zinn_locale' => $locale ) );
	}

	/**
	 * Send `/fr/about/` to `/fr/a-propos/` when a translated slug exists.
	 *
	 * ⛔⛔ **A 301, and it is what makes translated slugs safe to turn on.** Without it,
	 * every URL that was indexed before the customer enabled slug translation keeps
	 * resolving, so the same page is reachable at two addresses in one language — the
	 * duplicate-content problem the whole product is supposed to avoid. With it, the old
	 * address keeps working for ever and passes its authority to the new one.
	 *
	 * @return void
	 */
	public function redirect_untranslated_slug(): void {
		$locale = self::current();
		if ( '' === $locale || is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! Zinn_Translate_Options::flag( 'translate_slugs' ) ) {
			return;
		}
		$requested = self::request_path();
		$stripped  = self::strip_prefix( $requested, $locale );
		$wanted    = self::to_localised_path( self::to_source_path( $stripped, $locale ), $locale );
		$target    = '/' . trim( $locale, '/' ) . $wanted;
		if ( untrailingslashit( $target ) === untrailingslashit( $requested ) ) {
			return;
		}
		// ⛔ Only ever redirect to a path on this site, built from our own index — never to
		// anything derived from the request. A redirect target that a visitor can influence
		// is an open redirect, and this runs on tens of thousands of sites.
		wp_safe_redirect( home_url( $target ), 301 );
		exit;
	}

	/**
	 * Turn a translated path into the source path WordPress can resolve.
	 *
	 * @param string $path   The requested path, without the locale prefix.
	 * @param string $locale The request's locale.
	 * @return string The path in the site's own language.
	 */
	public static function to_source_path( string $path, string $locale ): string {
		if ( ! Zinn_Translate_Options::flag( 'translate_slugs' ) ) {
			return $path;
		}
		$index = self::reverse_slug_index( $locale );
		if ( array() === $index ) {
			return $path;
		}
		$segments = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			$segments[] = $index[ $segment ] ?? $segment;
		}
		return implode( '/', array_filter( $segments, 'strlen' ) );
	}

	/**
	 * Turn a source path into the translated path for a locale.
	 *
	 * @param string $path   A path in the site's own language, no locale prefix.
	 * @param string $locale The target locale.
	 * @return string The translated path, always starting with `/`.
	 */
	public static function to_localised_path( string $path, string $locale ): string {
		$trimmed = trim( $path, '/' );
		if ( '' === $trimmed ) {
			return '/';
		}
		if ( Zinn_Translate_Options::flag( 'translate_slugs' ) ) {
			$forward  = self::forward_slug_index( $locale );
			$segments = array();
			foreach ( explode( '/', $trimmed ) as $segment ) {
				$segments[] = $forward[ $segment ] ?? $segment;
			}
			$trimmed = implode( '/', $segments );
		}
		// ⛔⛔ `user_trailingslashit`, because an hreflang annotation must name the CANONICAL
		// url and a site whose permalinks end in a slash canonicalises `/about` to `/about/`.
		// Announcing the un-slashed form makes every alternate a redirect: the set still
		// looks right in the source, and a crawler following it lands one hop from where it
		// was told to go, which is the commonest reason an hreflang set is quietly ignored.
		return user_trailingslashit( '/' . $trimmed );
	}

	/**
	 * Translated slug => source slug, for one locale.
	 *
	 * ⛔ Built once per request from the store's own cached read, never queried per segment.
	 * A per-segment query would be an N+1 against somebody else's database on every page
	 * view, and the plugin would be the reason their site got slower.
	 *
	 * ⛔⛔ A translated slug that collides with another document's is DROPPED, first one
	 * wins, and the loser keeps its source slug. Two pages at one URL is the failure mode
	 * where a customer's page silently becomes unreachable — and it is not hypothetical:
	 * genuinely different English words collapse into one word in several languages.
	 *
	 * @param string $locale Language code.
	 * @return array<string, string> Translated slug => source slug.
	 */
	public static function reverse_slug_index( string $locale ): array {
		if ( isset( self::$slug_index[ $locale ] ) ) {
			return self::$slug_index[ $locale ];
		}
		$index = array();
		foreach ( Zinn_Translate_Store::for_locale( $locale ) as $ref => $fields ) {
			if ( ! isset( $fields['slug'] ) ) {
				continue;
			}
			$translated = sanitize_title( $fields['slug'] );
			$source     = self::source_slug_for( $ref );
			if ( '' === $translated || '' === $source || $translated === $source ) {
				continue;
			}
			if ( isset( $index[ $translated ] ) ) {
				continue;
			}
			$index[ $translated ] = $source;
		}
		// ⛔⛔ SUPERSEDED SLUGS RESOLVE TOO, and they are added AFTER the current ones so a
		// live slug always wins a collision with a retired one. Without this, re-translating
		// a page kills every URL of it that anybody has ever linked or indexed — the new
		// address works, the sitemap is right, and the traffic lands on a 404. Because they
		// resolve to the same document, `redirect_untranslated_slug` then 301s them to the
		// current address with no extra machinery.
		foreach ( Zinn_Translate_Store::for_locale( $locale ) as $ref => $fields ) {
			if ( ! isset( $fields[ Zinn_Translate_Store::FIELD_SLUG_HISTORY ] ) ) {
				continue;
			}
			$source = self::source_slug_for( $ref );
			if ( '' === $source ) {
				continue;
			}
			foreach ( explode( "\n", $fields[ Zinn_Translate_Store::FIELD_SLUG_HISTORY ] ) as $old ) {
				$old = sanitize_title( trim( $old ) );
				if ( '' !== $old && ! isset( $index[ $old ] ) ) {
					$index[ $old ] = $source;
				}
			}
		}
		self::$slug_index[ $locale ] = $index;
		return $index;
	}

	/**
	 * Source slug => translated slug, for one locale. The CURRENT slug only.
	 *
	 * ⛔⛔ **NOT `array_flip()` OF THE REVERSE INDEX, AND THAT MISTAKE INVERTED THE
	 * REDIRECT.** The reverse index deliberately contains superseded slugs so old URLs keep
	 * resolving; flipping it collapses several keys onto one source slug and PHP keeps the
	 * last, which is a retired one. The live symptom was precise and backwards: the new
	 * address 301'd to the old address, and the old address answered 200. Everything looked
	 * like it was working — there was a redirect, it went somewhere real, and the page
	 * rendered — which is why only asking for both URLs and reading the status codes found it.
	 *
	 * @param string $locale Language code.
	 * @return array<string, string> Source slug => translated slug.
	 */
	public static function forward_slug_index( string $locale ): array {
		if ( isset( self::$forward_index[ $locale ] ) ) {
			return self::$forward_index[ $locale ];
		}
		$index = array();
		foreach ( Zinn_Translate_Store::for_locale( $locale ) as $ref => $fields ) {
			if ( ! isset( $fields[ Zinn_Translate_Store::FIELD_SLUG ] ) ) {
				continue;
			}
			$translated = sanitize_title( $fields[ Zinn_Translate_Store::FIELD_SLUG ] );
			$source     = self::source_slug_for( $ref );
			if ( '' === $translated || '' === $source || isset( $index[ $source ] ) ) {
				continue;
			}
			$index[ $source ] = $translated;
		}
		self::$forward_index[ $locale ] = $index;
		return $index;
	}

	/**
	 * The site's own slug for one object ref.
	 *
	 * @param string $ref `<doc_type>:<remote_id>`.
	 * @return string The source slug, or an empty string.
	 */
	private static function source_slug_for( string $ref ): string {
		$parts = explode( ':', $ref, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		list( $type, $id ) = $parts;
		if ( 'term' === $type ) {
			$term = get_term( (int) $id );
			return $term instanceof WP_Term ? $term->slug : '';
		}
		$post = get_post( (int) $id );
		return $post instanceof WP_Post ? $post->post_name : '';
	}

	/**
	 * Put the current locale's prefix and translated slugs onto a permalink.
	 *
	 * ⛔⛔ **Without this the site leaks out of its own language on the first click.** A
	 * visitor reading `/fr/a-propos/` follows a link in the body or the menu, WordPress
	 * renders `/contact/` because permalinks know nothing about locales, and they are back
	 * in English with no way to tell what happened.
	 *
	 * @param string       $url  The permalink WordPress built.
	 * @param WP_Post|null $post The post it belongs to.
	 * @return string The permalink, localised for this request.
	 */
	public function localise_permalink( $url, $post = null ): string {
		unset( $post );
		return self::localise_url( (string) $url, self::current() );
	}

	/**
	 * `page_link` passes a post ID rather than an object; the localisation is identical.
	 *
	 * @param string $url The permalink WordPress built.
	 * @param int    $post_id The page id.
	 * @return string The permalink, localised.
	 */
	public function localise_page_link( $url, $post_id = 0 ): string {
		unset( $post_id );
		return self::localise_url( (string) $url, self::current() );
	}

	/**
	 * The same for a term archive link.
	 *
	 * @param string       $url      The term link.
	 * @param WP_Term|null $term     The term.
	 * @param string       $taxonomy Its taxonomy.
	 * @return string The link, localised.
	 */
	public function localise_term_link( $url, $term = null, $taxonomy = '' ): string {
		unset( $term, $taxonomy );
		return self::localise_url( (string) $url, self::current() );
	}

	/**
	 * `home_url()` inside a translated request points at the translated home page.
	 *
	 * ⛔ Only the empty path is localised. `home_url( '/wp-json/' )` and
	 * `home_url( '/wp-login.php' )` must not gain a locale prefix — the REST API and the
	 * login screen do not live under one, and prefixing them 404s a site's own admin.
	 *
	 * @param string $url  The URL core built.
	 * @param string $path The path it was asked for.
	 * @return string The URL, localised where that is correct.
	 */
	public function localise_home_url( $url, $path = '' ): string {
		$locale = self::current();
		if ( '' === $locale || ! in_array( trim( (string) $path, '/' ), array( '' ), true ) ) {
			return (string) $url;
		}
		return self::localise_url( (string) $url, $locale );
	}

	/**
	 * This site's address, WITHOUT going through the `home_url` filter.
	 *
	 * ⛔⛔ **CALLING `home_url()` HERE IS A FATAL, AND IT LOOKED PERFECTLY CORRECT.** This
	 * class filters `home_url`; :meth:`localise_url` used to call `home_url()` to find the
	 * prefix to strip — so the filter called the function that fires the filter, and every
	 * request for a translated page recursed until PHP ran out of memory. Nothing about
	 * either line is wrong on its own, which is exactly why re-reading them confirms both
	 * (`CLAUDE.md` §2.24): the defect is in the interaction, and only running it shows it.
	 *
	 * ⛔ `get_option( 'home' )` rather than `home_url()`, and the scheme normalised
	 * separately. The option is the raw site address and no filter of ours touches it, so
	 * this cannot re-enter however many of our own filters are on the stack.
	 *
	 * @return string The site address with no trailing slash.
	 */
	public static function home_base(): string {
		if ( null !== self::$home_base ) {
			return self::$home_base;
		}
		self::$home_base = untrailingslashit( (string) set_url_scheme( (string) get_option( 'home' ) ) );
		return self::$home_base;
	}

	/**
	 * Rewrite an absolute URL on this site into one locale.
	 *
	 * ⛔ Off-site URLs are returned untouched, and so is a URL that already carries the
	 * prefix — otherwise a filter that runs twice produces `/fr/fr/about/`, which is a 404
	 * announced to crawlers in an hreflang tag.
	 *
	 * @param string $url    An absolute URL.
	 * @param string $locale The target locale, or empty to leave it alone.
	 * @return string The localised URL.
	 */
	public static function localise_url( string $url, string $locale ): string {
		if ( '' === $locale || '' === $url ) {
			return $url;
		}
		$home = self::home_base();
		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		$path = (string) wp_parse_url( $rest, PHP_URL_PATH );
		if ( str_starts_with( ltrim( $path, '/' ) . '/', $locale . '/' ) ) {
			return $url;
		}
		$tail      = substr( $rest, strlen( $path ) );
		$localised = self::to_localised_path( $path, $locale );
		return $home . '/' . $locale . $localised . $tail;
	}

	/**
	 * The path of the current request, always starting with `/`.
	 *
	 * @return string The request path.
	 */
	public static function request_path(): string {
		// ⛔ SANITIZED, not merely unslashed. `$_SERVER['REQUEST_URI']` is attacker-controlled
		// on every request and this value is interpolated into `href` attributes that we then
		// announce to search engines as this page's canonical alternates.
		$raw = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$uri = (string) wp_parse_url( $raw, PHP_URL_PATH );
		return '' === $uri ? '/' : '/' . ltrim( $uri, '/' );
	}

	/**
	 * A path with one locale prefix removed.
	 *
	 * @param string $path   The path.
	 * @param string $locale The prefix to remove.
	 * @return string The path without it, always starting with `/`.
	 */
	public static function strip_prefix( string $path, string $locale ): string {
		if ( '' !== $locale && str_starts_with( $path, '/' . $locale ) ) {
			$path = substr( $path, strlen( $locale ) + 1 );
		}
		return '' === $path ? '/' : '/' . ltrim( $path, '/' );
	}

	/**
	 * Forget the cached slug indexes — after a translation is saved.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$slug_index    = array();
		self::$forward_index = array();
		self::$home_base     = null;
	}
}

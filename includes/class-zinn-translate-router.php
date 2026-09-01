<?php
/**
 * Locale-prefixed URLs — `/fr/about/` serving the same page as `/about/`, in French.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the rewrite rules and decides which locale a request is for.
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
	 * The locale captured for this request, or null before `parse_request` has run.
	 *
	 * @var string|null
	 */
	private ?string $locale = null;

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
	}

	/**
	 * The locale for the current request, or empty for the original language.
	 */
	public static function current(): string {
		$locale = get_query_var( 'zinn_locale', '' );
		return is_string( $locale ) ? $locale : '';
	}

	/**
	 * Add one rewrite rule per served locale.
	 *
	 * ⛔ Served locales only. A rule for a language nobody enabled answers a URL the site
	 * does not publish, which is a thin duplicate page for a crawler to find.
	 *
	 * @return void
	 */
	public function register_rules(): void {
		$settings = new Zinn_Translate_Settings();
		$locales  = $settings->locales();
		if ( array() === $locales ) {
			return;
		}
		// The alternation is built from validated codes only — `Settings::locales()` refuses
		// anything that is not a language tag, precisely because this value is interpolated
		// into a regular expression that governs every URL on the site.
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

		$inner = new WP();
		$inner->parse_request( array() );
		// Re-run the ordinary parse against the un-prefixed path.
		$_SERVER['REQUEST_URI'] = '/' . ltrim( $path, '/' );
		$inner->parse_request( array() );

		$carried = $inner->query_vars;
		unset( $carried['zinn_locale'], $carried['zinn_path'] );
		$wp->query_vars = array_merge( $carried, array( 'zinn_locale' => $locale ) );
		$this->locale   = $locale;
	}
}

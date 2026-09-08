<?php
/**
 * Telling WordPress which language the page is in, so IT translates its own strings.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switches WordPress's own locale for a translated request.
 *
 * ⛔⛔ **WITHOUT THIS A TRANSLATED SHOP STILL SAYS "ADD TO CART".** Everything else in this
 * plugin translates the CUSTOMER'S content — their pages, their products, their menus. It
 * cannot touch the theme's and WooCommerce's own interface strings, because those are not
 * content: they are `__()` calls whose translations WordPress already has, in a language
 * pack, sitting on the server unused. Measured on the demo shop: a French product page with
 * a French title, a French description and a French breadcrumb, and `Add to cart`,
 * `Description`, `Additional information` and `Reviews (0)` in English underneath them. A
 * shopper reads that as a half-finished site, and they are right.
 *
 * ⭐ So the fix is not to translate those strings — that would be paying a model to reproduce
 * work the WordPress community has already done and given away. It is to tell WordPress what
 * language the page is in and let it load the pack.
 *
 * ⛔ **It switches only to a pack that is actually INSTALLED**, checked against
 * `get_available_languages()`. Switching to a locale with no pack silently produces English
 * with a different locale name attached — which is what it was already doing — while also
 * changing date formats and number formatting for no benefit.
 */
final class Zinn_Translate_Locale_Switch {

	/**
	 * The option recording which language packs we have tried to install.
	 */
	private const INSTALLED_OPTION = 'zinn_translate_packs';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// ⛔ `determine_locale` rather than `switch_to_locale()` on an early action, because
		// it runs before the first `load_textdomain()` — a switch made later leaves whatever
		// has already loaded in the original language, which is a page in two languages.
		add_filter( 'determine_locale', array( $this, 'locale' ), 20 );
	}

	/**
	 * The WordPress locale for this request.
	 *
	 * @param string $locale The locale WordPress worked out.
	 * @return string The locale to use.
	 */
	public function locale( $locale ): string {
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			// ⛔ The front end only. Switching the admin's language because a visitor is
			// reading the French version of a page would change the language of the site
			// owner's own dashboard, which is nothing to do with what a visitor asked for.
			return (string) $locale;
		}
		$code = self::requested_code();
		if ( '' === $code ) {
			return (string) $locale;
		}
		$installed = self::installed_for( $code );
		return '' === $installed ? (string) $locale : $installed;
	}

	/**
	 * The locale code this request is for, read from the URL.
	 *
	 * ⛔⛔ Read from `$_SERVER['REQUEST_URI']` and NOT from the router, because
	 * `determine_locale` fires long before `parse_request` — the query vars do not exist yet.
	 * A version of this that asked the router returned an empty string on every request and
	 * did nothing at all, while looking exactly right.
	 *
	 * @return string A language code we publish, or an empty string.
	 */
	private static function requested_code(): string {
		$raw   = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$path  = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$first = strtolower( (string) strtok( ltrim( $path, '/' ), '/' ) );
		if ( '' === $first ) {
			return '';
		}
		return in_array( $first, Zinn_Translate_Options::locales(), true ) ? $first : '';
	}

	/**
	 * The installed WordPress locale matching a language code, or an empty string.
	 *
	 * ⛔ Matched by PREFIX, because WordPress ships `fr_FR` where our registry says `fr`, and
	 * `pt_BR` and `pt_PT` both answer to `pt`. Taking the first match is right: a site that
	 * has installed exactly one Portuguese pack means that one.
	 *
	 * @param string $code Our language code.
	 * @return string A WordPress locale, or an empty string when no pack is installed.
	 */
	public static function installed_for( string $code ): string {
		return self::pick( $code, get_available_languages() );
	}

	/**
	 * The best locale for a language code out of a list of locales.
	 *
	 * ⛔⛔ **THE CONVENTIONAL FORM FIRST, AND THE ORDER IS NOT COSMETIC.** A bare prefix scan
	 * over wordpress.org's alphabetical list picks `fr_CA` for French and `de_AT` for German —
	 * both real, both valid, and both the wrong dialect for the overwhelming majority of a
	 * site's readers. Measured: the first version of this installed exactly those two. Trying
	 * `fr_FR` and `de_DE` first costs one array lookup and gets the common case right.
	 *
	 * @param string   $code    Our language code.
	 * @param string[] $locales Locales to choose from.
	 * @return string The chosen locale, or an empty string.
	 */
	private static function pick( string $code, array $locales ): string {
		if ( in_array( $code, $locales, true ) ) {
			return $code;
		}
		$conventional = $code . '_' . strtoupper( $code );
		if ( in_array( $conventional, $locales, true ) ) {
			return $conventional;
		}
		foreach ( $locales as $locale ) {
			if ( str_starts_with( (string) $locale, $code . '_' ) ) {
				return (string) $locale;
			}
		}
		return '';
	}

	/**
	 * Download the language packs for every published locale that has none.
	 *
	 * ⛔ Called from the hourly sweep and from WP-CLI, never from a page render: it downloads
	 * files from wordpress.org, and a visitor's page load must never wait on that.
	 *
	 * ⛔ Each locale is attempted ONCE and the attempt is recorded whether it succeeded or
	 * not. A locale with no pack — and there are several among the 58 — would otherwise be
	 * re-requested from wordpress.org every hour for ever, on every site that publishes it.
	 *
	 * @return array<string, string> Locale => what happened.
	 */
	public static function ensure_packs(): array {
		$tried  = get_option( self::INSTALLED_OPTION, array() );
		$tried  = is_array( $tried ) ? $tried : array();
		$result = array();
		foreach ( Zinn_Translate_Options::locales() as $code ) {
			if ( '' !== self::installed_for( $code ) ) {
				$result[ $code ] = 'installed';
				continue;
			}
			if ( isset( $tried[ $code ] ) ) {
				$result[ $code ] = 'unavailable';
				continue;
			}
			$tried[ $code ] = time();
			$wp_locale      = self::wordpress_locale_for( $code );
			if ( '' === $wp_locale ) {
				$result[ $code ] = 'no matching WordPress locale';
				continue;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			$done            = wp_download_language_pack( $wp_locale );
			$result[ $code ] = false === $done ? 'download failed' : 'downloaded ' . (string) $done;
		}
		update_option( self::INSTALLED_OPTION, $tried, false );
		return $result;
	}

	/**
	 * The WordPress locale wordpress.org offers for one of our language codes.
	 *
	 * ⛔ Asked of wordpress.org's own list rather than mapped from a table here. A hand-written
	 * `fr => fr_FR` map is a hand-maintained enumeration of somebody else's data
	 * (`CLAUDE.md` §2.24), and the entry that goes missing is never the one you are reading.
	 *
	 * @param string $code Our language code.
	 * @return string A WordPress locale, or an empty string.
	 */
	private static function wordpress_locale_for( string $code ): string {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$available = wp_get_available_translations();
		if ( ! is_array( $available ) ) {
			return '';
		}
		return self::pick( $code, array_map( 'strval', array_keys( $available ) ) );
	}

	/**
	 * Install the THEME and PLUGIN translations for every installed locale.
	 *
	 * ⛔⛔ **`wp_download_language_pack()` FETCHES WordPress CORE AND NOTHING ELSE, AND ON A
	 * SHOP THAT IS THE SMALL HALF.** Measured on the demo: core packs installed, the locale
	 * switched correctly, and `Add to cart`, `Description`, `Additional information` and
	 * `Reviews (0)` were all still English — because those strings belong to WooCommerce and
	 * to the theme, whose packs are distributed separately. A customer looking at that sees a
	 * product page that is half translated and concludes the product does not work.
	 *
	 * ⛔ Every failure is reported rather than swallowed. A theme with no translation for a
	 * language is an ordinary and permanent state, and the site owner is entitled to know
	 * which of their languages it applies to rather than wondering why one looks unfinished.
	 *
	 * @return array<string, string> What happened, keyed by what was updated.
	 */
	public static function ensure_component_packs(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		wp_clean_update_cache();
		wp_update_plugins();
		wp_update_themes();
		$updates = wp_get_translation_updates();
		if ( array() === $updates ) {
			return array();
		}
		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$results  = $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => false ) );
		$out      = array();
		foreach ( (array) $updates as $index => $update ) {
			$slug         = (string) ( $update->slug ?? 'core' ) . ':' . (string) ( $update->language ?? '' );
			$done         = is_array( $results ) && ! empty( $results[ $index ] ) && ! is_wp_error( $results[ $index ] );
			$out[ $slug ] = $done ? 'installed' : 'failed';
		}
		return $out;
	}
}

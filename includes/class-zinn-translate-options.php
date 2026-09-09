<?php
/**
 * Every setting this plugin has, with its default, in one place.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the plugin's settings, wherever they are stored.
 *
 * ⭐⭐ **One accessor, and every other class reads through it.** The alternative — each
 * class calling `get_option()` with its own default — is the shape where a boolean means
 * "on" in the renderer and "off" in the sitemap because two authors picked two defaults,
 * and nothing anywhere is red. The defaults are declared once, here, and merged on read.
 *
 * ⛔ **The shared settings framework (W41-Q) is the owner of the SCREEN, not of this.**
 * `Zinn_Translate_Admin_UI::get()` is the front-end-and-CLI-safe reader that framework
 * provides, and this class calls it when it is present. It exists because the plugin's
 * front end, its cron worker and WP-CLI all need settings, and none of them should have to
 * know which of the two paths supplied them.
 */
final class Zinn_Translate_Options {

	/**
	 * The single option the shared settings framework owns.
	 *
	 * ⛔ The name is fixed by W41-Q's contract and must not be changed here: the framework
	 * reads and writes this exact key, and a plugin writing somewhere else would configure
	 * a screen the framework then reads as empty — the silent failure §2.44 is about.
	 */
	public const OPTION = 'zinn_translate_settings';

	/**
	 * Legacy scalar options from 1.1.x, folded into the array by the framework's migration.
	 *
	 * ⛔⛔ **They are READ here as a fallback and never deleted.** Version 1.1.2 is already
	 * installed on customer sites and stores its site id, token and locale list as three
	 * separate options. A version that only read the new array would leave every one of
	 * those sites fetching nothing — with a settings screen that looks perfectly configured,
	 * because the framework's defaults render fine. That is a site that silently stops being
	 * translated, which is exactly the direction §2.44 says is expensive.
	 */
	private const LEGACY = array(
		'site_id' => 'zinn_translate_site_id',
		'token'   => 'zinn_translate_token',
		'locales' => 'zinn_translate_locales',
	);

	/**
	 * Cached merged settings for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Every setting and its default.
	 *
	 * ⛔ `locales` defaults to EMPTY, meaning "publish none", never "publish all". Turning
	 * 57 locale-prefixed URL trees on somebody's domain because an option was unset is an
	 * SEO event they did not ask for and cannot easily undo.
	 *
	 * @return array<string, mixed> Defaults keyed by setting name.
	 */
	public static function defaults(): array {
		return array(
			// ── Where translations come from ──────────────────────────────────────
			// ⚖️ Owner, 2026-09-08: *"we are not paying to translate peoples sites, they
			// need to either use our ai credits for it or byo key for their providers"*.
			// So there are exactly two funding modes and no third, free one.
			'mode'                  => 'zinn',
			'site_id'               => '',
			'token'                 => '',
			// ⛔ Computed from the measured price table (§2.45), never typed here. A default
			// written as a literal beside the prices is correct on the day it is typed and
			// nothing notices when a cheaper option appears.
			'provider'              => Zinn_Translate_Provider_Factory::cheapest(),
			'provider_key'          => '',
			'provider_model'        => '',
			// ── What is published ─────────────────────────────────────────────────
			'source_locale'         => '',
			'locales'               => array(),
			'translate_slugs'       => true,
			'translate_meta'        => true,
			'translate_terms'       => true,
			'translate_menus'       => true,
			'translate_woo'         => true,
			'post_types'            => self::default_post_types(),
			// ── When it happens ───────────────────────────────────────────────────
			'auto_translate'        => true,
			'batch_size'            => 20,
			// ── SEO surfaces ──────────────────────────────────────────────────────
			'hreflang'              => true,
			'sitemaps'              => true,
			'llms_txt'              => true,
			// ── The switcher ──────────────────────────────────────────────────────
			'switcher_preset'       => 'dropdown',
			'switcher_locales'      => array(),
			'switcher_native_names' => true,
			'switcher_show_flags'   => false,
			'switcher_show_current' => true,
			'switcher_show_source'  => true,
		);
	}

	/**
	 * The post types a site translates unless its owner says otherwise.
	 *
	 * ⛔⛔ **DEFINED HERE AND NOWHERE ELSE, AND THAT IS THE POINT RATHER THAN TIDINESS.**
	 * This list used to be written out in four files — `defaults()`, the settings field, the
	 * collector's fallback and the sitemap's — and the one in the SETTINGS FIELD silently won
	 * on every site: `Zinn_Translate_Admin_UI::all()` fills any unsaved key from the field's
	 * own declared default, and `all()` merges `defaults()` *underneath* that. So changing
	 * the default here alone changed nothing a customer could see (§2.45).
	 *
	 * ⛔ `product` is in the list because `translate_woo` defaults on. Without it a stock
	 * install collected a product's short description, purchase note, attributes and
	 * variation descriptions and left its NAME, description, excerpt, slug and SEO fields in
	 * the source language — the half-translated shop this plugin's WooCommerce class exists
	 * to prevent. The post type is registered by WooCommerce, so on a site without it the
	 * name is simply an entry `WP_Query` matches nothing for, which costs nothing.
	 *
	 * @return string[] Post type names.
	 */
	public static function default_post_types(): array {
		return array( 'post', 'page', 'product' );
	}

	/**
	 * The post types this site translates.
	 *
	 * @return string[] Post type names, defaulted and coerced.
	 */
	public static function post_types(): array {
		$types = self::get( 'post_types', self::default_post_types() );
		if ( ! is_array( $types ) || array() === $types ) {
			return array();
		}
		return array_values( array_map( 'strval', $types ) );
	}

	/**
	 * One setting, merged over its default.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default_value Returned when the setting is unknown. Prefer declaring it in defaults().
	 * @return mixed The stored value, or the default.
	 */
	public static function get( string $key, $default_value = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * A setting as a boolean.
	 *
	 * @param string $key Setting name.
	 * @return bool The value, coerced.
	 */
	public static function flag( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * A setting as a trimmed string.
	 *
	 * @param string $key Setting name.
	 * @return string The value, coerced.
	 */
	public static function text( string $key ): string {
		$value = self::get( $key, '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Every setting, defaults merged, legacy options folded in.
	 *
	 * @return array<string, mixed> The settings.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = array();
		if ( class_exists( 'Zinn_Translate_Admin_UI' ) && method_exists( 'Zinn_Translate_Admin_UI', 'all' ) ) {
			$framework = call_user_func( array( 'Zinn_Translate_Admin_UI', 'all' ) );
			if ( is_array( $framework ) ) {
				$stored = $framework;
			}
		} else {
			$raw = get_option( self::OPTION, array() );
			if ( is_array( $raw ) ) {
				$stored = $raw;
			}
		}

		// ⛔ Legacy values fill only what the array does not already hold, so a value saved
		// through the new screen always wins over the 1.1.x option it replaced.
		foreach ( self::LEGACY as $key => $option ) {
			if ( isset( $stored[ $key ] ) && '' !== $stored[ $key ] && array() !== $stored[ $key ] ) {
				continue;
			}
			$legacy = get_option( $option, null );
			if ( null !== $legacy && '' !== $legacy && array() !== $legacy ) {
				$stored[ $key ] = $legacy;
			}
		}

		self::$cache = array_merge( self::defaults(), $stored );
		return self::$cache;
	}

	/**
	 * Forget the cached settings — for tests, and after a save.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * The locales this site publishes, validated.
	 *
	 * ⛔ Validated against the generated language table rather than a pattern. A pattern
	 * accepts `zz`, and this list becomes rewrite rules and `hreflang` attributes — a URL
	 * tree for a language we cannot translate is a set of thin duplicate pages announced to
	 * crawlers as canonical alternates.
	 *
	 * @return string[] Language codes, in registry order, never including the source.
	 */
	public static function locales(): array {
		$raw = self::get( 'locales', array() );
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$source = self::source_locale();
		$clean  = array();
		foreach ( $raw as $code ) {
			$code = strtolower( trim( (string) $code ) );
			if ( '' !== $code && $code !== $source && Zinn_Translate_Locales::known( $code ) ) {
				$clean[] = $code;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * The language the site is written in.
	 *
	 * ⛔ Derived from WordPress itself when unset, never assumed to be English. A German
	 * site whose source we recorded as English would be "translated" from German into
	 * German by way of an English prompt, and the customer would be billed for it.
	 *
	 * @return string A language code from our table, falling back to `en`.
	 */
	public static function source_locale(): string {
		$configured = self::text( 'source_locale' );
		if ( '' !== $configured && Zinn_Translate_Locales::known( $configured ) ) {
			return $configured;
		}
		$wp = strtolower( (string) get_locale() );
		$wp = str_replace( '_', '-', $wp );
		if ( Zinn_Translate_Locales::known( $wp ) ) {
			return $wp;
		}
		$base = explode( '-', $wp )[0];
		return Zinn_Translate_Locales::known( $base ) ? $base : 'en';
	}

	/**
	 * Whether this site is connected to a Zinn Digital® account.
	 *
	 * @return bool True when both the site id and the token are present.
	 */
	public static function is_connected(): bool {
		return '' !== self::text( 'site_id' ) && '' !== self::text( 'token' );
	}

	/**
	 * Whether the plugin has somewhere to send text for translation.
	 *
	 * ⛔ Two funding modes, and neither of them is us paying. `zinn` spends the customer's
	 * own Zinn AI credits; `byo` spends against the key they pasted in. A site configured
	 * for neither translates nothing and says so on its settings screen — it does not
	 * quietly fall back to anything, because there is nothing to fall back to.
	 *
	 * @return bool True when translation can actually be bought.
	 */
	public static function can_translate(): bool {
		if ( 'byo' === self::text( 'mode' ) ) {
			return '' !== self::text( 'provider_key' );
		}
		return self::is_connected();
	}
}

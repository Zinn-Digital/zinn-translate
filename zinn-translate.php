<?php
/**
 * Plugin Name:       Zinn® Translate
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-translate
 * Description:       Publishes this site in every language you choose, on its own web addresses, with translated slugs, metadata, menus, WooCommerce products, hreflang, per-language sitemaps and llms.txt. Translate with your Zinn Digital® plan or with your own provider key.
 * Version:           2.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-translate
 * Domain Path:       /languages
 * Update URI:        https://zinndigital.com
 *
 * @package ZinnTranslate
 *
 * ⭐⭐ THIS PLUGIN IS THE CONSUMER, and that is the whole reason it exists. The platform
 * has held over a million finished translations that reached no visitor for the life of the
 * system — produced, stored, marked `up_to_date`, and read by nothing, while a dashboard
 * reported 99% complete. A translation that no page renders is not a feature; it is a row.
 * So the product is not finished by translating a customer's site. It is finished here.
 *
 * ── HOW IT WORKS, in five lines, because "where do the translations happen?" is the first
 *    question anybody asks and the answer was never written down (docs/553 §1) ───────────
 *
 * 1. A COLLECTOR walks this site and lists every translatable string — posts, pages, terms,
 *    menus, image alt text, SEO metadata, WooCommerce products and attributes, slugs.
 * 2. A PROVIDER turns those strings into another language. Either Zinn Digital® (billed to
 *    the site's own Site Translation plan) or a key the site owner pastes in (billed to
 *    them by Google, DeepL or OpenAI). ⚖️ Owner ruling 2026-09-08: we never absorb the cost.
 * 3. A STORE keeps the results in one table on this site, with the hash of the source they
 *    were made from and whether a human has edited them.
 * 4. A ROUTER serves `/fr/a-propos/` from the same post as `/about/` — no duplicated posts.
 * 5. A RENDERER swaps the words at render time. Nothing about the site's own content moves.
 *
 * ⛔ WHAT THIS PLUGIN STILL DOES NOT DO, deliberately:
 *
 * - It does not duplicate content. No shadow posts, no shadow taxonomy, no second copy of
 *   anything the customer wrote. Deactivating it returns the site to exactly what it was,
 *   and a customer who stops paying loses translated URLs, never their own words.
 * - It exposes no REST route. A plugin that opens one is a new attack surface on every site
 *   it is installed on, for a capability this does not need.
 * - It never sends a customer's content anywhere they did not configure. With no provider
 *   set up it collects nothing, sends nothing, and says so on its own screen.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZINN_TRANSLATE_VERSION', '2.1.1' );
define( 'ZINN_TRANSLATE_FILE', __FILE__ );

/**
 * Where a connected site talks to Zinn Digital®.
 *
 * ⛔ Filterable so a staging site can point at `api.dev.zinndigital.com`, but it defaults to
 * production and is never read from the database — a compromised option must not be able to
 * redirect a site's content to somebody else's server.
 *
 * @return string Absolute URL with no trailing slash.
 */
function zinn_translate_api_base(): string {
	/**
	 * Filters the Zinn Digital® API base URL.
	 *
	 * @param string $base Absolute URL with no trailing slash.
	 */
	return (string) apply_filters( 'zinn_translate_api_base', 'https://api.zinndigital.com' );
}

/**
 * Make one string safe for a `text/plain` response body.
 *
 * ⛔ `esc_html()` is the wrong tool for a file that is not HTML: it turns `&` into `&amp;`
 * in a document whose readers are answer engines reading plain text. What "safe" means here
 * is no markup and no control characters, which is what this does.
 *
 * @param string $text The text to emit.
 * @return string The text, safe for a plain-text body.
 */
function zinn_translate_plain( string $text ): string {
	$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
}

require_once __DIR__ . '/includes/class-zinn-translate-locales.php';
require_once __DIR__ . '/includes/class-zinn-translate-options.php';
require_once __DIR__ . '/includes/class-zinn-translate-store.php';
require_once __DIR__ . '/includes/class-zinn-translate-prompt.php';
require_once __DIR__ . '/includes/providers/interface-zinn-translate-provider.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-error.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-zinn.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-gemini.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-deepl.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-openai.php';
require_once __DIR__ . '/includes/providers/class-zinn-translate-provider-factory.php';
require_once __DIR__ . '/includes/class-zinn-translate-status.php';
require_once __DIR__ . '/includes/class-zinn-translate-seo.php';
require_once __DIR__ . '/includes/class-zinn-translate-woocommerce.php';
require_once __DIR__ . '/includes/class-zinn-translate-collector.php';
require_once __DIR__ . '/includes/class-zinn-translate-locale-switch.php';
require_once __DIR__ . '/includes/class-zinn-translate-router.php';
require_once __DIR__ . '/includes/class-zinn-translate-renderer.php';
require_once __DIR__ . '/includes/class-zinn-translate-switcher.php';
require_once __DIR__ . '/includes/class-zinn-translate-switcher-widget.php';
require_once __DIR__ . '/includes/class-zinn-translate-sitemap.php';
require_once __DIR__ . '/includes/class-zinn-translate-queue.php';
require_once __DIR__ . '/includes/class-zinn-translate-admin-ui.php'; // Generated by wp/bin/build-admin-ui.php.
require_once __DIR__ . '/includes/class-zinn-translate-admin.php';
require_once __DIR__ . '/includes/class-zinn-translate-cli.php';
require_once __DIR__ . '/includes/class-zinn-translate-updater.php'; // Generated by wp/bin/build-updater.php.

/**
 * Boot the plugin.
 *
 * ⛔ The router hooks `init` at priority 1 because it registers rewrite rules, which must be
 * in place before WordPress parses the request. The renderer hooks much later — it only ever
 * filters output.
 *
 * ⛔ `maybe_install()` runs here rather than only on activation. A plugin updated by an
 * automatic background update, by WP-CLI or by copying files over never re-runs its
 * activation hook, so a schema change that only ran there would leave a working site one
 * version behind with no error until a query hit a column that is not there.
 *
 * @return void
 */
function zinn_translate_boot(): void {
	Zinn_Translate_Store::maybe_install();
	( new Zinn_Translate_Admin() )->hooks();
	( new Zinn_Translate_Locale_Switch() )->hooks();
	( new Zinn_Translate_Router() )->hooks();
	( new Zinn_Translate_Renderer() )->hooks();
	( new Zinn_Translate_SEO() )->hooks();
	( new Zinn_Translate_Switcher() )->hooks();
	( new Zinn_Translate_Sitemap() )->hooks();
	( new Zinn_Translate_WooCommerce() )->hooks();
	( new Zinn_Translate_Queue() )->hooks();
	( new Zinn_Translate_Updater( ZINN_TRANSLATE_FILE, ZINN_TRANSLATE_VERSION ) )->register();
	Zinn_Translate_CLI::register();
}
add_action( 'plugins_loaded', 'zinn_translate_boot' );

/**
 * Create the table and the rewrite rules on activation.
 *
 * @return void
 */
function zinn_translate_activate(): void {
	Zinn_Translate_Store::install();
	( new Zinn_Translate_Router() )->register_rules();
	( new Zinn_Translate_Sitemap() )->register_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'zinn_translate_activate' );

/**
 * Drop the locale rewrite rules and the schedule when the plugin is switched off.
 *
 * ⛔ Deliberately symmetric with activation. Leaving the rules behind leaves `/fr/about/`
 * resolving to a handler that no longer exists, so a customer who deactivates gets broken
 * URLs instead of their old site back — and by then those URLs are indexed.
 *
 * ⛔ The TABLE is NOT dropped here. Deactivating a plugin to test something must not destroy
 * every translation the site has paid for; that belongs in `uninstall.php`, which is the
 * action a customer takes when they mean it.
 *
 * @return void
 */
function zinn_translate_deactivate(): void {
	Zinn_Translate_Queue::unschedule();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'zinn_translate_deactivate' );

// ── The Zinn® panel ──────────────────────────────────────────────────────────────────────
//
// ⚖️ Owner, 2026-09-01: *"each plugin should promote our hosting and marketplace as well as
// Zinn Hub global marketplace inside people's site in the admin dashboard"*, and *"user
// guides for them … linked to in the plugins dashboard"*.
//
// ⛔ `require_once` rather than the autoloader, and a STRING callable rather than
// `array( Zinn_Translate_Promo::class, … )`. The class is deliberately global — it is shipped
// identically into seven plugins with different namespacing conventions, and three of them
// bootstrap inside a namespace where `Zinn_Translate_Promo::class` would resolve to a class that does
// not exist. A string callable is resolved in the global namespace at call time, which is
// correct from every one of the seven. `php -l` cannot see that mistake; only running it can.
require_once __DIR__ . '/includes/class-zinn-translate-promo.php';
add_action( 'plugins_loaded', array( 'Zinn_Translate_Promo', 'register' ) );

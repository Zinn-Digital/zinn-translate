<?php
/**
 * Plugin Name:       Zinn® Translate
 * Plugin URI:        https://zinndigital.com
 * Description:       Serves this site in every language Zinn Digital® has translated it into, on its own web addresses, with correct hreflang tags. Translation happens on Zinn Digital®; this plugin renders it.
 * Version:           1.0.0
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
 * ⛔ WHAT THIS PLUGIN DOES NOT DO, deliberately:
 *
 * - It does not translate. Not one word is generated on the customer's server; it fetches
 *   finished text from Zinn Digital® and renders it. A site that cannot reach us shows its
 *   own original content, unchanged, which is the correct failure.
 * - It does not WRITE to the database. No duplicated posts, no shadow post type, no taxonomy.
 *   Translations are applied at render time through core filters, so deactivating the plugin
 *   returns the site to exactly what it was with nothing to clean up — and a customer who
 *   stops paying loses the translated URLs, never their own content.
 * - It exposes no REST route and no shortcode. A plugin that opens a route is a new attack
 *   surface on every site it is installed on, for a capability we do not need.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZINN_TRANSLATE_VERSION', '1.0.0' );
define( 'ZINN_TRANSLATE_FILE', __FILE__ );

/**
 * Where the bundle is fetched from.
 *
 * ⛔ Filterable so a staging site can point at `api.dev.zinndigital.com`, but it defaults to
 * production and is never read from the database — a compromised option must not be able to
 * redirect a site's rendered content to somebody else's server.
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

require_once __DIR__ . '/includes/class-zinn-translate-settings.php';
require_once __DIR__ . '/includes/class-zinn-translate-client.php';
require_once __DIR__ . '/includes/class-zinn-translate-router.php';
require_once __DIR__ . '/includes/class-zinn-translate-renderer.php';

/**
 * Boot the plugin.
 *
 * ⛔ The router hooks `init` at priority 1 because it registers rewrite rules, which must be
 * in place before WordPress parses the request. The renderer hooks much later — it only ever
 * filters output.
 */
function zinn_translate_boot(): void {
	( new Zinn_Translate_Settings() )->hooks();
	( new Zinn_Translate_Router() )->hooks();
	( new Zinn_Translate_Renderer() )->hooks();
}
add_action( 'plugins_loaded', 'zinn_translate_boot' );

/**
 * Flush rewrite rules on activation and deactivation.
 *
 * ⛔ Both, and this is not symmetry for its own sake. Leaving the rules behind on
 * deactivation leaves `/fr/about/` resolving to a 404 handler that no longer exists, so a
 * customer who deactivates the plugin gets broken URLs instead of their old site back — and
 * those URLs are indexed by then.
 */
function zinn_translate_activate(): void {
	( new Zinn_Translate_Router() )->register_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'zinn_translate_activate' );

/**
 * Drop the locale rewrite rules when the plugin is switched off.
 *
 * ⛔ Deliberately symmetric with activation. Leaving the rules behind leaves `/fr/about/`
 * resolving to a handler that no longer exists, so a customer who deactivates gets broken
 * URLs instead of their old site back — and by then those URLs are indexed.
 *
 * @return void
 */
function zinn_translate_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'zinn_translate_deactivate' );

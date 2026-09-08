<?php
/**
 * Remove everything this plugin stored, on uninstall.
 *
 * ⛔⛔ **UNINSTALL, NEVER DEACTIVATION.** Deactivating a plugin is something people do to
 * test a theme conflict for thirty seconds; uninstalling is a decision. Dropping the table
 * on deactivation would destroy every translation the customer has paid for, including
 * every correction they typed by hand, and they would find out by reactivating.
 *
 * ⛔ Ours only. The plugin never writes a post, a term or a meta row — the customer's own
 * content is untouched by design — so there is nothing of theirs to remove here, and
 * removing anything of theirs would be the plugin deleting their site.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-zinn-translate-locales.php';
require_once __DIR__ . '/includes/class-zinn-translate-store.php';

Zinn_Translate_Store::drop();

delete_option( 'zinn_translate_settings' );
delete_option( 'zinn_translate_last_error' );
// The 1.1.x options, still present on sites upgraded from that version.
delete_option( 'zinn_translate_site_id' );
delete_option( 'zinn_translate_token' );
delete_option( 'zinn_translate_locales' );

wp_clear_scheduled_hook( 'zinn_translate_work' );
wp_clear_scheduled_hook( 'zinn_translate_sweep' );

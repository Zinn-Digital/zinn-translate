<?php
/**
 * Remove this plugin's options on uninstall.
 *
 * ⛔ Options only. The plugin never wrote a post, a term or a meta row — translations are
 * applied at render time — so there is nothing else of ours on the site, and deleting
 * anything belonging to the customer here would be the plugin removing their content.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'zinn_translate_site_id' );
delete_option( 'zinn_translate_token' );
delete_option( 'zinn_translate_locales' );

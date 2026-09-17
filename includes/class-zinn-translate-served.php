<?php
/**
 * Zinn Digital® — the languages the customer chose on their dashboard, kept on this site.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Follows the dashboard's "Shown to visitors" choice for a site on a Zinn Digital® plan.
 *
 * ⛔⛔ **WHY THIS EXISTS (D26430, D26950).** The dashboard's Translation tab saved which
 * languages a site shows to visitors and answered *"Saved. Your site is serving those
 * languages now."* Nothing read that choice: this plugin published only its own local
 * option, so the sentence was false on every site and could not be caught from the
 * dashboard, whose only reader of the value was the screen that wrote it (§2.38).
 *
 * ⭐ **The answer is STORED, and a page load never asks the engine.** `locales()` is read by
 * the router at `init` priority 1 on every request; a remote call there would put our API's
 * latency — or an outage — in front of every visitor to every connected site (§2.16). The
 * engine is asked from the hourly sweep, straight after the connection is saved, and when an
 * administrator opens the settings screen with a stale copy.
 *
 * ⛔⛔ **"NEVER ASKED" AND "ASKED, AND THE ANSWER WAS NONE" ARE DIFFERENT STATES.** Until the
 * first successful answer for THIS site id is stored, `locales()` returns null and the caller
 * keeps publishing the languages chosen on this site's own screen — a site that has just been
 * connected, or whose token cannot read the list, must not lose every translated URL because
 * a request failed. Once an answer is stored, an empty list means publish none, exactly as the
 * dashboard says (§2.44). A failed refresh keeps the last good answer; it never blanks it.
 */
final class Zinn_Translate_Served {

	/**
	 * Where the last good answer is kept. Separate from the settings array on purpose: the
	 * settings framework rewrites that option wholesale on every save, and a value the
	 * customer did not type there must not be erased by a save of the screen.
	 */
	public const OPTION = 'zinn_translate_served';

	/**
	 * The one-off event that refreshes the list soon after the connection changes.
	 */
	public const HOOK = 'zinn_translate_served_sync';

	/**
	 * How old a stored answer may be before opening the settings screen asks again.
	 */
	public const STALE_AFTER = 300;

	/**
	 * Wire the refresh triggers.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( self::HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'add_option_' . Zinn_Translate_Options::OPTION, array( __CLASS__, 'connection_added' ), 20, 2 );
		add_action( 'update_option_' . Zinn_Translate_Options::OPTION, array( __CLASS__, 'connection_changed' ), 20, 2 );
		add_action( 'current_screen', array( __CLASS__, 'refresh_if_stale' ) );
	}

	/**
	 * Whether this site follows the dashboard: on a Zinn Digital® plan, and connected.
	 *
	 * @return bool True when the dashboard decides what is published.
	 */
	public static function applies(): bool {
		return 'byo' !== Zinn_Translate_Options::text( 'mode' ) && Zinn_Translate_Options::is_connected();
	}

	/**
	 * The stored answer for the connected site, or null when there is none to follow.
	 *
	 * ⛔ An answer stored for a DIFFERENT site id is ignored: a customer who pastes another
	 * site's id must not publish the first site's languages until the next refresh.
	 *
	 * @return string[]|null Language codes, or null when the site has not been answered yet.
	 */
	public static function locales(): ?array {
		if ( ! self::applies() ) {
			return null;
		}
		$stored = self::stored();
		if ( null === $stored || Zinn_Translate_Options::text( 'site_id' ) !== $stored['site_id'] ) {
			return null;
		}
		return $stored['locales'];
	}

	/**
	 * The stored record, validated, or null.
	 *
	 * @return array{site_id: string, locales: string[], state: string, synced_at: int}|null The record.
	 */
	public static function stored(): ?array {
		$raw = get_option( self::OPTION, null );
		if ( ! is_array( $raw ) || ! isset( $raw['site_id'], $raw['locales'], $raw['synced_at'] ) || ! is_array( $raw['locales'] ) ) {
			return null;
		}
		return array(
			'site_id'   => (string) $raw['site_id'],
			'locales'   => array_values( array_map( 'strval', $raw['locales'] ) ),
			'state'     => isset( $raw['state'] ) ? (string) $raw['state'] : '',
			'synced_at' => (int) $raw['synced_at'],
		);
	}

	/**
	 * Ask the engine and keep the answer.
	 *
	 * ⛔ A change to the published set drops the cached rewrite rules, for the reason the
	 * router's `settings_updated()` gives: a language switched on must not answer 404 until
	 * something unrelated happens to flush them (D26433).
	 *
	 * @return bool True when an answer was stored, false when there was nothing to ask or the
	 *              request failed (the failure is recorded for the connection card).
	 */
	public static function sync(): bool {
		if ( ! self::applies() ) {
			return false;
		}
		try {
			$answer = Zinn_Translate_Provider_Factory::zinn()->served();
		} catch ( Zinn_Translate_Provider_Error $error ) {
			Zinn_Translate_Status::record( $error );
			return false;
		}
		$before = Zinn_Translate_Options::locales();
		update_option(
			self::OPTION,
			array(
				'site_id'   => Zinn_Translate_Options::text( 'site_id' ),
				'locales'   => $answer['locales'],
				'state'     => $answer['state'],
				'synced_at' => time(),
			),
			false
		);
		Zinn_Translate_Options::flush();
		if ( Zinn_Translate_Options::locales() !== $before ) {
			delete_option( 'rewrite_rules' );
		}
		return true;
	}

	/**
	 * Settings saved for the first time: ask soon.
	 *
	 * @param string $option The option name.
	 * @param mixed  $value  The new value.
	 * @return void
	 */
	public static function connection_added( $option, $value ): void {
		unset( $option );
		self::connection_changed( array(), $value );
	}

	/**
	 * The connection changed: ask soon, off the save request.
	 *
	 * ⛔ Scheduled rather than called inline: the save request still holds the OLD settings in
	 * the options cache the provider reads, and an administrator should not wait on our API to
	 * see their settings saved.
	 *
	 * @param mixed $old_value The previous settings.
	 * @param mixed $value     The new settings.
	 * @return void
	 */
	public static function connection_changed( $old_value, $value ): void {
		$before = is_array( $old_value ) ? $old_value : array();
		$after  = is_array( $value ) ? $value : array();
		foreach ( array( 'mode', 'site_id', 'token' ) as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				delete_option( 'rewrite_rules' );
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time() + 5, self::HOOK );
				}
				return;
			}
		}
	}

	/**
	 * Opening this plugin's settings screen with a stale answer asks again, off the page render.
	 *
	 * ⛔ Matched on the screen id WordPress computed from our own page slug, never on
	 * `$_GET['page']`, so no other admin page ever schedules a request to our API.
	 *
	 * @param WP_Screen|mixed $screen The screen being loaded.
	 * @return void
	 */
	public static function refresh_if_stale( $screen = null ): void {
		$id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		if ( '' === $id || false === strpos( $id, Zinn_Translate_Admin_UI::page_slug() ) || ! self::applies() ) {
			return;
		}
		$stored = self::stored();
		if ( null !== $stored && ( time() - $stored['synced_at'] ) < self::STALE_AFTER ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}

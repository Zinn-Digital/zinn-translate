<?php
/**
 * What went wrong last, and what the site owner can do about it.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the last provider failure, classified, for the connection card.
 *
 * ⭐⭐ `CLAUDE.md` §2.57 applied on the customer's own server. The rule says an AI provider's
 * failure is the customer's to understand: name the KIND of failure, link the vendor's own
 * page when they can act on it, and never dress our own bug up as somebody else's outage.
 * Here the site owner is not merely entitled to know — with a bring-your-own key they are
 * the ONLY person who can fix it, and "translation failed" tells them nothing at all.
 *
 * ⛔ The boundary in §2.57 still holds: this names an AI PROVIDER the site owner chose. It
 * says nothing about hosting, DNS or the infrastructure underneath, and it never should.
 */
final class Zinn_Translate_Status {

	private const OPTION = 'zinn_translate_last_error';

	/**
	 * Record a failure.
	 *
	 * @param Zinn_Translate_Provider_Error $error What happened.
	 * @return void
	 */
	public static function record( Zinn_Translate_Provider_Error $error ): void {
		update_option(
			self::OPTION,
			array(
				'kind'     => $error->kind(),
				'message'  => $error->getMessage(),
				'help_url' => $error->help_url(),
				'at'       => time(),
			),
			false
		);
	}

	/**
	 * Forget the last failure, after a call succeeds.
	 *
	 * ⛔ Called on SUCCESS rather than expired on a timer. An error that ages out on its own
	 * disappears from the screen while the fault is still there, and the site owner is left
	 * with a green card and no translations.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The last failure, or null.
	 *
	 * @return array{kind: string, message: string, help_url: string, at: int}|null The failure.
	 */
	public static function last(): ?array {
		$raw = get_option( self::OPTION, null );
		if ( ! is_array( $raw ) || ! isset( $raw['kind'], $raw['message'] ) ) {
			return null;
		}
		return array(
			'kind'     => (string) $raw['kind'],
			'message'  => (string) $raw['message'],
			'help_url' => (string) ( $raw['help_url'] ?? '' ),
			'at'       => (int) ( $raw['at'] ?? 0 ),
		);
	}

	/**
	 * The connection card the shared settings framework renders.
	 *
	 * ⛔⛔ `standalone` is a STATE, not an error, and it renders neutral. A site translating
	 * on its owner's own Gemini key is working exactly as intended, and painting that amber
	 * would teach them to ignore the one card that will one day be telling them something
	 * real.
	 *
	 * @return array<string, mixed> The status, in the shape W41-Q's framework expects.
	 */
	public static function status(): array {
		$failure   = self::last();
		$connected = Zinn_Translate_Options::is_connected();
		$mode      = Zinn_Translate_Options::text( 'mode' );
		$details   = array(
			array(
				'label' => __( 'Languages published', 'zinn-translate' ),
				'value' => (string) count( Zinn_Translate_Options::locales() ),
			),
			array(
				'label' => __( 'Site language', 'zinn-translate' ),
				'value' => Zinn_Translate_Locales::name( Zinn_Translate_Options::source_locale() ),
			),
		);
		if ( '' !== Zinn_Translate_SEO::detected_label() ) {
			$details[] = array(
				'label' => __( 'SEO plugin', 'zinn-translate' ),
				'value' => Zinn_Translate_SEO::detected_label(),
			);
		}

		if ( null !== $failure ) {
			$state = Zinn_Translate_Provider_Error::OUTAGE === $failure['kind'] ? 'degraded' : 'disconnected';
			$card  = array(
				'state'      => $state,
				'summary'    => self::summary_for( $failure['kind'] ),
				'reason'     => $failure['message'],
				'details'    => $details,
				'checked_at' => $failure['at'],
			);
			if ( '' !== $failure['help_url'] ) {
				$card['action'] = array(
					'label' => self::action_for( $failure['kind'] ),
					'url'   => $failure['help_url'],
				);
			}
			return $card;
		}

		if ( 'byo' === $mode ) {
			$provider = Zinn_Translate_Provider_Factory::current();
			if ( ! $provider->ready() ) {
				return array(
					'state'      => 'disconnected',
					'summary'    => __( 'No translation provider is set up yet.', 'zinn-translate' ),
					'reason'     => __( 'Paste an API key from your chosen provider, or connect the site to a Zinn Digital® account, and translation starts on the next pass.', 'zinn-translate' ),
					'details'    => $details,
					'checked_at' => time(),
				);
			}
			return array(
				'state'      => 'standalone',
				'summary'    => sprintf(
					/* translators: %s: a translation provider's name, for example "DeepL". */
					__( 'Translating with your own %s key.', 'zinn-translate' ),
					$provider->label()
				),
				'reason'     => __( 'This site is not connected to a Zinn Digital® account, which is fine — your provider bills you directly.', 'zinn-translate' ),
				'details'    => $details,
				'checked_at' => time(),
			);
		}

		if ( ! $connected ) {
			return array(
				'state'      => 'disconnected',
				'summary'    => __( 'Not connected to Zinn Digital® yet.', 'zinn-translate' ),
				'reason'     => __( 'Add this site\'s ID and token from your Zinn Digital® dashboard, or switch to your own provider key to run without an account.', 'zinn-translate' ),
				'details'    => $details,
				'checked_at' => time(),
			);
		}
		return array(
			'state'      => 'connected',
			'summary'    => __( 'Connected to Zinn Digital®.', 'zinn-translate' ),
			'details'    => $details,
			'checked_at' => time(),
		);
	}

	/**
	 * A one-line summary for a failure kind.
	 *
	 * @param string $kind One of the provider error constants.
	 * @return string Copy for the card's headline.
	 */
	private static function summary_for( string $kind ): string {
		switch ( $kind ) {
			case Zinn_Translate_Provider_Error::OUTAGE:
				return __( 'The translation provider is having a problem.', 'zinn-translate' );
			case Zinn_Translate_Provider_Error::CREDENTIAL:
				return __( 'Translation is paused — your provider needs attention.', 'zinn-translate' );
			case Zinn_Translate_Provider_Error::REFUSED:
				return __( 'Translation has not been set up yet.', 'zinn-translate' );
			default:
				// ⛔ Ours, and it says so. §2.57: guessing "outage" on an unclassified
				// failure blames a third party for our own bug, which is the reverse of what
				// that rule is for.
				return __( 'Something went wrong at our end.', 'zinn-translate' );
		}
	}

	/**
	 * The label on the card's fix button.
	 *
	 * @param string $kind One of the provider error constants.
	 * @return string The button label.
	 */
	private static function action_for( string $kind ): string {
		return Zinn_Translate_Provider_Error::OUTAGE === $kind
			? __( 'Check their status page', 'zinn-translate' )
			: __( 'Fix this on your account', 'zinn-translate' );
	}
}

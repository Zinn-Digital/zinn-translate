<?php
/**
 * Zinn Digital® — translation bought against the customer's own Site Translation plan.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ⛔⛔ `ExceptionNotEscaped` IS DISABLED FOR THIS FILE, DELIBERATELY, AND THE ALTERNATIVE IS
// A REAL BUG. The sniff exists because an exception message often ends up in `wp_die()`,
// where unescaped text is an XSS. Nothing here does that: every message from this file is
// caught by `Zinn_Translate_Queue`, stored by `Zinn_Translate_Status::record()`, and rendered
// by the connection card through `esc_html()`. Escaping it at the throw site as well would
// double-encode it — `you're` would reach the customer as `you&#039;re` — which is the exact
// defect the renderer's own note about `esc_html` on `the_title` describes.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Submits the site's inventory to Zinn Digital® and collects the finished translations.
 *
 * ⛔⛔ **THIS PROVIDER IS ASYNCHRONOUS AND THE OTHERS ARE NOT, AND THAT IS DELIBERATE.**
 * Gemini, DeepL and OpenAI are called with the site owner's own key and answer in one
 * request. Our engine does not, and must not: a site with 40,000 words would hold an HTTP
 * connection open for minutes, our latency would become the customer's cron timeout, and a
 * retry would pay for the same words twice. Instead the site PUSHES its inventory, the
 * engine's durable pipeline translates it against the customer's plan, and the site PULLS
 * finished text whenever it likes. `CLAUDE.md` §2.9: long work is not done in a request.
 *
 * ⭐ So `translate()` here returns whatever is READY, and returns nothing on the first pass
 * of a new site. That is not a failure and must not be reported as one — the queue simply
 * asks again next time, and every string it does not receive stays `missing` until it does.
 *
 * ⚖️ Owner ruling 2026-09-08: the words are billed to the customer's own plan. The engine
 * asks entitlement, good standing (`CLAUDE.md` §2.46) and the tier allowance before it
 * registers anything, and answers 402 when it refuses.
 */
final class Zinn_Translate_Provider_Zinn implements Zinn_Translate_Provider {

	/**
	 * The site's Zinn Digital® id.
	 *
	 * @var string
	 */
	private string $site_id;

	/**
	 * The site's token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Build the provider.
	 *
	 * @param string $site_id The Zinn Digital® site id.
	 * @param string $token   The site's token.
	 */
	public function __construct( string $site_id, string $token ) {
		$this->site_id = trim( $site_id );
		$this->token   = trim( $token );
	}

	/**
	 * The provider's name.
	 *
	 * @return string Its name.
	 */
	public function label(): string {
		return 'Zinn Digital®';
	}

	/**
	 * Whether this site is connected.
	 *
	 * @return bool True when both the id and the token are present.
	 */
	public function ready(): bool {
		return '' !== $this->site_id && '' !== $this->token;
	}

	/**
	 * Collect whatever this locale has finished.
	 *
	 * @param array<string, string> $strings       The ids being asked about.
	 * @param string                $source_locale Unused; the engine knows the site's source.
	 * @param string                $target_locale The language to collect.
	 * @param string                $kind          Unused; the engine records this per field.
	 * @return array<string, string> Translations keyed by the ids that are ready.
	 * @throws Zinn_Translate_Provider_Error When the engine could not be reached or refused.
	 */
	public function translate( array $strings, string $source_locale, string $target_locale, string $kind ): array {
		unset( $source_locale, $kind );
		if ( ! $this->ready() ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::REFUSED,
				__( 'This site is not connected to Zinn Digital®, so there is nothing to collect. Add the Site ID and token, or switch to your own provider key.', 'zinn-translate' )
			);
		}
		$bundle = $this->bundle( $target_locale );
		$out    = array();
		foreach ( $bundle as $ref => $fields ) {
			foreach ( $fields as $field => $text ) {
				$key = $ref . '#' . $field;
				if ( array_key_exists( $key, $strings ) && '' !== trim( $text ) ) {
					$out[ $key ] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Push the site's complete inventory to Zinn Digital®.
	 *
	 * ⛔⛔ **The submission is the COMPLETE list and the engine retires anything absent from
	 * it** (`CLAUDE.md` §2.52). So this must never be called with a partial inventory: doing
	 * so deletes the translations of every document left out, and the customer pays to make
	 * them again. The collector raises rather than returning a short list for exactly this
	 * reason, and this method does not catch that.
	 *
	 * @param array<int, array<string, mixed>> $documents The complete inventory.
	 * @return array<string, mixed> What the engine did — documents, words, registered, retired.
	 * @throws Zinn_Translate_Provider_Error When the engine could not be reached or refused.
	 */
	public function submit( array $documents ): array {
		if ( ! $this->ready() ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::REFUSED,
				__( 'This site is not connected to Zinn Digital®.', 'zinn-translate' )
			);
		}
		$response = wp_remote_post(
			$this->endpoint( 'translation/sources' ),
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
				'body'    => (string) wp_json_encode( array( 'documents' => array_values( $documents ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'Zinn Digital® could not be reached. Nothing was sent; it will try again on the next pass.', 'zinn-translate' ),
				'https://status.zinndigital.com/'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 402 === $code ) {
			// ⛔ 402 is the engine saying "not in good standing, or over the tier
			// allowance", and its body carries copy written for a customer. It is passed
			// through rather than replaced, because the engine knows which of the two it is
			// and this plugin does not.
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::CREDENTIAL,
				is_array( $body ) && isset( $body['refused'] ) && '' !== (string) $body['refused']
					? (string) $body['refused']
					: __( 'Translation is paused on this site. Check the account is up to date on your Zinn Digital® dashboard.', 'zinn-translate' ),
				'https://app.zinndigital.com/billing'
			);
		}
		if ( 401 === $code || 403 === $code ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::CREDENTIAL,
				__( 'Zinn Digital® rejected this site\'s token. Copy it again from the site\'s Translation tab on your dashboard.', 'zinn-translate' ),
				'https://app.zinndigital.com/'
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			Zinn_Translate_Prompt::assert_ok( $code, '', 'https://app.zinndigital.com/billing', 'https://status.zinndigital.com/' );
		}
		return is_array( $body ) ? $body : array();
	}

	/**
	 * One locale's finished translations.
	 *
	 * @param string $locale The language to fetch.
	 * @return array<string, array<string, string>> Fields keyed by object ref.
	 * @throws Zinn_Translate_Provider_Error When the engine could not be reached or refused.
	 */
	public function bundle( string $locale ): array {
		$response = wp_remote_get(
			$this->endpoint( 'translation/bundle/' . rawurlencode( $locale ) ),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'Zinn Digital® could not be reached. Your site keeps serving the translations it already has.', 'zinn-translate' ),
				'https://status.zinndigital.com/'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Zinn_Translate_Prompt::assert_ok( $code, '', 'https://app.zinndigital.com/billing', 'https://status.zinndigital.com/' );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['documents'] ) || ! is_array( $body['documents'] ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::UNKNOWN,
				__( 'Zinn Digital® answered with something we could not read. Your site keeps serving the translations it already has.', 'zinn-translate' )
			);
		}
		$documents = array();
		foreach ( $body['documents'] as $document ) {
			if ( ! is_array( $document ) || ! isset( $document['document'], $document['fields'] ) || ! is_array( $document['fields'] ) ) {
				continue;
			}
			$documents[ (string) $document['document'] ] = array_map( 'strval', $document['fields'] );
		}
		return $documents;
	}

	/**
	 * The absolute URL of one endpoint for this site.
	 *
	 * @param string $suffix The path after the site id.
	 * @return string The absolute URL.
	 */
	private function endpoint( string $suffix ): string {
		return sprintf(
			'%s/v1/sites/%s/%s',
			zinn_translate_api_base(),
			rawurlencode( $this->site_id ),
			$suffix
		);
	}
}

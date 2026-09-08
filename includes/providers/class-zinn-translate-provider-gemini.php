<?php
/**
 * Google Gemini, called with the site owner's own key.
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
 * Translates with Gemini's `generateContent` endpoint.
 *
 * ⛔⛔ **THE KEY IS THE SITE OWNER'S AND THE BILL IS THEIRS.** Nothing in this file touches
 * a Zinn Digital® credential. That is the owner's ruling of 2026-09-08 made concrete: a site
 * with no Zinn account translates on its own budget, and a site with one uses its Zinn plan
 * through the other provider. There is no path where we pay.
 *
 * ⛔ One request per batch, not per string. A page has a title, a body and an excerpt; a
 * shop page has forty product names. Per-string requests would be forty round trips for one
 * screen, forty times the per-request overhead on the owner's bill, and a rate limit hit in
 * the first minute.
 */
final class Zinn_Translate_Provider_Gemini implements Zinn_Translate_Provider {

	/**
	 * The default model.
	 *
	 * ⛔ The cheapest model that does this job properly, and it is a SETTING rather than a
	 * constant so a customer who wants a larger one can pay for it. `CLAUDE.md` §2.45: the
	 * default follows the cheapest measured option, never the most capable one, because the
	 * money is somebody's and the difference on a 90,000-word site is not small.
	 */
	private const DEFAULT_MODEL = 'gemini-2.5-flash';

	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * The site owner's API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * The model to call.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Build the provider from the plugin's settings.
	 *
	 * @param string $key   The API key.
	 * @param string $model A model name, or an empty string for the default.
	 */
	public function __construct( string $key, string $model = '' ) {
		$this->key   = $key;
		$this->model = '' === trim( $model ) ? self::DEFAULT_MODEL : trim( $model );
	}

	/**
	 * The provider's name.
	 *
	 * @return string Its name.
	 */
	public function label(): string {
		return 'Google Gemini';
	}

	/**
	 * Whether a call would have credentials.
	 *
	 * @return bool True when a key is configured.
	 */
	public function ready(): bool {
		return '' !== trim( $this->key );
	}

	/**
	 * Translate a batch.
	 *
	 * @param array<string, string> $strings       Text keyed by id.
	 * @param string                $source_locale The source language.
	 * @param string                $target_locale The target language.
	 * @param string                $kind          One of `Zinn_Translate_Prompt`'s KIND_ constants.
	 * @return array<string, string> Translations keyed by the same ids.
	 * @throws Zinn_Translate_Provider_Error When the provider could not be reached or refused.
	 */
	public function translate( array $strings, string $source_locale, string $target_locale, string $kind ): array {
		if ( ! $this->ready() ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::REFUSED,
				__( 'No Google Gemini API key is saved, so there is nothing to translate with.', 'zinn-translate' )
			);
		}
		if ( array() === $strings ) {
			return array();
		}

		$response = wp_remote_post(
			self::ENDPOINT . rawurlencode( $this->model ) . ':generateContent',
			array(
				// ⛔ A long timeout, because this runs in cron and not in a page render. The
				// renderer's own client uses three seconds for the opposite reason.
				'timeout' => 60,
				'headers' => array(
					'Content-Type'   => 'application/json',
					// ⛔ The key travels in a HEADER, never in the query string. A URL is
					// written to access logs, proxy logs and error reports on a server we do
					// not operate, and a key in a log is a key that has leaked.
					'x-goog-api-key' => $this->key,
				),
				'body'    => (string) wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array( array( 'text' => Zinn_Translate_Prompt::system( $kind ) ) ),
						),
						'contents'           => array(
							array(
								'role'  => 'user',
								'parts' => array(
									array( 'text' => Zinn_Translate_Prompt::user( $strings, $source_locale, $target_locale ) ),
								),
							),
						),
						'generationConfig'   => array(
							// ⛔ Zero temperature. A translation is not a creative task and a
							// site whose text changes every time it is re-translated cannot be
							// proofread by its owner.
							'temperature'      => 0,
							'responseMimeType' => 'application/json',
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'Google Gemini could not be reached. Nothing was translated and nothing was charged; it will try again on the next pass.', 'zinn-translate' ),
				'https://status.cloud.google.com/'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		Zinn_Translate_Prompt::assert_ok( $code, $body, 'https://aistudio.google.com/apikey', 'https://status.cloud.google.com/' );

		$decoded = json_decode( $body, true );
		$text    = '';
		if ( is_array( $decoded ) && isset( $decoded['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text = (string) $decoded['candidates'][0]['content']['parts'][0]['text'];
		}
		return Zinn_Translate_Prompt::decode( $text, $strings, $kind );
	}
}

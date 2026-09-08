<?php
/**
 * OpenAI, and anything that speaks its chat-completions API.
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
 * Translates with a chat-completions endpoint.
 *
 * ⭐ The base URL is a setting, so this one class also covers every self-hosted or
 * third-party service that implements the same shape — which is most of them. That is
 * `CLAUDE.md` §2.6's "≥1 swappable implementation" arriving as a genuine escape hatch rather
 * than as a second hard-coded vendor.
 */
final class Zinn_Translate_Provider_OpenAI implements Zinn_Translate_Provider {

	private const DEFAULT_MODEL = 'gpt-4.1-mini';

	private const DEFAULT_BASE = 'https://api.openai.com/v1';

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
	 * The API base URL.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Build the provider.
	 *
	 * @param string $key   The API key.
	 * @param string $model A model name, or empty for the default.
	 * @param string $base  An API base URL, or empty for OpenAI's.
	 */
	public function __construct( string $key, string $model = '', string $base = '' ) {
		$this->key   = $key;
		$this->model = '' === trim( $model ) ? self::DEFAULT_MODEL : trim( $model );
		$this->base  = '' === trim( $base ) ? self::DEFAULT_BASE : untrailingslashit( trim( $base ) );
	}

	/**
	 * The provider's name.
	 *
	 * @return string Its name.
	 */
	public function label(): string {
		return 'OpenAI';
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
				__( 'No OpenAI API key is saved, so there is nothing to translate with.', 'zinn-translate' )
			);
		}
		if ( array() === $strings ) {
			return array();
		}
		$response = wp_remote_post(
			$this->base . '/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->key,
				),
				'body'    => (string) wp_json_encode(
					array(
						'model'           => $this->model,
						'temperature'     => 0,
						'response_format' => array( 'type' => 'json_object' ),
						'messages'        => array(
							array(
								'role'    => 'system',
								'content' => Zinn_Translate_Prompt::system( $kind ),
							),
							array(
								'role'    => 'user',
								'content' => Zinn_Translate_Prompt::user( $strings, $source_locale, $target_locale ),
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'OpenAI could not be reached. Nothing was translated and nothing was charged; it will try again on the next pass.', 'zinn-translate' ),
				'https://status.openai.com/'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		Zinn_Translate_Prompt::assert_ok( $code, $body, 'https://platform.openai.com/account/billing', 'https://status.openai.com/' );

		$decoded = json_decode( $body, true );
		$text    = '';
		if ( is_array( $decoded ) && isset( $decoded['choices'][0]['message']['content'] ) ) {
			$text = (string) $decoded['choices'][0]['message']['content'];
		}
		return Zinn_Translate_Prompt::decode( $text, $strings, $kind );
	}
}

<?php
/**
 * DeepL — a translation API rather than a language model.
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
 * Translates with DeepL's `/v2/translate` endpoint.
 *
 * ⭐ Kept alongside the two model providers because it is genuinely a different tool: it
 * takes an array of texts and returns an array of translations, with real HTML handling and
 * a formality control, and it cannot wander off and answer a question instead. For a site
 * whose languages DeepL covers it is usually both better and cheaper — and `CLAUDE.md`
 * §2.45 says the cheap option that actually answers the question is the one to take.
 *
 * ⛔ It does NOT cover all 58 of our languages, and that is checked before the call rather
 * than discovered from a 400. A provider that silently returns nothing for Swahili would
 * leave those rows `missing` for ever with nothing anywhere saying why.
 */
final class Zinn_Translate_Provider_DeepL implements Zinn_Translate_Provider {

	/**
	 * Target languages DeepL accepts, lower-cased.
	 *
	 * ⛔ A hand-maintained list is exactly what `CLAUDE.md` §2.24 warns about, and this one
	 * is here anyway for a reason that does not apply to the general case: DeepL publishes
	 * no free capability endpoint, the list changes perhaps twice a year, and the failure
	 * when it is stale is a clear refusal naming the language rather than a silent wrong
	 * answer. It is checked against the live API by `--self-test` in the plugin's suite.
	 */
	private const SUPPORTED = array(
		'ar',
		'bg',
		'cs',
		'da',
		'de',
		'el',
		'en',
		'es',
		'et',
		'fi',
		'fr',
		'he',
		'hu',
		'id',
		'it',
		'ja',
		'ko',
		'lt',
		'lv',
		'nb',
		'nl',
		'pl',
		'pt',
		'ro',
		'ru',
		'sk',
		'sl',
		'sv',
		'th',
		'tr',
		'uk',
		'vi',
		'zh',
	);

	/**
	 * The site owner's API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Build the provider.
	 *
	 * @param string $key The DeepL API key.
	 */
	public function __construct( string $key ) {
		$this->key = trim( $key );
	}

	/**
	 * The provider's name.
	 *
	 * @return string Its name.
	 */
	public function label(): string {
		return 'DeepL';
	}

	/**
	 * Whether a call would have credentials.
	 *
	 * @return bool True when a key is configured.
	 */
	public function ready(): bool {
		return '' !== $this->key;
	}

	/**
	 * Whether DeepL can produce a language at all.
	 *
	 * @param string $locale A language code.
	 * @return bool True when DeepL supports it.
	 */
	public static function supports( string $locale ): bool {
		return in_array( strtolower( explode( '-', $locale )[0] ), self::SUPPORTED, true );
	}

	/**
	 * The API host for this key.
	 *
	 * ⛔ DeepL's free and paid tiers are DIFFERENT HOSTNAMES, and a free key sent to the paid
	 * host answers 403 — which reads exactly like a revoked key. The `:fx` suffix is how a
	 * free key identifies itself, and reading it here turns a confusing credential error into
	 * a call that simply works.
	 *
	 * @return string The API base URL.
	 */
	private function host(): string {
		return str_ends_with( $this->key, ':fx' )
			? 'https://api-free.deepl.com'
			: 'https://api.deepl.com';
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
				__( 'No DeepL API key is saved, so there is nothing to translate with.', 'zinn-translate' )
			);
		}
		if ( ! self::supports( $target_locale ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::REFUSED,
				sprintf(
					/* translators: %s: a language name, for example "Swahili". */
					__( 'DeepL does not translate into %s. Choose Google Gemini or OpenAI for this language, or connect the site to Zinn Digital®.', 'zinn-translate' ),
					Zinn_Translate_Locales::name( $target_locale )
				),
				'https://developers.deepl.com/docs/getting-started/supported-languages'
			);
		}
		if ( array() === $strings ) {
			return array();
		}

		// ⛔⛔ DeepL translates a slug into a PHRASE — `about` becomes `Über uns`, with a
		// space and an umlaut — because it has no notion of a URL segment. That is not a
		// failure of DeepL; it is the wrong tool for this field. The phrase is turned into a
		// slug by `sanitize_title()` on the way into the store, which is exactly what a human
		// editor would do, so slugs are translated here rather than refused.
		// ⛔ Order is the contract here: DeepL returns translations in the order it was given
		// the texts, with no ids of its own, so the keys are re-attached from this array
		// rather than from anything in the response.
		$keys = array_keys( $strings );
		$body = array(
			'text'        => array_values( $strings ),
			'target_lang' => strtoupper( explode( '-', $target_locale )[0] ),
			'source_lang' => strtoupper( explode( '-', $source_locale )[0] ),
		);
		if ( Zinn_Translate_Prompt::KIND_HTML === $kind ) {
			$body['tag_handling'] = 'html';
		}

		$response = wp_remote_post(
			$this->host() . '/v2/translate',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'DeepL-Auth-Key ' . $this->key,
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'DeepL could not be reached. Nothing was translated and nothing was charged; it will try again on the next pass.', 'zinn-translate' ),
				'https://www.deepl.com/en/publisher'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		// ⛔ 456 is DeepL's own "quota exceeded" and it is NOT a standard status, so a
		// generic 4xx handler reads it as a bad request and tells the customer to check
		// their key — which is wrong and wastes their time. It is a billing problem.
		if ( 456 === $code ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::CREDENTIAL,
				__( 'Your DeepL character quota is used up for this period. Translation resumes when it resets, or when you raise the limit on your DeepL account.', 'zinn-translate' ),
				'https://www.deepl.com/en/your-account/usage'
			);
		}
		Zinn_Translate_Prompt::assert_ok( $code, $raw, 'https://www.deepl.com/en/your-account/keys', 'https://www.deepl.com/en/publisher' );

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['translations'] ) || ! is_array( $decoded['translations'] ) ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::UNKNOWN,
				__( 'DeepL answered with something we could not read. Nothing was saved.', 'zinn-translate' )
			);
		}
		$out = array();
		foreach ( array_values( $decoded['translations'] ) as $index => $entry ) {
			if ( ! isset( $keys[ $index ], $entry['text'] ) ) {
				continue;
			}
			$text = (string) $entry['text'];
			if ( Zinn_Translate_Prompt::KIND_SLUG === $kind ) {
				$text = sanitize_title( $text );
				if ( '' === $text || sanitize_title( $strings[ $keys[ $index ] ] ) === $text ) {
					continue;
				}
			}
			if ( '' !== trim( $text ) ) {
				$out[ $keys[ $index ] ] = $text;
			}
		}
		return $out;
	}
}

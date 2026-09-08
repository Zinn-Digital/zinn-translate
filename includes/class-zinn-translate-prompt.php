<?php
/**
 * What we ask a model for, and how its answer is read back.
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
 * The prompt, the response parser and the shared HTTP failure classifier.
 *
 * ⭐⭐ **One place, shared by every model provider.** Gemini and OpenAI differ in their
 * envelope and in nothing else that matters here — same instruction, same JSON contract,
 * same way of reading the answer, same way of deciding whether a 401 is the customer's
 * problem or ours. Two copies would drift, and the drift is invisible: a site would get
 * subtly different translations depending on which key its owner pasted in.
 */
final class Zinn_Translate_Prompt {

	/**
	 * Plain prose — a title, an excerpt, a meta description.
	 */
	public const KIND_TEXT = 'text';

	/**
	 * Markup — a post body, a product short description.
	 */
	public const KIND_HTML = 'html';

	/**
	 * A URL segment. Its own kind because the prose instruction destroys it.
	 */
	public const KIND_SLUG = 'slug';

	/**
	 * The system instruction.
	 *
	 * ⛔⛔ **"Return the same keys" is the load-bearing sentence.** The whole batch is
	 * matched back to its documents by key, so a model that helpfully renames or reorders
	 * them produces a response we cannot attribute — and the safe thing to do with an
	 * unattributable translation is throw it away, which means the customer paid for
	 * nothing. Everything else in this instruction protects the customer's markup.
	 *
	 * @param string $kind One of the three `KIND_` constants.
	 * @return string The instruction.
	 */
	public static function system( string $kind ): string {
		if ( self::KIND_SLUG === $kind ) {
			// ⛔⛔ SLUGS GET THEIR OWN INSTRUCTION, AND THE PROSE ONE ACTIVELY BREAKS THEM.
			// It says "preserve URLs verbatim", which is right for a link inside a paragraph
			// and catastrophic for a field that IS a URL segment: the model reads `about` as
			// a URL, obeys, and returns `about` in every language. Measured on a real site
			// — 36 slugs, three languages, not one translated, and every gate green because
			// an unchanged string is a perfectly valid translation as far as anything here
			// could tell (`CLAUDE.md` §2.44).
			return implode(
				"\n",
				array(
					'You translate URL slugs for a website.',
					'You are given a JSON object mapping opaque ids to slugs.',
					'Return a JSON object with EXACTLY the same ids, each mapped to the translated slug.',
					'Never add, remove, reorder or rename an id.',
					'A slug is a short phrase written in lowercase with hyphens between words.',
					'Translate the WORDS of the slug into the target language. Do not leave it unchanged.',
					'Answer with lowercase letters, digits and hyphens only. No spaces, no accents, no punctuation.',
					'Transliterate a language with a non-Latin script into Latin letters, because this becomes a web address.',
					'Keep it short: five words at most.',
				)
			);
		}
		$is_html = self::KIND_HTML === $kind;
		$rules   = array(
			'You are a professional website localizer.',
			'You are given a JSON object mapping opaque ids to text.',
			'Return a JSON object with EXACTLY the same ids, each mapped to the translation.',
			'Never add, remove, reorder or rename an id.',
			'Translate only. Never answer, explain, summarise or comment on the text.',
			'Preserve numbers, currency amounts, dates, email addresses, URLs and code verbatim.',
			'Preserve leading and trailing whitespace exactly as given.',
			'Keep brand and product names untranslated.',
		);
		if ( $is_html ) {
			// ⛔ Tag-aware. A translated body whose `<a href>` was rewritten is a page of
			// broken links, and a body whose block-editor HTML comments were dropped is a
			// post the customer can no longer edit in the editor at all.
			$rules[] = 'The text is HTML. Translate only the text nodes and the alt, title and aria-label attributes.';
			$rules[] = 'Reproduce every tag, attribute, class, id and HTML comment byte for byte, including WordPress block comments such as <!-- wp:paragraph -->.';
			// ⛔⛔ SHORTCODES, NAMED EXPLICITLY. A body routinely contains `[contact-form]`,
			// `[product_page id="12"]` or this plugin's own `[zinn_language_switcher]`, and a
			// model that "helpfully" translates the tag name or an attribute produces a page
			// with a literal `[formulaire-contact]` printed in it where a form used to be.
			// The failure is silent: the page renders, it is 200, and a feature is gone.
			$rules[] = 'Reproduce WordPress shortcodes in square brackets byte for byte, including their names and attributes. Never translate a shortcode name or attribute.';
		} else {
			$rules[] = 'The text is plain text. Do not add markup.';
		}
		return implode( "\n", $rules );
	}

	/**
	 * The user turn.
	 *
	 * @param array<string, string> $strings       Text keyed by id.
	 * @param string                $source_locale The source language.
	 * @param string                $target_locale The target language.
	 * @return string The prompt.
	 */
	public static function user( array $strings, string $source_locale, string $target_locale ): string {
		return sprintf(
			"Translate from %s into %s.\n\n%s",
			Zinn_Translate_Locales::name( $source_locale ),
			Zinn_Translate_Locales::name( $target_locale ),
			(string) wp_json_encode( $strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Read a model's answer back into translations.
	 *
	 * ⛔⛔ **A key the model invented is DISCARDED, and a key it dropped stays missing.** The
	 * tempting alternative — zipping the answer's values onto the request's keys in order —
	 * silently attributes one page's translation to another page the moment the model
	 * returns 39 items for 40. That failure is invisible on every screen and produces a site
	 * where the About page's French text is on the Contact page.
	 *
	 * @param string                $text    The model's raw answer.
	 * @param array<string, string> $strings What was asked for.
	 * @param string                $kind    One of the three `KIND_` constants.
	 * @return array<string, string> Translations keyed by the requested ids.
	 */
	public static function decode( string $text, array $strings, string $kind = self::KIND_TEXT ): array {
		$text = trim( $text );
		// A model that wraps JSON in a ```json fence is common and harmless; unwrap it
		// rather than failing the whole batch over punctuation.
		if ( str_starts_with( $text, '```' ) ) {
			$text = (string) preg_replace( '/\A```[a-z]*\s*|\s*```\z/i', '', $text );
		}
		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $strings ) || ! is_string( $value ) ) {
				continue;
			}
			if ( '' === trim( $value ) ) {
				// ⛔ Absent, never empty. An empty translation stored as `current` would
				// blank that string on the site for ever and never be retried.
				continue;
			}
			if ( self::KIND_SLUG === $kind ) {
				// ⛔⛔ A slug is made URL-safe HERE, and an UNCHANGED slug is discarded
				// rather than stored. Storing it would mark the row `current` against the
				// source hash, so it would never be retried — a slug that failed to
				// translate once would stay English for the life of the site, and the only
				// visible symptom is a French page at an English address.
				$value = sanitize_title( $value );
				if ( '' === $value || sanitize_title( $strings[ $key ] ) === $value ) {
					continue;
				}
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Turn an HTTP status into the right kind of refusal, or return quietly.
	 *
	 * ⭐⭐ `CLAUDE.md` §2.57 applied where the site owner is the only person who can act. The
	 * distinction that matters is **whose problem it is**: a 401 or a 402 is the owner's key
	 * or the owner's bill and they can fix it in five minutes if we say so; a 500 is the
	 * vendor's and the honest thing is to say that and retry later. Reporting the first as
	 * "the provider is having an outage" sends somebody to a status page to wait for a
	 * problem that will never clear on its own.
	 *
	 * ⛔ And an unclassifiable failure is reported as OURS, never as an outage. Blaming a
	 * third party for our own bug is the reverse of what this rule exists to do.
	 *
	 * @param int    $code        The HTTP status.
	 * @param string $body        The response body, for the log only.
	 * @param string $billing_url Where the owner fixes a credential or billing problem.
	 * @param string $status_url  The provider's status page.
	 * @return void
	 * @throws Zinn_Translate_Provider_Error When the status is not a success.
	 */
	public static function assert_ok( int $code, string $body, string $billing_url, string $status_url ): void {
		if ( 200 <= $code && 300 > $code ) {
			return;
		}
		unset( $body );
		if ( 401 === $code || 403 === $code ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::CREDENTIAL,
				__( 'The API key was rejected. Check that it is still valid and has translation access, then save it again.', 'zinn-translate' ),
				$billing_url
			);
		}
		if ( 402 === $code || 429 === $code ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::CREDENTIAL,
				__( 'The provider refused on billing or quota. Nothing was translated. Check the balance and limits on your account with them.', 'zinn-translate' ),
				$billing_url
			);
		}
		if ( 500 <= $code ) {
			throw new Zinn_Translate_Provider_Error(
				Zinn_Translate_Provider_Error::OUTAGE,
				__( 'The translation provider is having a problem at their end. Nothing was translated or charged, and it will try again on the next pass.', 'zinn-translate' ),
				$status_url
			);
		}
		throw new Zinn_Translate_Provider_Error(
			Zinn_Translate_Provider_Error::UNKNOWN,
			__( 'The translation request failed and we could not tell why. Nothing was saved.', 'zinn-translate' )
		);
	}
}

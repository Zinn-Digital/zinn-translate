<?php
/**
 * The one seam every translation source sits behind.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Something that can turn a batch of strings into another language.
 *
 * ⚖️⚖️ **OWNER RULING, 2026-09-08, VERBATIM:** *"we are not paying to translate peoples
 * sites, that is madness, they need to either use our ai credits for it or byo key for their
 * providers"*. So there are exactly two funding models and no third free one:
 *
 * * **Zinn** — the site is connected to a Zinn Digital® account and the words are bought
 *   against that customer's own Site Translation plan. We never absorb the cost.
 * * **BYO key** — the site owner pastes their own Google Gemini, DeepL or OpenAI key and is
 *   billed by that vendor directly. This is what makes the plugin usable with no Zinn
 *   account at all, which is what lets us give it away on WordPress.org.
 *
 * ⛔ `CLAUDE.md` §2.6 — no vendor lock-in. Three implementations exist, they are chosen by a
 * setting, and no business logic in this plugin calls a vendor SDK. A fourth provider is a
 * new file and one entry in the factory.
 *
 * ⛔⛔ **A provider REFUSES rather than returning the source text.** Handing back the
 * original on failure would store English in the French column, mark it `current`, and the
 * site would serve English for ever with every screen reporting success — the exact shape
 * `CLAUDE.md` §2.44 calls the expensive direction.
 */
interface Zinn_Translate_Provider {

	/**
	 * Translate a batch of strings.
	 *
	 * @param array<string, string> $strings       Text keyed by an opaque id the caller chose.
	 * @param string                $source_locale The language the strings are in.
	 * @param string                $target_locale The language to produce.
	 * @param string                $kind          One of `Zinn_Translate_Prompt`'s KIND_ constants.
	 * @return array<string, string> Translations keyed by the SAME ids. A string the provider
	 *                               could not translate is ABSENT, never present and empty.
	 * @throws Zinn_Translate_Provider_Error When the provider could not be reached or refused.
	 */
	public function translate( array $strings, string $source_locale, string $target_locale, string $kind ): array;

	/**
	 * A name for the settings screen.
	 *
	 * @return string The provider's own name.
	 */
	public function label(): string;

	/**
	 * Whether this provider is configured well enough to be called.
	 *
	 * @return bool True when a call would have credentials.
	 */
	public function ready(): bool;
}

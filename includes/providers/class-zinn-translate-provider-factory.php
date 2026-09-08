<?php
/**
 * Choosing the provider the site is configured for.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the provider named in the settings.
 *
 * ⛔ **There is no default that spends somebody's money by accident.** A site with no mode,
 * no key and no Zinn connection gets a provider that refuses immediately and says why — not
 * one that quietly picks whichever credential it can find. `CLAUDE.md` §2.45's rule is that
 * a default follows a measured price; the sharper rule here is that a default must never
 * follow a credential the customer did not choose to use for this.
 */
final class Zinn_Translate_Provider_Factory {

	/**
	 * What each provider costs to translate a thousand words, in cents.
	 *
	 * ⛔⛔ **MEASURED, AND THE DEFAULT IS COMPUTED FROM IT** (`CLAUDE.md` §2.45). A default
	 * written as a string literal beside the prices is a comment that happens to run: it is
	 * correct on the day somebody types it and nothing notices when a cheaper tier appears,
	 * when a vendor changes its rates, or when somebody "temporarily" flips it. A derived one
	 * picks up a new cheap option on its own, and a test asserts that nobody can make an
	 * expensive provider the default without changing these numbers.
	 *
	 * ⚠️ **`PRICES_CAPTURED_ON` is part of the data, not decoration.** These are the vendors'
	 * published list prices for the model each provider defaults to, at roughly 750 words per
	 * 1,000 tokens. They are list prices rather than a balance measurement because the money
	 * here is the SITE OWNER'S and we never see their bill — which is exactly why the
	 * comparison has to be re-read rather than trusted for ever. A test fails when this date
	 * is over a year old.
	 *
	 * @return array<string, int> Provider id => cents per 1,000 words translated.
	 */
	public static function prices(): array {
		return array(
			'gemini' => 3,
			'openai' => 12,
			'deepl'  => 25,
		);
	}

	/**
	 * The day :func:`prices` was read from the vendors' own pricing pages.
	 */
	public const PRICES_CAPTURED_ON = '2026-09-08';

	/**
	 * The provider an unrecognised setting falls back to: the cheapest measured one.
	 *
	 * ⛔ Computed, never typed.
	 *
	 * ⛔⛔ **`$prices` IS INJECTABLE SO THE TEST CAN ACTUALLY FAIL, and that is the whole
	 * reason the parameter exists.** The obvious test — `cheapest() === 'gemini'` — is true
	 * today whatever this function does, because Gemini genuinely is the cheapest entry: a
	 * mutant that threw the table away and returned the literal `'gemini'` SURVIVES it. That
	 * is `CLAUDE.md` §2.24's amendment exactly, a test written from the same intention as the
	 * code and asserting the answer rather than the mechanism. Handing it a table where a
	 * different provider is cheapest is the only assertion that can tell the two apart, and
	 * it was written after watching the naive one pass against the mutant.
	 *
	 * @param array<string, int>|null $prices A price table, or null for the measured one.
	 * @return string A provider id.
	 */
	public static function cheapest( ?array $prices = null ): string {
		$prices = null === $prices ? self::prices() : $prices;
		asort( $prices );
		return (string) array_key_first( $prices );
	}

	/**
	 * The BYO providers a site owner may choose between.
	 *
	 * @return array<string, string> Provider id => name.
	 */
	public static function choices(): array {
		return array(
			'gemini' => 'Google Gemini',
			'deepl'  => 'DeepL',
			'openai' => 'OpenAI',
		);
	}

	/**
	 * The provider this site is configured for.
	 *
	 * @return Zinn_Translate_Provider The provider.
	 */
	public static function current(): Zinn_Translate_Provider {
		if ( 'byo' === Zinn_Translate_Options::text( 'mode' ) ) {
			return self::byo();
		}
		return new Zinn_Translate_Provider_Zinn(
			Zinn_Translate_Options::text( 'site_id' ),
			Zinn_Translate_Options::text( 'token' )
		);
	}

	/**
	 * The Zinn Digital® provider specifically, for the code that must push an inventory.
	 *
	 * @return Zinn_Translate_Provider_Zinn The provider.
	 */
	public static function zinn(): Zinn_Translate_Provider_Zinn {
		return new Zinn_Translate_Provider_Zinn(
			Zinn_Translate_Options::text( 'site_id' ),
			Zinn_Translate_Options::text( 'token' )
		);
	}

	/**
	 * The configured bring-your-own-key provider.
	 *
	 * @return Zinn_Translate_Provider The provider.
	 */
	private static function byo(): Zinn_Translate_Provider {
		$key    = Zinn_Translate_Options::text( 'provider_key' );
		$model  = Zinn_Translate_Options::text( 'provider_model' );
		$chosen = Zinn_Translate_Options::text( 'provider' );
		if ( ! isset( self::choices()[ $chosen ] ) ) {
			// ⛔ An unrecognised setting lands on the CHEAPEST measured provider, computed
			// from `prices()` — never on whichever branch happens to be written last. A typo
			// must not silently buy the dear one across a whole site, and it is the site
			// owner's money.
			$chosen = self::cheapest();
		}
		switch ( $chosen ) {
			case 'deepl':
				return new Zinn_Translate_Provider_DeepL( $key );
			case 'openai':
				return new Zinn_Translate_Provider_OpenAI( $key, $model, Zinn_Translate_Options::text( 'provider_base' ) );
			default:
				return new Zinn_Translate_Provider_Gemini( $key, $model );
		}
	}
}

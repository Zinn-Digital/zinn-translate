<?php
/**
 * The languages Zinn Digital® translates into — code, name, native name, direction.
 *
 * ⛔⛔ GENERATED FILE. Do not edit by hand.
 *     Source:    packages/i18n/languages.json
 *     Generator: scripts/wp-translate-locales.py  (--check runs in pre-commit and CI)
 *
 * ⭐ It is generated because it is a COPY of a registry, and a hand-maintained copy of a
 * list is wrong the first time the registry moves — silently, because a missing language
 * does not raise anything, it simply never appears in the picker.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

// ⛔⛔ ARRAY FORMATTING SNIFFS OFF FOR THIS FILE, AND THE REASON IS THAT THE GATE CANNOT
// OTHERWISE EVER PASS. `phpcbf` rewrites a one-line-per-entry array into an aligned,
// multi-line one — so the generator emits one shape, the formatter rewrites it to another,
// and `--check` reports the file STALE for ever, on a tree where nothing is wrong. A
// generated file that a formatter rewrites has two authors that disagree, and the honest fix
// is to give the generator the last word on a file nobody edits by hand.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every language this plugin can publish, and what a page in it needs to know.
 */
final class Zinn_Translate_Locales {

	/**
	 * Code => name, native name, direction. Generated; see the file header.
	 *
	 * @var array<string, array{name: string, native: string, dir: string}>
	 */
	// phpcs:disable WordPress.Arrays.MultipleStatementAlignment
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing
	private const LANGUAGES = array(
		'en' => array( 'name' => 'English', 'native' => 'English', 'dir' => 'ltr' ),
		'es' => array( 'name' => 'Spanish', 'native' => 'Español', 'dir' => 'ltr' ),
		'pt-BR' => array( 'name' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)', 'dir' => 'ltr' ),
		'fr' => array( 'name' => 'French', 'native' => 'Français', 'dir' => 'ltr' ),
		'de' => array( 'name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr' ),
		'it' => array( 'name' => 'Italian', 'native' => 'Italiano', 'dir' => 'ltr' ),
		'nl' => array( 'name' => 'Dutch', 'native' => 'Nederlands', 'dir' => 'ltr' ),
		'pl' => array( 'name' => 'Polish', 'native' => 'Polski', 'dir' => 'ltr' ),
		'ru' => array( 'name' => 'Russian', 'native' => 'Русский', 'dir' => 'ltr' ),
		'uk' => array( 'name' => 'Ukrainian', 'native' => 'Українська', 'dir' => 'ltr' ),
		'ro' => array( 'name' => 'Romanian', 'native' => 'Română', 'dir' => 'ltr' ),
		'bg' => array( 'name' => 'Bulgarian', 'native' => 'Български', 'dir' => 'ltr' ),
		'el' => array( 'name' => 'Greek', 'native' => 'Ελληνικά', 'dir' => 'ltr' ),
		'cs' => array( 'name' => 'Czech', 'native' => 'Čeština', 'dir' => 'ltr' ),
		'hu' => array( 'name' => 'Hungarian', 'native' => 'Magyar', 'dir' => 'ltr' ),
		'sr' => array( 'name' => 'Serbian', 'native' => 'Српски', 'dir' => 'ltr' ),
		'hr' => array( 'name' => 'Croatian', 'native' => 'Hrvatski', 'dir' => 'ltr' ),
		'sq' => array( 'name' => 'Albanian', 'native' => 'Shqip', 'dir' => 'ltr' ),
		'tr' => array( 'name' => 'Turkish', 'native' => 'Türkçe', 'dir' => 'ltr' ),
		'az' => array( 'name' => 'Azerbaijani', 'native' => 'Azərbaycan', 'dir' => 'ltr' ),
		'hy' => array( 'name' => 'Armenian', 'native' => 'Հայերեն', 'dir' => 'ltr' ),
		'ka' => array( 'name' => 'Georgian', 'native' => 'ქართული', 'dir' => 'ltr' ),
		'uz' => array( 'name' => 'Uzbek', 'native' => 'Oʻzbek', 'dir' => 'ltr' ),
		'ja' => array( 'name' => 'Japanese', 'native' => '日本語', 'dir' => 'ltr' ),
		'zh' => array( 'name' => 'Chinese', 'native' => '中文', 'dir' => 'ltr' ),
		'ko' => array( 'name' => 'Korean', 'native' => '한국어', 'dir' => 'ltr' ),
		'id' => array( 'name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'dir' => 'ltr' ),
		'vi' => array( 'name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'dir' => 'ltr' ),
		'th' => array( 'name' => 'Thai', 'native' => 'ไทย', 'dir' => 'ltr' ),
		'km' => array( 'name' => 'Khmer', 'native' => 'ខ្មែរ', 'dir' => 'ltr' ),
		'ms' => array( 'name' => 'Malay', 'native' => 'Bahasa Melayu', 'dir' => 'ltr' ),
		'fil' => array( 'name' => 'Filipino', 'native' => 'Filipino', 'dir' => 'ltr' ),
		'my' => array( 'name' => 'Burmese', 'native' => 'မြန်မာ', 'dir' => 'ltr' ),
		'hi' => array( 'name' => 'Hindi', 'native' => 'हिन्दी', 'dir' => 'ltr' ),
		'bn' => array( 'name' => 'Bengali', 'native' => 'বাংলা', 'dir' => 'ltr' ),
		'ta' => array( 'name' => 'Tamil', 'native' => 'தமிழ்', 'dir' => 'ltr' ),
		'te' => array( 'name' => 'Telugu', 'native' => 'తెలుగు', 'dir' => 'ltr' ),
		'mr' => array( 'name' => 'Marathi', 'native' => 'मराठी', 'dir' => 'ltr' ),
		'pa' => array( 'name' => 'Punjabi', 'native' => 'ਪੰਜਾਬੀ', 'dir' => 'ltr' ),
		'kn' => array( 'name' => 'Kannada', 'native' => 'ಕನ್ನಡ', 'dir' => 'ltr' ),
		'gu' => array( 'name' => 'Gujarati', 'native' => 'ગુજરાતી', 'dir' => 'ltr' ),
		'ne' => array( 'name' => 'Nepali', 'native' => 'नेपाली', 'dir' => 'ltr' ),
		'si' => array( 'name' => 'Sinhala', 'native' => 'සිංහල', 'dir' => 'ltr' ),
		'ml' => array( 'name' => 'Malayalam', 'native' => 'മലയാളം', 'dir' => 'ltr' ),
		'sw' => array( 'name' => 'Swahili', 'native' => 'Kiswahili', 'dir' => 'ltr' ),
		'am' => array( 'name' => 'Amharic', 'native' => 'አማርኛ', 'dir' => 'ltr' ),
		'ar' => array( 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl' ),
		'ur' => array( 'name' => 'Urdu', 'native' => 'اردو', 'dir' => 'rtl' ),
		'fa' => array( 'name' => 'Persian', 'native' => 'فارسی', 'dir' => 'rtl' ),
		'he' => array( 'name' => 'Hebrew', 'native' => 'עברית', 'dir' => 'rtl' ),
		'ps' => array( 'name' => 'Pashto', 'native' => 'پښتو', 'dir' => 'rtl' ),
		'so' => array( 'name' => 'Somali', 'native' => 'Soomaali', 'dir' => 'ltr' ),
		'ha' => array( 'name' => 'Hausa', 'native' => 'Hausa', 'dir' => 'ltr' ),
		'yo' => array( 'name' => 'Yoruba', 'native' => 'Yorùbá', 'dir' => 'ltr' ),
		'kk' => array( 'name' => 'Kazakh', 'native' => 'Қазақ', 'dir' => 'ltr' ),
		'tg' => array( 'name' => 'Tajik', 'native' => 'Тоҷикӣ', 'dir' => 'ltr' ),
		'lo' => array( 'name' => 'Lao', 'native' => 'ລາວ', 'dir' => 'ltr' ),
		'mn' => array( 'name' => 'Mongolian', 'native' => 'Монгол', 'dir' => 'ltr' ),
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing
	// phpcs:enable WordPress.Arrays.MultipleStatementAlignment

	/**
	 * Every language code we support, in registry order.
	 *
	 * @return string[] Language codes.
	 */
	public static function codes(): array {
		return array_keys( self::LANGUAGES );
	}

	/**
	 * Whether a code is one we support.
	 *
	 * @param string $code Language code.
	 * @return bool True when the code is in the table.
	 */
	public static function known( string $code ): bool {
		return isset( self::LANGUAGES[ $code ] );
	}

	/**
	 * The English name of a language, or the code itself when we do not know it.
	 *
	 * @param string $code Language code.
	 * @return string A name safe to show.
	 */
	public static function name( string $code ): string {
		return self::LANGUAGES[ $code ]['name'] ?? $code;
	}

	/**
	 * The language's own name for itself — what belongs in a language switcher.
	 *
	 * ⛔ The NATIVE name, not the English one, is what a switcher shows. Somebody who
	 * cannot read the current page's language cannot read "German" either; they can read
	 * "Deutsch". A switcher labelled in English is a switcher for people who do not need it.
	 *
	 * @param string $code Language code.
	 * @return string The language's own name, or the code when we do not know it.
	 */
	public static function native_name( string $code ): string {
		return self::LANGUAGES[ $code ]['native'] ?? $code;
	}

	/**
	 * `ltr` or `rtl` for a language.
	 *
	 * ⛔ Defaults to `ltr` for an unknown code, which is the safe wrong answer: an RTL page
	 * rendered LTR is awkward, and an LTR page rendered RTL is unreadable.
	 *
	 * @param string $code Language code.
	 * @return string Either `ltr` or `rtl`.
	 */
	public static function direction( string $code ): string {
		return self::LANGUAGES[ $code ]['dir'] ?? 'ltr';
	}

	/**
	 * Whether a language is written right to left.
	 *
	 * @param string $code Language code.
	 * @return bool True for Arabic, Hebrew, Persian, Urdu and Pashto.
	 */
	public static function is_rtl( string $code ): bool {
		return 'rtl' === self::direction( $code );
	}

	/**
	 * The `hreflang` value for a code.
	 *
	 * ⛔ Ours are already valid BCP 47 language tags, so this is the identity today. It
	 * exists so that the day a regional code (`pt-BR`) enters the registry there is one
	 * place that decides how it is spelled in an attribute, rather than eleven call sites.
	 *
	 * @param string $code Language code.
	 * @return string The tag to put in an hreflang attribute.
	 */
	public static function hreflang( string $code ): string {
		return $code;
	}

	/**
	 * Everything about every language, for a settings screen.
	 *
	 * @return array<string, array{name: string, native: string, dir: string}> The table.
	 */
	public static function all(): array {
		return self::LANGUAGES;
	}
}

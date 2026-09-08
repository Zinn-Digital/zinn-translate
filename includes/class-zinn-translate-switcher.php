<?php
/**
 * The language switcher — shortcode, block, menu item and widget.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a language picker in whichever way the site owner wants to place one.
 *
 * ⛔⛔ **FOUR PLACEMENTS, ONE RENDERER.** A shortcode for the body, a block for the editor,
 * a nav-menu item for the header, and a widget for a sidebar. They exist separately because
 * WordPress gives a site owner four unrelated ways to put something on a page and a plugin
 * that only supports one of them is a plugin whose switcher cannot go where the theme needs
 * it — but all four call :meth:`render`, so a preset, a label choice or an accessibility fix
 * lands in every placement at once.
 *
 * ⛔ **Only the languages that have something to show.** The list is the customer's chosen
 * publish set, optionally narrowed to a subset they pick. A switcher offering a language
 * whose page does not exist is a link to a 404 in the site's own header.
 *
 * ── ⛔ On styling ────────────────────────────────────────────────────────────────────
 * The presets are TOKENS, not stylesheets. `Zinn_Translate_Style_Presets` (the shared
 * plugin framework) prints `--zinn-switcher-accent` and friends as custom properties, and
 * the component CSS here reads them with `var()`. So a customer can recolour the switcher
 * without us shipping one stylesheet per preset, and a preset that nobody chose costs a
 * visitor nothing. Every rule uses LOGICAL properties (`margin-inline-start`), so RTL is
 * handled by the same CSS rather than by a mirrored copy of it (`CLAUDE.md` §2.7).
 */
final class Zinn_Translate_Switcher {

	/**
	 * The style presets a site owner can choose between.
	 *
	 * @return array<string, string> Preset id => label.
	 */
	public static function presets(): array {
		return array(
			'dropdown' => __( 'Dropdown', 'zinn-translate' ),
			'inline'   => __( 'Inline list', 'zinn-translate' ),
			'pill'     => __( 'Pills', 'zinn-translate' ),
			'minimal'  => __( 'Minimal text', 'zinn-translate' ),
			'flags'    => __( 'Flags and names', 'zinn-translate' ),
			'sidebar'  => __( 'Stacked list', 'zinn-translate' ),
		);
	}

	/**
	 * Register the shortcode, the block, the widget and the menu integration.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_shortcode( 'zinn_language_switcher', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'menu_item' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Ship the component stylesheet, and only where a switcher can appear.
	 *
	 * ⛔ `CLAUDE.md` §2.22 — a visitor reading a blog post must not download a stylesheet
	 * for a widget that is not on the page. The file is tiny, but "it is only small" is how
	 * a bundle grows, and this one is genuinely conditional: with no languages published
	 * there is no switcher anywhere and nothing is enqueued at all.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( array() === Zinn_Translate_Options::locales() ) {
			return;
		}
		wp_register_style(
			'zinn-translate-switcher',
			plugins_url( 'assets/switcher.css', ZINN_TRANSLATE_FILE ),
			array(),
			ZINN_TRANSLATE_VERSION
		);
	}

	/**
	 * `[zinn_language_switcher]`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string The rendered switcher.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'preset'  => '',
				'labels'  => '',
				'flags'   => '',
				'current' => '',
				'source'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			'zinn_language_switcher'
		);
		return self::render(
			array(
				'preset'  => (string) $atts['preset'],
				'labels'  => (string) $atts['labels'],
				'flags'   => '' === $atts['flags'] ? null : self::truthy( (string) $atts['flags'] ),
				'current' => '' === $atts['current'] ? null : self::truthy( (string) $atts['current'] ),
				'source'  => '' === $atts['source'] ? null : self::truthy( (string) $atts['source'] ),
			)
		);
	}

	/**
	 * Register the editor block.
	 *
	 * ⛔ Server-rendered (`render_callback`) rather than a JavaScript block. The list of
	 * links depends on the URL being viewed, which the editor cannot know — and a block
	 * that stores its markup would freeze one page's links into every page it is used on.
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'zinn/language-switcher',
			array(
				'api_version'     => 3,
				'title'           => __( 'Language switcher', 'zinn-translate' ),
				'category'        => 'widgets',
				'icon'            => 'translation',
				'description'     => __( 'Let visitors read this page in another language.', 'zinn-translate' ),
				'attributes'      => array(
					'preset' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array<string, string> $attributes Block attributes.
	 * @return string The rendered switcher.
	 */
	public function render_block( $attributes ): string {
		$attributes = (array) $attributes;
		return self::render( array( 'preset' => (string) ( $attributes['preset'] ?? '' ) ) );
	}

	/**
	 * Register the sidebar widget.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		if ( class_exists( 'Zinn_Translate_Switcher_Widget' ) ) {
			register_widget( 'Zinn_Translate_Switcher_Widget' );
		}
	}

	/**
	 * Append the switcher to a nav menu.
	 *
	 * ⛔⛔ **Only to the menu the customer NAMED**, matched by theme location. Appending to
	 * every menu puts a language picker in the footer, the legal menu and the account menu
	 * of every site that installs this, which is exactly the "adds itself everywhere"
	 * behaviour that gets a plugin uninstalled.
	 *
	 * @param string $items The menu's rendered `<li>` items.
	 * @param object $args  The menu arguments.
	 * @return string The items, with ours appended when this is the chosen menu.
	 */
	public function menu_item( $items, $args = null ): string {
		$location = is_object( $args ) && isset( $args->theme_location ) ? (string) $args->theme_location : '';
		$wanted   = Zinn_Translate_Options::text( 'switcher_menu' );
		if ( '' === $wanted || $wanted !== $location ) {
			return (string) $items;
		}
		$switcher = self::render( array( 'preset' => 'inline' ) );
		if ( '' === $switcher ) {
			return (string) $items;
		}
		return (string) $items . '<li class="menu-item zinn-switcher-menu-item">' . $switcher . '</li>';
	}

	/**
	 * The languages this switcher offers, in registry order, with their URLs.
	 *
	 * @return array<int, array{code: string, label: string, url: string, current: bool, dir: string}> The entries.
	 */
	public static function entries(): array {
		$published = Zinn_Translate_Options::locales();
		if ( array() === $published ) {
			return array();
		}
		$chosen = Zinn_Translate_Options::get( 'switcher_locales', array() );
		$chosen = is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		if ( array() !== $chosen ) {
			// ⛔ Intersected, never used as the list. A code the customer picked here but
			// later stopped publishing would otherwise be a link to a page that does not
			// exist, in their own header, for as long as nobody noticed.
			$published = array_values( array_intersect( $published, $chosen ) );
		}

		$current = Zinn_Translate_Router::current();
		$native  = Zinn_Translate_Options::flag( 'switcher_native_names' );
		$path    = Zinn_Translate_Router::strip_prefix( Zinn_Translate_Router::request_path(), $current );
		$raw     = Zinn_Translate_Router::to_source_path( ltrim( $path, '/' ), $current );
		$source  = '' === trim( $raw, '/' ) ? '/' : user_trailingslashit( '/' . trim( $raw, '/' ) );
		$home    = Zinn_Translate_Router::home_base();

		$entries = array();
		if ( Zinn_Translate_Options::flag( 'switcher_show_source' ) ) {
			$code      = Zinn_Translate_Options::source_locale();
			$entries[] = array(
				'code'    => $code,
				'label'   => $native ? Zinn_Translate_Locales::native_name( $code ) : Zinn_Translate_Locales::name( $code ),
				'url'     => $home . $source,
				'current' => '' === $current,
				'dir'     => Zinn_Translate_Locales::direction( $code ),
			);
		}
		foreach ( $published as $code ) {
			$entries[] = array(
				'code'    => $code,
				'label'   => $native ? Zinn_Translate_Locales::native_name( $code ) : Zinn_Translate_Locales::name( $code ),
				'url'     => $home . '/' . $code . Zinn_Translate_Router::to_localised_path( $source, $code ),
				'current' => $code === $current,
				'dir'     => Zinn_Translate_Locales::direction( $code ),
			);
		}
		if ( ! Zinn_Translate_Options::flag( 'switcher_show_current' ) ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function ( array $entry ): bool {
						return ! $entry['current'];
					}
				)
			);
		}
		return $entries;
	}

	/**
	 * Render the switcher.
	 *
	 * @param array<string, mixed> $args Overrides for the stored settings.
	 * @return string The markup, or an empty string when there is nothing to show.
	 */
	public static function render( array $args = array() ): string {
		$entries = self::entries();
		if ( count( $entries ) < 2 ) {
			// ⛔ One language is not a choice. Rendering a "switcher" with a single entry
			// puts a dead control in a customer's header and makes the site look broken.
			return '';
		}
		wp_enqueue_style( 'zinn-translate-switcher' );

		$preset = isset( $args['preset'] ) && '' !== $args['preset']
			? (string) $args['preset']
			: Zinn_Translate_Options::text( 'switcher_preset' );
		if ( ! isset( self::presets()[ $preset ] ) ) {
			$preset = 'dropdown';
		}
		$show_flags = isset( $args['flags'] ) && null !== $args['flags']
			? (bool) $args['flags']
			: Zinn_Translate_Options::flag( 'switcher_show_flags' );

		$classes = 'zinn-switcher zinn-switcher--' . sanitize_html_class( $preset );

		ob_start();
		?>
		<nav class="<?php echo esc_attr( $classes ); ?>" aria-label="<?php esc_attr_e( 'Choose a language', 'zinn-translate' ); ?>">
			<ul class="zinn-switcher__list">
				<?php foreach ( $entries as $entry ) : ?>
					<li class="zinn-switcher__item<?php echo $entry['current'] ? ' is-current' : ''; ?>">
						<a
							class="zinn-switcher__link"
							href="<?php echo esc_url( $entry['url'] ); ?>"
							hreflang="<?php echo esc_attr( Zinn_Translate_Locales::hreflang( $entry['code'] ) ); ?>"
							lang="<?php echo esc_attr( Zinn_Translate_Locales::hreflang( $entry['code'] ) ); ?>"
							dir="<?php echo esc_attr( $entry['dir'] ); ?>"
							<?php
							// ⛔ `aria-current="true"`, not a CSS class alone. A visitor using a
							// screen reader has no other way to know which language they are
							// already reading, and "which one am I on?" is the whole question a
							// language switcher answers.
							echo $entry['current'] ? ' aria-current="true"' : '';
							?>
						>
							<?php if ( $show_flags ) : ?>
								<span class="zinn-switcher__flag" aria-hidden="true"><?php echo esc_html( self::flag( $entry['code'] ) ); ?></span>
							<?php endif; ?>
							<span class="zinn-switcher__label"><?php echo esc_html( $entry['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A flag emoji for a language, or an empty string when there is no honest one.
	 *
	 * ⛔⛔ **A LANGUAGE IS NOT A COUNTRY, AND THIS IS WHY FLAGS ARE OFF BY DEFAULT.** Arabic
	 * is spoken in twenty-two countries and Spanish in twenty; picking one flag for either
	 * tells most of their speakers that the site means somebody else. Where there is no
	 * defensible single flag this returns nothing and the name stands on its own, which is
	 * the right answer rather than a compromise.
	 *
	 * @param string $code Language code.
	 * @return string A flag emoji, or an empty string.
	 */
	public static function flag( string $code ): string {
		$flags = array(
			'de' => '🇩🇪',
			'fr' => '🇫🇷',
			'it' => '🇮🇹',
			'ja' => '🇯🇵',
			'ko' => '🇰🇷',
			'nl' => '🇳🇱',
			'pl' => '🇵🇱',
			'tr' => '🇹🇷',
			'th' => '🇹🇭',
			'vi' => '🇻🇳',
			'cs' => '🇨🇿',
			'el' => '🇬🇷',
			'hu' => '🇭🇺',
			'ro' => '🇷🇴',
			'fi' => '🇫🇮',
			'da' => '🇩🇰',
			'sv' => '🇸🇪',
			'nb' => '🇳🇴',
			'he' => '🇮🇱',
			'is' => '🇮🇸',
			'uk' => '🇺🇦',
			'id' => '🇮🇩',
		);
		return $flags[ $code ] ?? '';
	}

	/**
	 * Read a shortcode attribute as a boolean.
	 *
	 * @param string $value The attribute value.
	 * @return bool What the author meant.
	 */
	private static function truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}

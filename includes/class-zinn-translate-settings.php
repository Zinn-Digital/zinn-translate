<?php
/**
 * Settings — the site's Zinn Digital® identity and which languages it serves.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the site id, the read token and the served locales.
 *
 * ⛔ The served-locale list is authoritative HERE, on the site, and not on our side. The
 * engine knows which locales the customer CHOSE; this list is what the site actually
 * publishes. They are normally the same and the settings screen syncs them — but a site
 * that cannot reach us must keep serving the URLs it was already serving, because the
 * alternative is that our outage 404s a customer's indexed pages.
 */
class Zinn_Translate_Settings {

	private const OPTION_SITE_ID = 'zinn_translate_site_id';
	private const OPTION_TOKEN   = 'zinn_translate_token';
	private const OPTION_LOCALES = 'zinn_translate_locales';

	/**
	 * Register the settings screen.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * The Zinn Digital® site id this WordPress install belongs to.
	 *
	 * @return string The id, or an empty string when the site has not been connected.
	 */
	public function site_id(): string {
		return (string) get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * The read-only token used to fetch this site's translations.
	 *
	 * ⛔ Read-only by construction at the other end: it can fetch this site's bundle and
	 * nothing else, so a leaked token cannot change anything.
	 *
	 * @return string The token, or an empty string when none is configured.
	 */
	public function token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	/**
	 * The locales this site publishes.
	 *
	 * ⛔ An empty list means NONE, never "all". Publishing 57 locale-prefixed URL trees on
	 * somebody's domain because an option was empty is an SEO event they did not ask for and
	 * cannot easily undo — the same rule the engine applies at its own end.
	 *
	 * @return string[] Locale codes.
	 */
	public function locales(): array {
		$raw = get_option( self::OPTION_LOCALES, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $code ) {
			$code = trim( (string) $code );
			// ⛔ Validated against a strict pattern, not merely non-empty. This value ends up
			// in a rewrite rule and in an `hreflang` attribute; an unvalidated option that
			// reaches a regular expression is a way to break every URL on the site.
			if ( '' !== $code && 1 === preg_match( '/\A[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\z/', $code ) ) {
				$clean[] = $code;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Add the Settings → Zinn Translate page.
	 *
	 * @return void
	 */
	public function menu(): void {
		add_options_page(
			__( 'Zinn® Translate', 'zinn-translate' ),
			__( 'Zinn® Translate', 'zinn-translate' ),
			'manage_options',
			'zinn-translate',
			array( $this, 'render' )
		);
	}

	/**
	 * Register the three stored options and their sanitizers.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			'zinn_translate',
			self::OPTION_SITE_ID,
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'zinn_translate',
			self::OPTION_TOKEN,
			array(
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'zinn_translate',
			self::OPTION_LOCALES,
			array(
				'sanitize_callback' => array( $this, 'sanitize_locales' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Reduce a submitted locale list to valid language tags.
	 *
	 * ⛔ Validated against a strict pattern rather than merely trimmed: this value ends up in
	 * a rewrite rule and in an `hreflang` attribute, so an unvalidated option reaching a
	 * regular expression is a way to break every URL on the site.
	 *
	 * @param mixed $value Raw submitted value — an array or a comma-separated string.
	 * @return string[] The accepted locale codes.
	 */
	public function sanitize_locales( $value ): array {
		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
		$clean = array();
		foreach ( $parts as $code ) {
			$code = strtolower( trim( (string) $code ) );
			if ( '' !== $code && 1 === preg_match( '/\A[a-z]{2,3}(-[a-z0-9]{2,8})?\z/', $code ) ) {
				$clean[] = $code;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zinn® Translate', 'zinn-translate' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Your site is translated by Zinn Digital®. Choose the languages to publish, and each one is served on its own web address with the right hreflang tags.',
					'zinn-translate'
				);
				?>
			</p>
			<form action="options.php" method="post">
				<?php settings_fields( 'zinn_translate' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zinn_translate_site_id"><?php esc_html_e( 'Site ID', 'zinn-translate' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_SITE_ID ); ?>" id="zinn_translate_site_id" type="text" class="regular-text" value="<?php echo esc_attr( $this->site_id() ); ?>" />
							<p class="description"><?php esc_html_e( 'From your Zinn Digital® dashboard, on this site\'s Translation tab.', 'zinn-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_translate_token"><?php esc_html_e( 'Read-only token', 'zinn-translate' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_TOKEN ); ?>" id="zinn_translate_token" type="password" class="regular-text" value="<?php echo esc_attr( $this->token() ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Read-only. It can fetch this site\'s translations and nothing else.', 'zinn-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zinn_translate_locales"><?php esc_html_e( 'Languages to publish', 'zinn-translate' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_LOCALES ); ?>" id="zinn_translate_locales" type="text" class="regular-text" value="<?php echo esc_attr( implode( ', ', $this->locales() ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma separated, for example: fr, de, es. Leave empty to publish none.', 'zinn-translate' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
			// ⛔⛔ AT THE BOTTOM OF THE SCREEN, INSIDE `.wrap`, BELOW THE CONTROLS — NEVER ABOVE
			// THEM. Somebody who opened a settings screen came to change a setting. A promotion
			// that pushes the thing they came for below the fold is the "disruptive upselling"
			// a WordPress.org reviewer rejects, and it would deserve it.
			Zinn_Translate_Promo::render_panel();
			?>
		</div>
		<?php
	}
}

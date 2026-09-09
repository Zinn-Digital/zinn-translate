<?php
/**
 * The settings screens: what the site owner sees and changes.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares this plugin's settings to the shared framework, and owns the override screen.
 *
 * ⛔⛔ **THE SETTINGS FRAMEWORK IS W41-Q'S AND THIS PLUGIN ONLY DECLARES.** Field types,
 * sanitising, nonces, capability checks, the connection card and the style-preset mechanism
 * are all shared across every Zinn® plugin, so a fix to any of them lands everywhere at
 * once. A second framework here would be a second set of escaping bugs to find.
 *
 * ⭐ There WAS a temporary bridge here — an `else` branch registering the same parent menu,
 * page slug and option array as the framework, so a site configured through it was configured
 * for the framework too. It existed because a plugin whose settings screen has not shipped
 * yet is a plugin a customer cannot use, and it was owned jointly with W41-Q on the agreement
 * that whichever branch reached `main` second would delete the other. W41-O landed first, so
 * W41-Q deleted it in the same change that renders this plugin's framework classes — which is
 * where the deletion belongs, next to the thing that makes it correct.
 */
final class Zinn_Translate_Admin {

	/**
	 * The one top-level menu every Zinn® plugin hangs off.
	 */
	private const PARENT = 'zinn';

	private const PAGE = 'zinn-translate';

	/**
	 * Register the screens.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// ⛔ Unconditional. The `class_exists` guard and its bridge screen were a deliberate,
		// jointly-owned temporary: W41-O shipped a plugin whose framework had not landed yet,
		// on the agreement that whichever branch reached `main` second deleted the other.
		// `Zinn_Translate_Admin_UI` is now a generated file in this plugin's own tree
		// (`wp/bin/build-admin-ui.php`), so the guard could only ever answer true — and a
		// guard that cannot fail is a branch nobody will ever test again.
		add_action( 'init', array( $this, 'register_with_framework' ), 5 );
		add_action( 'admin_post_zinn_translate_override', array( $this, 'save_override' ) );
		add_filter( 'zinn_diagnostics', array( $this, 'diagnostics' ) );
	}

	/**
	 * Declare the settings to the shared framework.
	 *
	 * @return void
	 */
	public function register_with_framework(): void {
		call_user_func(
			array( 'Zinn_Translate_Admin_UI', 'register' ),
			array(
				'title'      => __( 'Translate', 'zinn-translate' ),
				'option'     => Zinn_Translate_Options::OPTION,
				'capability' => 'manage_options',
				'position'   => 30,
				'connection' => array( 'Zinn_Translate_Status', 'status' ),
				'legacy'     => array(
					'site_id' => 'zinn_translate_site_id',
					'token'   => 'zinn_translate_token',
					'locales' => 'zinn_translate_locales',
				),
				'tabs'       => self::tabs(),
				'screens'    => array(
					array(
						'id'     => 'overrides',
						'title'  => __( 'Manual overrides', 'zinn-translate' ),
						'render' => array( $this, 'render_overrides' ),
					),
					array(
						'id'     => 'urls',
						'title'  => __( 'Sitemaps and URLs', 'zinn-translate' ),
						'render' => array( $this, 'render_urls' ),
					),
				),
			)
		);
		if ( class_exists( 'Zinn_Translate_Style_Presets' ) ) {
			call_user_func(
				array( 'Zinn_Translate_Style_Presets', 'register' ),
				array(
					'id'      => 'switcher',
					'presets' => self::style_presets(),
					'tokens'  => self::style_tokens(),
				)
			);
		}
	}

	/**
	 * The settings, as tabs of fields.
	 *
	 * @return array<string, array<string, mixed>> The tabs.
	 */
	private static function tabs(): array {
		$languages = array();
		foreach ( Zinn_Translate_Locales::all() as $code => $language ) {
			$languages[ $code ] = $language['native'] . ' — ' . $language['name'];
		}
		return array(
			'general'  => array(
				'title'  => __( 'General', 'zinn-translate' ),
				'fields' => array(
					array(
						'key'         => 'mode',
						'type'        => 'radio',
						'label'       => __( 'Who pays for the translation', 'zinn-translate' ),
						'description' => __( 'Either your Zinn Digital® plan, or an API key of your own. There is no free tier — somebody pays a provider for every word.', 'zinn-translate' ),
						'default'     => 'zinn',
						'choices'     => array(
							'zinn' => __( 'My Zinn Digital® plan', 'zinn-translate' ),
							'byo'  => __( 'My own provider key', 'zinn-translate' ),
						),
						'sanitize'    => 'key',
					),
					array(
						'key'         => 'site_id',
						'type'        => 'text',
						'label'       => __( 'Site ID', 'zinn-translate' ),
						'description' => __( 'From your Zinn Digital® dashboard, on this site\'s Translation tab.', 'zinn-translate' ),
						'show_if'     => array( 'mode' => 'zinn' ),
						'sanitize'    => 'text',
					),
					array(
						'key'      => 'token',
						'type'     => 'password',
						'label'    => __( 'Site token', 'zinn-translate' ),
						'secret'   => true,
						'show_if'  => array( 'mode' => 'zinn' ),
						'sanitize' => 'text',
					),
					array(
						'key'      => 'provider',
						'type'     => 'select',
						'label'    => __( 'Provider', 'zinn-translate' ),
						'choices'  => Zinn_Translate_Provider_Factory::choices(),
						// ⛔ Derived from the measured price table, never typed (§2.45).
						'default'  => Zinn_Translate_Provider_Factory::cheapest(),
						'show_if'  => array( 'mode' => 'byo' ),
						'sanitize' => 'key',
					),
					array(
						'key'         => 'provider_key',
						'type'        => 'password',
						'label'       => __( 'API key', 'zinn-translate' ),
						'description' => __( 'Your key, billed to you by the provider. It is stored on this site and sent only to them.', 'zinn-translate' ),
						'secret'      => true,
						'show_if'     => array( 'mode' => 'byo' ),
						'sanitize'    => 'text',
					),
					array(
						'key'         => 'provider_model',
						'type'        => 'text',
						'label'       => __( 'Model', 'zinn-translate' ),
						'description' => __( 'Leave empty for the cheapest model that does the job well.', 'zinn-translate' ),
						'show_if'     => array( 'mode' => 'byo' ),
						'sanitize'    => 'text',
					),
					array(
						'key'         => 'source_locale',
						'type'        => 'select',
						'label'       => __( 'This site is written in', 'zinn-translate' ),
						'description' => __( 'Leave empty to use the site language from WordPress settings.', 'zinn-translate' ),
						'choices'     => $languages,
						'sanitize'    => 'key',
					),
					array(
						'key'         => 'locales',
						'type'        => 'multiselect',
						'searchable'  => true,
						'label'       => __( 'Languages to publish', 'zinn-translate' ),
						'description' => __( 'Each one gets its own web addresses and its own sitemap. Publish only the languages you want indexed.', 'zinn-translate' ),
						'choices'     => $languages,
						'sanitize'    => 'key',
					),
					array(
						'key'         => 'auto_translate',
						'type'        => 'toggle',
						'label'       => __( 'Translate automatically when I publish or update', 'zinn-translate' ),
						'description' => __( 'Editing a page marks its translations out of date and they are remade in the background. Editing without changing any words costs nothing.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
				),
			),
			'content'  => array(
				'title'  => __( 'What gets translated', 'zinn-translate' ),
				'fields' => array(
					array(
						'key'      => 'post_types',
						'type'     => 'multiselect',
						'label'    => __( 'Post types', 'zinn-translate' ),
						'choices'  => self::post_type_choices(),
						// ⛔⛔ NOT a literal. This field's default is what `Admin_UI::all()`
						// fills an unsaved site with, and it therefore OVERRIDES
						// `Options::defaults()` on every install — so a literal here is not a
						// duplicate of the default, it IS the default, silently.
						'default'  => Zinn_Translate_Options::default_post_types(),
						'sanitize' => 'key',
					),
					array(
						'key'         => 'translate_slugs',
						'type'        => 'toggle',
						'label'       => __( 'Translate the web addresses too', 'zinn-translate' ),
						'description' => __( 'French visitors get /fr/a-propos/ rather than /fr/about/. The untranslated address keeps working and redirects, so nothing that was indexed breaks.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
					array(
						'key'      => 'translate_meta',
						'type'     => 'toggle',
						'label'    => __( 'Translate SEO titles, descriptions and image alt text', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'      => 'translate_terms',
						'type'     => 'toggle',
						'label'    => __( 'Translate categories and tags', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'      => 'translate_menus',
						'type'     => 'toggle',
						'label'    => __( 'Translate navigation menus', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'         => 'translate_woo',
						'type'        => 'toggle',
						'label'       => __( 'Translate WooCommerce products and attributes', 'zinn-translate' ),
						'description' => __( 'Short descriptions, purchase notes, attribute labels and variation descriptions. Prices, SKUs and stored option values are never touched.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
				),
			),
			'seo'      => array(
				'title'  => __( 'SEO', 'zinn-translate' ),
				'fields' => array(
					array(
						'key'         => 'hreflang',
						'type'        => 'toggle',
						'label'       => __( 'Add hreflang tags', 'zinn-translate' ),
						'description' => __( 'Tells search engines these pages are the same page in different languages. Leave this on unless another plugin is already doing it.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
					array(
						'key'      => 'sitemaps',
						'type'     => 'toggle',
						'label'    => __( 'Publish a sitemap for each language', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'         => 'llms_txt',
						'type'        => 'toggle',
						'label'       => __( 'Publish llms.txt for each language', 'zinn-translate' ),
						'description' => __( 'A plain-text map of the site that AI answer engines read. They often cannot run JavaScript and may never fetch a sitemap.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
				),
			),
			'switcher' => array(
				'title'  => __( 'Language switcher', 'zinn-translate' ),
				'fields' => array(
					array(
						'key'         => 'switcher_preset',
						'type'        => 'select',
						'label'       => __( 'Style', 'zinn-translate' ),
						'choices'     => Zinn_Translate_Switcher::presets(),
						'default'     => 'dropdown',
						'description' => __( 'Colours, spacing and corners are on the Styling tab. Place it with the block, the [zinn_language_switcher] shortcode, or a menu.', 'zinn-translate' ),
						'sanitize'    => 'key',
					),
					array(
						'key'         => 'switcher_locales',
						'type'        => 'multiselect',
						'searchable'  => true,
						'label'       => __( 'Show only these languages', 'zinn-translate' ),
						'description' => __( 'Leave empty to show every language you publish.', 'zinn-translate' ),
						'choices'     => $languages,
						'sanitize'    => 'key',
					),
					array(
						'key'         => 'switcher_native_names',
						'type'        => 'toggle',
						'label'       => __( 'Name each language in its own language', 'zinn-translate' ),
						'description' => __( 'Deutsch rather than German. Somebody who cannot read the current page cannot read "German" either.', 'zinn-translate' ),
						'default'     => true,
						'sanitize'    => 'bool',
					),
					array(
						'key'         => 'switcher_show_flags',
						'type'        => 'toggle',
						'label'       => __( 'Show flags', 'zinn-translate' ),
						'description' => __( 'Off by default: a language is not a country, and Arabic and Spanish have no single flag that does not exclude most of their speakers.', 'zinn-translate' ),
						'default'     => false,
						'sanitize'    => 'bool',
					),
					array(
						'key'      => 'switcher_show_current',
						'type'     => 'toggle',
						'label'    => __( 'Include the language being read', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'      => 'switcher_show_source',
						'type'     => 'toggle',
						'label'    => __( 'Include the original language', 'zinn-translate' ),
						'default'  => true,
						'sanitize' => 'bool',
					),
					array(
						'key'         => 'switcher_menu',
						'type'        => 'select',
						'label'       => __( 'Add it to this menu', 'zinn-translate' ),
						'description' => __( 'Only the menu you name here. Nothing is added to any other menu on the site.', 'zinn-translate' ),
						'choices'     => self::menu_choices(),
						'sanitize'    => 'key',
					),
				),
			),
		);
	}

	/**
	 * The switcher's style presets, as tokens for the shared preset mechanism.
	 *
	 * @return array<string, array<string, mixed>> Presets.
	 */
	private static function style_presets(): array {
		$presets = array();
		$looks   = array(
			'dropdown' => array( '#1d4ed8', '#ffffff', '#111827', '6px', '0.35rem', '0.9rem' ),
			'inline'   => array( '#1d4ed8', 'transparent', '#111827', '0px', '0.75rem', '0.9rem' ),
			'pill'     => array( '#1d4ed8', '#eef2ff', '#1e3a8a', '999px', '0.4rem', '0.85rem' ),
			'minimal'  => array( '#111827', 'transparent', '#4b5563', '0px', '0.5rem', '0.8rem' ),
			'flags'    => array( '#1d4ed8', '#ffffff', '#111827', '8px', '0.5rem', '0.9rem' ),
			'sidebar'  => array( '#1d4ed8', 'transparent', '#111827', '4px', '0.25rem', '0.95rem' ),
		);
		foreach ( Zinn_Translate_Switcher::presets() as $id => $label ) {
			$look           = $looks[ $id ] ?? $looks['dropdown'];
			$presets[ $id ] = array(
				'label'  => $label,
				'tokens' => array(
					'accent'    => $look[0],
					'surface'   => $look[1],
					'text'      => $look[2],
					'radius'    => $look[3],
					'gap'       => $look[4],
					'font-size' => $look[5],
				),
			);
		}
		return $presets;
	}

	/**
	 * The tokens a customer may override on any preset.
	 *
	 * @return array<string, array<string, string>> Tokens.
	 */
	private static function style_tokens(): array {
		return array(
			'accent'    => array(
				'type'  => 'color',
				'label' => __( 'Accent', 'zinn-translate' ),
			),
			'surface'   => array(
				'type'  => 'color',
				'label' => __( 'Background', 'zinn-translate' ),
			),
			'text'      => array(
				'type'  => 'color',
				'label' => __( 'Text', 'zinn-translate' ),
			),
			'radius'    => array(
				'type'  => 'text',
				'label' => __( 'Corner radius', 'zinn-translate' ),
			),
			'gap'       => array(
				'type'  => 'text',
				'label' => __( 'Spacing', 'zinn-translate' ),
			),
			'font-size' => array(
				'type'  => 'text',
				'label' => __( 'Text size', 'zinn-translate' ),
			),
		);
	}

	/**
	 * Public post types a customer can choose to translate.
	 *
	 * @return array<string, string> Post type => label.
	 */
	private static function post_type_choices(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'product_variation' ), true ) ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->name ?? $type->name;
		}
		return $out;
	}

	/**
	 * The theme's registered menu locations.
	 *
	 * @return array<string, string> Location => label.
	 */
	private static function menu_choices(): array {
		$out = array( '' => __( 'Do not add it to a menu', 'zinn-translate' ) );
		foreach ( (array) get_registered_nav_menus() as $location => $label ) {
			$out[ (string) $location ] = (string) $label;
		}
		return $out;
	}

	/**
	 * What this plugin contributes to the shared diagnostics report.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @return array<string, mixed> The report with our section added.
	 */
	public function diagnostics( $report ): array {
		$report                   = (array) $report;
		$failure                  = Zinn_Translate_Status::last();
		$report['zinn-translate'] = array(
			'version'      => ZINN_TRANSLATE_VERSION,
			'mode'         => Zinn_Translate_Options::text( 'mode' ),
			'connected'    => Zinn_Translate_Options::is_connected() ? 'yes' : 'no',
			'source'       => Zinn_Translate_Options::source_locale(),
			'locales'      => implode( ', ', Zinn_Translate_Options::locales() ),
			'seo_plugin'   => Zinn_Translate_SEO::detected_label(),
			'rows'         => Zinn_Translate_Store::counts(),
			// ⛔ The KIND of the last failure, never the message. A message can carry a
			// truncated key or a URL with a token in it, and a diagnostics report is
			// something a customer pastes into a support ticket.
			'last_failure' => null === $failure ? '' : $failure['kind'],
		);
		return $report;
	}

	/**
	 * The manual-override screen.
	 *
	 * ⭐⭐ **An override is the feature that makes the rest trustworthy.** Machine translation
	 * gets a product name, a brand or a turn of phrase wrong sooner or later, and a customer
	 * who cannot fix it has to turn the whole thing off. Overrides are stored with their own
	 * status and the writer refuses to overwrite one, so a correction made once survives
	 * every future re-translation — which is the only version of this feature worth having.
	 *
	 * @return void
	 */
	public function render_overrides(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters on a screen the capability check above already guards; no state is changed.
		$locale = isset( $_GET['zinn_locale'] ) ? sanitize_key( wp_unslash( $_GET['zinn_locale'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$rows   = Zinn_Translate_Store::browse( $locale, $search, 50, 0 );
		?>
		<p>
			<?php
			esc_html_e(
				'Edit any translation here. An edited translation is kept exactly as you wrote it and is never overwritten when the page is translated again.',
				'zinn-translate'
			);
			?>
		</p>
		<form method="get">
			<?php
			foreach ( array( 'page', 'tab', 'screen' ) as $carry ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Carrying the current screen through a GET filter form.
				if ( isset( $_GET[ $carry ] ) ) {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $carry ),
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
						esc_attr( sanitize_text_field( wp_unslash( $_GET[ $carry ] ) ) )
					);
				}
			}
			?>
			<select name="zinn_locale">
				<option value=""><?php esc_html_e( 'Every language', 'zinn-translate' ); ?></option>
				<?php foreach ( Zinn_Translate_Options::locales() as $code ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $locale, $code ); ?>>
						<?php echo esc_html( Zinn_Translate_Locales::name( $code ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search translations', 'zinn-translate' ); ?>" />
			<?php submit_button( __( 'Filter', 'zinn-translate' ), 'secondary', '', false ); ?>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Content', 'zinn-translate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Language', 'zinn-translate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Translation', 'zinn-translate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'zinn-translate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Save', 'zinn-translate' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $rows ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing translated yet. Publish a language and translation starts in the background.', 'zinn-translate' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'zinn_translate_override' ); ?>
						<input type="hidden" name="action" value="zinn_translate_override" />
						<input type="hidden" name="object_ref" value="<?php echo esc_attr( $row['object_ref'] ); ?>" />
						<input type="hidden" name="field" value="<?php echo esc_attr( $row['field'] ); ?>" />
						<input type="hidden" name="locale" value="<?php echo esc_attr( $row['locale'] ); ?>" />
						<td><code><?php echo esc_html( $row['object_ref'] . ' · ' . $row['field'] ); ?></code></td>
						<td><?php echo esc_html( Zinn_Translate_Locales::name( $row['locale'] ) ); ?></td>
						<td><textarea name="text" rows="3" class="large-text"><?php echo esc_textarea( $row['text'] ); ?></textarea></td>
						<td><?php echo esc_html( self::status_label( $row['status'] ) ); ?></td>
						<td><?php submit_button( __( 'Save', 'zinn-translate' ), 'secondary', '', false ); ?></td>
					</form>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The sitemap and llms.txt addresses, so the owner can check them.
	 *
	 * @return void
	 */
	public function render_urls(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<p><?php esc_html_e( 'These addresses are live. Open any of them to check it, and submit the sitemap index to Google Search Console.', 'zinn-translate' ); ?></p>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( Zinn_Translate_Sitemap::urls() as $label => $url ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save one manual override.
	 *
	 * @return void
	 */
	public function save_override(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to edit translations on this site.', 'zinn-translate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zinn_translate_override' );
		$ref    = isset( $_POST['object_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['object_ref'] ) ) : '';
		$field  = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
		$locale = isset( $_POST['locale'] ) ? sanitize_key( wp_unslash( $_POST['locale'] ) ) : '';
		// ⛔ `wp_kses_post`, not `sanitize_text_field`: a body override is legitimately HTML,
		// and stripping it would silently destroy the customer's formatting the moment they
		// corrected a single word in a paragraph.
		$text = isset( $_POST['text'] ) ? wp_kses_post( wp_unslash( $_POST['text'] ) ) : '';

		if ( '' !== $ref && '' !== $field && Zinn_Translate_Locales::known( $locale ) ) {
			Zinn_Translate_Store::put( $ref, $field, $locale, $text, '', true );
			Zinn_Translate_Router::flush();
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * A human label for a stored row's status.
	 *
	 * @param string $status One of the store's status constants.
	 * @return string The label.
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case Zinn_Translate_Store::STATUS_OVERRIDE:
				return __( 'Edited by you — kept', 'zinn-translate' );
			case Zinn_Translate_Store::STATUS_STALE:
				return __( 'Source changed — will be redone', 'zinn-translate' );
			default:
				return __( 'Up to date', 'zinn-translate' );
		}
	}
}

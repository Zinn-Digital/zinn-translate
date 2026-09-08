<?php
/**
 * The language switcher as a classic sidebar widget.
 *
 * @package ZinnTranslate
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A sidebar widget wrapping the shared switcher renderer.
 *
 * ⛔ It exists alongside the block because a great many live sites run classic themes and
 * classic widget areas, and telling those customers to convert their sidebar to blocks in
 * order to show a language picker is not an answer. It renders through
 * :meth:`Zinn_Translate_Switcher::render`, so it is the same control everywhere.
 */
final class Zinn_Translate_Switcher_Widget extends WP_Widget {

	/**
	 * Register the widget with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'zinn_translate_switcher',
			__( 'Zinn® language switcher', 'zinn-translate' ),
			array( 'description' => __( 'Let visitors read this page in another language.', 'zinn-translate' ) )
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array<string, string> $args     Theme wrapper markup.
	 * @param array<string, string> $instance The widget's saved settings.
	 * @return void
	 */
	public function widget( $args, $instance ): void {
		$switcher = Zinn_Translate_Switcher::render(
			array( 'preset' => (string) ( $instance['preset'] ?? '' ) )
		);
		if ( '' === $switcher ) {
			return;
		}
		// ⛔ `$args` is theme-supplied wrapper markup that WordPress itself passes through
		// unescaped; escaping it here would print the theme's `<div>` as visible text.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied wrapper markup, per the widget API contract.
		echo $args['before_widget'] ?? '';
		if ( '' !== trim( (string) ( $instance['title'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied wrapper markup.
			echo $args['before_title'] ?? '';
			echo esc_html( apply_filters( 'widget_title', $instance['title'] ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied wrapper markup.
			echo $args['after_title'] ?? '';
		}
		// ⛔ `wp_kses_post` rather than raw: the markup is ours and built from escaped
		// values, but a widget renders on every page of the site and "it is our own markup"
		// is the reasoning that eventually prints something that is not.
		echo wp_kses_post( $switcher );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-supplied wrapper markup.
		echo $args['after_widget'] ?? '';
	}

	/**
	 * The widget's settings form.
	 *
	 * @param array<string, string> $instance The saved settings.
	 * @return void
	 */
	public function form( $instance ): void {
		$title  = (string) ( $instance['title'] ?? '' );
		$preset = (string) ( $instance['preset'] ?? '' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'zinn-translate' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'preset' ) ); ?>"><?php esc_html_e( 'Style', 'zinn-translate' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'preset' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'preset' ) ); ?>">
				<option value=""><?php esc_html_e( 'Use the plugin setting', 'zinn-translate' ); ?></option>
				<?php foreach ( Zinn_Translate_Switcher::presets() as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $preset ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitise the widget's settings.
	 *
	 * @param array<string, string> $new_instance The submitted settings.
	 * @param array<string, string> $old_instance The previous settings.
	 * @return array<string, string> The settings to store.
	 */
	public function update( $new_instance, $old_instance ): array {
		unset( $old_instance );
		$preset = sanitize_key( (string) ( $new_instance['preset'] ?? '' ) );
		return array(
			'title'  => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'preset' => isset( Zinn_Translate_Switcher::presets()[ $preset ] ) ? $preset : '',
		);
	}
}

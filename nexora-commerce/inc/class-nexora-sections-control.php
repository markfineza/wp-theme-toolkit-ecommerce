<?php
/**
 * Customizer control: toggle and reorder homepage sections with keyboard-accessible buttons.
 *
 * Stored value is a comma-separated list of section keys; disabled keys are prefixed with "-".
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sortable checklist control.
 */
class Nexora_Sections_Control extends WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'nexora-sections';

	/**
	 * Enqueue control script.
	 */
	public function enqueue() {
		wp_enqueue_script( 'nexora-customizer-controls', NEXORA_URI . '/assets/js/customizer-controls.js', array( 'customize-controls' ), NEXORA_VERSION, true );
		wp_add_inline_style( 'customize-controls', '.nexora-sections{margin:0}.nexora-sections li{display:flex;align-items:center;gap:6px;padding:6px 8px;margin:0 0 4px;background:#fff;border:1px solid #dcdcde;border-radius:4px}.nexora-sections label{flex:1}.nexora-sections button{min-width:30px}' );
	}

	/**
	 * Render the control.
	 */
	public function render_content() {
		$tokens = explode( ',', nexora_sanitize_sections( $this->value() ) );
		?>
		<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
		<?php if ( $this->description ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( $this->description ); ?></span>
		<?php endif; ?>
		<input type="hidden" class="nexora-sections-value" <?php $this->link(); ?> value="<?php echo esc_attr( implode( ',', $tokens ) ); ?>">
		<ul class="nexora-sections">
			<?php
			foreach ( $tokens as $token ) :
				$key = nexora_section_key( $token );
				if ( ! isset( $this->choices[ $key ] ) ) {
					continue;
				}
				$label = $this->choices[ $key ];
				?>
				<li data-key="<?php echo esc_attr( $key ); ?>">
					<label><input type="checkbox" <?php checked( '-' !== $token[0] ); ?>> <?php echo esc_html( $label ); ?></label>
					<button type="button" class="button button-small nexora-up" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: section name */ __( 'Move %s up', 'nexora-commerce' ), $label ) ); ?>">↑</button>
					<button type="button" class="button button-small nexora-down" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: section name */ __( 'Move %s down', 'nexora-commerce' ), $label ) ); ?>">↓</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}

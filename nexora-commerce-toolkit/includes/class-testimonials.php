<?php
/**
 * Testimonials post type (rendered by the theme's "Reviews and testimonials" homepage section).
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Testimonials.
 */
class Nexora_Toolkit_Testimonials {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_nexora_testimonial', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register post type and meta.
	 */
	public static function register() {
		register_post_type(
			'nexora_testimonial',
			array(
				'labels'        => array(
					'name'          => __( 'Testimonials', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Testimonial', 'nexora-commerce-toolkit' ),
					'add_new_item'  => __( 'Add testimonial', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Edit testimonial', 'nexora-commerce-toolkit' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-format-quote',
				'menu_position' => 58,
				'supports'      => array( 'title', 'editor' ),
				'description'   => __( 'Use genuine customer testimonials only, with the customer’s permission.', 'nexora-commerce-toolkit' ),
			)
		);
		foreach ( array( 'nexora_role' => 'string', 'nexora_rating' => 'integer' ) as $key => $type ) {
			register_post_meta(
				'nexora_testimonial',
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'integer' === $type ? 'absint' : 'sanitize_text_field',
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Meta box.
	 */
	public static function meta_box() {
		add_meta_box( 'nexora-testimonial', __( 'Testimonial details', 'nexora-commerce-toolkit' ), array( __CLASS__, 'render' ), 'nexora_testimonial', 'side' );
	}

	/**
	 * Meta box UI.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'nexora_testimonial', 'nexora_testimonial_nonce' );
		?>
		<p><label for="nexora_role"><?php esc_html_e( 'Location or role', 'nexora-commerce-toolkit' ); ?></label>
		<input type="text" class="widefat" id="nexora_role" name="nexora_role" value="<?php echo esc_attr( get_post_meta( $post->ID, 'nexora_role', true ) ); ?>"></p>
		<p><label for="nexora_rating"><?php esc_html_e( 'Rating (1–5, optional)', 'nexora-commerce-toolkit' ); ?></label>
		<input type="number" min="0" max="5" class="small-text" id="nexora_rating" name="nexora_rating" value="<?php echo esc_attr( get_post_meta( $post->ID, 'nexora_rating', true ) ); ?>"></p>
		<p class="description"><?php esc_html_e( 'The customer name is the title; the quote is the content.', 'nexora-commerce-toolkit' ); ?></p>
		<?php
	}

	/**
	 * Save meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_testimonial_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nexora_testimonial_nonce'] ), 'nexora_testimonial' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['nexora_role'] ) ) {
			update_post_meta( $post_id, 'nexora_role', sanitize_text_field( wp_unslash( $_POST['nexora_role'] ) ) );
		}
		if ( isset( $_POST['nexora_rating'] ) ) {
			update_post_meta( $post_id, 'nexora_rating', min( 5, absint( $_POST['nexora_rating'] ) ) );
		}
	}
}

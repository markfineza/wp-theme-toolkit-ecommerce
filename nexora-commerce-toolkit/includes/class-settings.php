<?php
/**
 * Settings → Freshtastic Toolkit.
 *
 * Secrets (API keys) are write-only in the UI: saved values are never printed back into the page,
 * and wp-config.php constants take precedence so keys can be kept out of the database entirely.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage and admin screen.
 */
class Nexora_Toolkit_Settings {

	const OPTION = 'nexora_toolkit_settings';

	/**
	 * Secret fields and the wp-config.php constant that overrides each one.
	 *
	 * @var array<string,string>
	 */
	const SECRETS = array(
		'mailchimp_api_key'   => 'NEXORA_MAILCHIMP_API_KEY',
		'brevo_api_key'       => 'NEXORA_BREVO_API_KEY',
		'klaviyo_private_key' => 'NEXORA_KLAVIYO_PRIVATE_KEY',
	);

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEXORA_TOOLKIT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'newsletter_provider'   => 'local',
			'mailchimp_api_key'     => '',
			'mailchimp_list_id'     => '',
			'mailchimp_double_optin' => true,
			'brevo_api_key'         => '',
			'brevo_list_id'         => '',
			'klaviyo_private_key'   => '',
			'klaviyo_list_id'       => '',
			'newsletter_success'    => '', // Translated on read (see get()); defaults() runs before translations load.
			'wishlist_enabled'      => true,
			'points_enabled'        => true,
			'ga4_id'                => '',
			'gtm_id'                => '',
			'meta_pixel_id'         => '',
			'google_ads_id'         => '',
			'google_ads_label'      => '',
			'gsc_verification'      => '',
			'consent_mode'          => 'basic',
			'tracking_force'        => false,
			'demo_labels'           => true,
			'rfq_enabled'           => true,
			'rfq_email'             => '',
			'orders_modern'         => true,
			'order_desk'            => true,
			'order_desk_name'       => '',
		);
	}

	/**
	 * Get a setting (secrets resolve wp-config constants first).
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( isset( self::SECRETS[ $key ] ) && defined( self::SECRETS[ $key ] ) ) {
			return (string) constant( self::SECRETS[ $key ] );
		}
		$all      = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( 'newsletter_success' === $key && '' === (string) $all[ $key ] ) {
			return __( 'Thanks! Please check your inbox to confirm your subscription.', 'nexora-commerce-toolkit' );
		}
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Toolkit', 'nexora-commerce-toolkit' ), __( 'Freshtastic Toolkit', 'nexora-commerce-toolkit' ), 'manage_options', 'nexora-toolkit', array( __CLASS__, 'render' ) );
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=nexora-toolkit' ) ) . '">' . esc_html__( 'Settings', 'nexora-commerce-toolkit' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'tools.php?page=nexora-demo' ) ) . '">' . esc_html__( 'Demo content', 'nexora-commerce-toolkit' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'nexora_toolkit',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings. Empty secret inputs keep the stored value; the "clear" checkbox removes it.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$out      = self::defaults();
		$booleans = array( 'mailchimp_double_optin', 'wishlist_enabled', 'points_enabled', 'tracking_force', 'demo_labels', 'rfq_enabled', 'orders_modern', 'order_desk' );

		foreach ( $out as $key => $default ) {
			if ( in_array( $key, $booleans, true ) ) {
				$out[ $key ] = ! empty( $input[ $key ] );
			} elseif ( isset( self::SECRETS[ $key ] ) ) {
				$value       = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
				$out[ $key ] = '' !== $value ? $value : $current[ $key ];
				if ( ! empty( $input[ $key . '_clear' ] ) ) {
					$out[ $key ] = '';
				}
			} elseif ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		$out['newsletter_provider'] = in_array( $out['newsletter_provider'], array( 'none', 'local', 'mailchimp', 'brevo', 'klaviyo' ), true ) ? $out['newsletter_provider'] : 'local';
		$out['consent_mode']        = in_array( $out['consent_mode'], array( 'none', 'basic', 'wp_consent_api' ), true ) ? $out['consent_mode'] : 'basic';
		$out['ga4_id']              = preg_match( '/^G-[A-Z0-9]+$/i', $out['ga4_id'] ) ? strtoupper( $out['ga4_id'] ) : '';
		$out['gtm_id']              = preg_match( '/^GTM-[A-Z0-9]+$/i', $out['gtm_id'] ) ? strtoupper( $out['gtm_id'] ) : '';
		$out['meta_pixel_id']       = preg_match( '/^\d{6,20}$/', $out['meta_pixel_id'] ) ? $out['meta_pixel_id'] : '';
		$out['google_ads_id']       = preg_match( '/^AW-\d+$/i', $out['google_ads_id'] ) ? strtoupper( $out['google_ads_id'] ) : '';
		$out['google_ads_label']    = preg_replace( '/[^A-Za-z0-9_-]/', '', $out['google_ads_label'] );
		$out['gsc_verification']    = preg_replace( '/[^A-Za-z0-9_-]/', '', $out['gsc_verification'] );
		$out['mailchimp_list_id']   = preg_replace( '/[^A-Za-z0-9]/', '', $out['mailchimp_list_id'] );
		$out['brevo_list_id']       = absint( $out['brevo_list_id'] ) ? (string) absint( $out['brevo_list_id'] ) : '';
		$out['klaviyo_list_id']     = preg_replace( '/[^A-Za-z0-9]/', '', $out['klaviyo_list_id'] );
		$out['rfq_email']           = is_email( $out['rfq_email'] ) ? sanitize_email( $out['rfq_email'] ) : '';

		return $out;
	}

	/**
	 * Render a field row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $type  text|checkbox|select|secret.
	 * @param array  $args  choices, help, placeholder.
	 */
	private static function field( $key, $label, $type = 'text', $args = array() ) {
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'nexora-' . $key;
		$value = self::get( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		switch ( $type ) {
			case 'checkbox':
				printf( '<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $args['choices'] as $k => $v ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $k ), selected( $value, $k, false ), esc_html( $v ) );
				}
				echo '</select>';
				break;
			case 'secret':
				$constant = self::SECRETS[ $key ];
				if ( defined( $constant ) ) {
					/* translators: %s: constant name */
					echo '<p><span class="dashicons dashicons-lock"></span> ' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'nexora-commerce-toolkit' ), $constant ) ) . '</p>';
					break;
				}
				printf(
					'<input type="password" class="regular-text" id="%1$s" name="%2$s" value="" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ? __( '•••••••• saved — leave blank to keep', 'nexora-commerce-toolkit' ) : '' )
				);
				if ( $value ) {
					printf( ' <label><input type="checkbox" name="%1$s" value="1"> %2$s</label>', esc_attr( self::OPTION . '[' . $key . '_clear]' ), esc_html__( 'Remove saved key', 'nexora-commerce-toolkit' ) );
				}
				/* translators: %s: constant name */
				echo '<p class="description">' . esc_html( sprintf( __( 'Recommended: define %s in wp-config.php instead of saving the key here.', 'nexora-commerce-toolkit' ), $constant ) ) . '</p>';
				break;
			default:
				printf( '<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ) );
		}
		if ( ! empty( $args['help'] ) ) {
			echo '<p class="description">' . wp_kses( $args['help'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'code' => array(), 'strong' => array() ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkout page status with switch / restore buttons (outside the settings form).
	 */
	private static function render_checkout_mode() {
		if ( ! class_exists( 'Nexora_Toolkit_Fulfilment' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}
		$key    = 'nexora_checkout_mode_result_' . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		$block  = Nexora_Toolkit_Fulfilment::checkout_uses_block();
		$backup = get_post_meta( wc_get_page_id( 'checkout' ), Nexora_Toolkit_Fulfilment::BACKUP, true );
		$url    = static function ( $mode ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=nexora_checkout_mode&mode=' . $mode ), 'nexora_checkout_mode' );
		};
		?>
		<h2><?php esc_html_e( 'Checkout page', 'nexora-commerce-toolkit' ); ?></h2>
		<?php if ( $result ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $result ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php
			echo esc_html(
				$block
					? __( 'Current: WooCommerce Checkout block. Courier and pickup scheduling and the Freshtastic checkout layout are not shown.', 'nexora-commerce-toolkit' )
					: __( 'Current: classic checkout (Freshtastic checkout layout with courier and pickup scheduling).', 'nexora-commerce-toolkit' )
			);
			?>
		</p>
		<p>
			<?php if ( $block ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $url( 'classic' ) ); ?>"><?php esc_html_e( 'Use the Freshtastic checkout', 'nexora-commerce-toolkit' ); ?></a>
			<?php elseif ( $backup ) : ?>
				<a class="button" href="<?php echo esc_url( $url( 'block' ) ); ?>"><?php esc_html_e( 'Restore the block checkout', 'nexora-commerce-toolkit' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conflicts = class_exists( 'Nexora_Toolkit_Tracking' ) ? Nexora_Toolkit_Tracking::detected_plugins() : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Freshtastic Toolkit', 'nexora-commerce-toolkit' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_toolkit' ); ?>

				<h2><?php esc_html_e( 'Newsletter', 'nexora-commerce-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Signup forms in the theme post to this site; the server forwards the address to your provider, so API keys are never exposed to visitors. Consent is required and never pre-ticked.', 'nexora-commerce-toolkit' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						'newsletter_provider',
						__( 'Provider', 'nexora-commerce-toolkit' ),
						'select',
						array(
							'choices' => array(
								'local'     => __( 'Store on this site only (export later)', 'nexora-commerce-toolkit' ),
								'mailchimp' => 'Mailchimp',
								'brevo'     => 'Brevo',
								'klaviyo'   => 'Klaviyo',
								'none'      => __( 'Disabled (hide signup forms)', 'nexora-commerce-toolkit' ),
							),
							'help'    => __( 'Every signup is also kept on this site (Users → Subscribers) with its consent timestamp, so nothing is lost if a provider call fails.', 'nexora-commerce-toolkit' ),
						)
					);
					self::field( 'mailchimp_api_key', __( 'Mailchimp API key', 'nexora-commerce-toolkit' ), 'secret' );
					self::field( 'mailchimp_list_id', __( 'Mailchimp audience ID', 'nexora-commerce-toolkit' ) );
					self::field( 'mailchimp_double_optin', __( 'Mailchimp double opt-in', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Recommended. New contacts are added as “pending” and Mailchimp sends a confirmation email.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'brevo_api_key', __( 'Brevo API key', 'nexora-commerce-toolkit' ), 'secret' );
					self::field( 'brevo_list_id', __( 'Brevo list ID', 'nexora-commerce-toolkit' ) );
					self::field( 'klaviyo_private_key', __( 'Klaviyo private API key', 'nexora-commerce-toolkit' ), 'secret' );
					self::field( 'klaviyo_list_id', __( 'Klaviyo list ID', 'nexora-commerce-toolkit' ) );
					self::field( 'newsletter_success', __( 'Success message', 'nexora-commerce-toolkit' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Analytics and conversion tracking', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $conflicts ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						/* translators: %s: plugin names */
						echo esc_html( sprintf( __( 'Detected: %s. To avoid duplicate events, Freshtastic skips the channels those plugins already handle. Tick “Send events anyway” only if you have disabled their tracking.', 'nexora-commerce-toolkit' ), implode( ', ', $conflicts ) ) );
						?>
					</p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'gtm_id', __( 'Google Tag Manager container ID', 'nexora-commerce-toolkit' ), 'text', array( 'placeholder' => 'GTM-XXXXXXX', 'help' => __( 'If set, ecommerce events are pushed to the dataLayer for your GTM tags. Leave GA4 below empty when GA4 is configured inside GTM.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'ga4_id', __( 'GA4 measurement ID', 'nexora-commerce-toolkit' ), 'text', array( 'placeholder' => 'G-XXXXXXXXXX' ) );
					self::field( 'google_ads_id', __( 'Google Ads conversion ID', 'nexora-commerce-toolkit' ), 'text', array( 'placeholder' => 'AW-123456789' ) );
					self::field( 'google_ads_label', __( 'Google Ads purchase conversion label', 'nexora-commerce-toolkit' ) );
					self::field( 'meta_pixel_id', __( 'Meta Pixel ID', 'nexora-commerce-toolkit' ) );
					self::field( 'gsc_verification', __( 'Google Search Console verification code', 'nexora-commerce-toolkit' ), 'text', array( 'help' => __( 'Only the content value of the HTML tag. Skip this if your SEO plugin already verifies the site.', 'nexora-commerce-toolkit' ) ) );
					self::field(
						'consent_mode',
						__( 'Consent handling', 'nexora-commerce-toolkit' ),
						'select',
						array(
							'choices' => array(
								'basic'          => __( 'Google Consent Mode v2: denied until a consent tool grants it (recommended)', 'nexora-commerce-toolkit' ),
								'wp_consent_api' => __( 'WP Consent API (requires a compatible consent plugin)', 'nexora-commerce-toolkit' ),
								'none'           => __( 'No consent gating (only where legally permitted)', 'nexora-commerce-toolkit' ),
							),
							'help'    => __( 'Your consent banner must update consent (gtag “consent update”, or the WP Consent API). With “denied” defaults, Meta Pixel waits for marketing consent.', 'nexora-commerce-toolkit' ),
						)
					);
					self::field( 'tracking_force', __( 'Send events anyway', 'nexora-commerce-toolkit' ), 'checkbox' );
					?>
				</table>

				<h2><?php esc_html_e( 'Store features', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'rfq_enabled', __( 'Request for quotation', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Lets shoppers request items that are not listed, out of stock or needed in bulk. Requests appear under WooCommerce → Quotations.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'rfq_email', __( 'Send quotation requests to', 'nexora-commerce-toolkit' ), 'text', array( 'placeholder' => get_option( 'admin_email' ), 'help' => __( 'Leave empty to use the site admin email.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'wishlist_enabled', __( 'Built-in wishlist', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Untick if you use another wishlist plugin (for example YITH WooCommerce Wishlist).', 'nexora-commerce-toolkit' ) ) );
					self::field( 'points_enabled', __( 'Points page in customer accounts', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Nothing awards points yet, so customers see a balance of 0 and "coming soon". Untick to hide the page until points go live.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'demo_labels', __( 'Label sample content', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Shows “Sample review” / “Demo product” labels on content created by the demo importer. Keep this on until demo content is removed.', 'nexora-commerce-toolkit' ) ) );
					?>
				</table>
				<h2 id="nexora-orders"><?php esc_html_e( 'Orders and checkout', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'orders_modern', __( 'Modern order screens', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Order summary tiles, fulfilment column and filters, status pills and an order progress bar on WooCommerce → Orders.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'order_desk', __( 'Order Desk mode', 'nexora-commerce-toolkit' ), 'checkbox', array( 'help' => __( 'Staff with the “Order Desk” role (Users → Add New → Role) see only Orders, Quotations and their profile, and land on the order list after logging in.', 'nexora-commerce-toolkit' ) ) );
					self::field( 'order_desk_name', __( 'Order Desk name', 'nexora-commerce-toolkit' ), 'text', array( 'placeholder' => __( 'Order Desk', 'nexora-commerce-toolkit' ) ) );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php Nexora_Store_Admin::render_engine_box(); ?>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<?php self::render_checkout_mode(); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}

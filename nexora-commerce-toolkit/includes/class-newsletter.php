<?php
/**
 * Newsletter signups.
 *
 * Flow: theme form → POST /wp-json/nexora/v1/subscribe (or admin-post.php without JS) →
 * validate (consent required, honeypot, rate limit) → store a local subscriber record with the
 * consent timestamp → forward to the configured provider server-side.
 *
 * The public form deliberately has no nonce: pages with forms are usually cached, and stale nonces
 * would reject genuine signups. Abuse is limited by the honeypot, per-IP rate limiting and the
 * provider's double opt-in.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter module.
 */
class Nexora_Toolkit_Newsletter {

	const CPT = 'nexora_subscriber';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_nopriv_nexora_subscribe', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_nexora_subscribe', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_nexora_export_subscribers', array( __CLASS__, 'export_csv' ) );
		add_action( 'wp_footer', array( __CLASS__, 'fallback_notice' ) );
		add_filter( 'nexora_newsletter_enabled', array( __CLASS__, 'enabled' ) );
		add_filter( 'nexora_script_data', array( __CLASS__, 'script_data' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'list_notice' ) );
	}

	/**
	 * Whether signup forms should render.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'none' !== Nexora_Toolkit_Settings::get( 'newsletter_provider' );
	}

	/**
	 * Give the theme's script the endpoint URL.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function script_data( $data ) {
		if ( self::enabled() ) {
			$data['newsletterUrl'] = esc_url_raw( rest_url( 'nexora/v1/subscribe' ) );
		}
		return $data;
	}

	/**
	 * Private subscriber records (Users → Subscribers).
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Newsletter subscribers', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Subscriber', 'nexora-commerce-toolkit' ),
					'menu_name'     => __( 'Subscribers', 'nexora-commerce-toolkit' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'users.php',
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'nexora/v1',
			'/subscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_subscribe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_subscribe( $request ) {
		$result = self::subscribe( $request->get_params() );
		return new WP_REST_Response( array( 'message' => $result['message'] ), $result['ok'] ? 200 : $result['status'] );
	}

	/**
	 * No-JS fallback handler.
	 */
	public static function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public signup form; see class docblock.
		$result   = self::subscribe( wp_unslash( $_POST ) );
		$referer  = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'nexora_newsletter', $result['ok'] ? 'ok' : 'error', $referer ) );
		exit;
	}

	/**
	 * Message for the no-JS fallback redirect.
	 */
	public static function fallback_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$state = isset( $_GET['nexora_newsletter'] ) ? sanitize_key( $_GET['nexora_newsletter'] ) : '';
		if ( ! $state ) {
			return;
		}
		$message = 'ok' === $state ? Nexora_Toolkit_Settings::get( 'newsletter_success' ) : __( 'Sorry, we could not complete your signup. Please check your email address and consent, then try again.', 'nexora-commerce-toolkit' );
		printf( '<div class="nx-toast-region" role="status"><div class="nx-toast%1$s"><p class="nx-toast__msg">%2$s</p></div></div>', 'ok' === $state ? '' : ' nx-toast--error', esc_html( $message ) );
	}

	/**
	 * Validate, store and forward a signup.
	 *
	 * @param array $params Params: email, consent, website (honeypot), source.
	 * @return array{ok:bool,status:int,message:string}
	 */
	public static function subscribe( $params ) {
		$fail = static function ( $message, $status = 400 ) {
			return array( 'ok' => false, 'status' => $status, 'message' => $message );
		};

		if ( ! self::enabled() ) {
			return $fail( __( 'Newsletter signups are currently closed.', 'nexora-commerce-toolkit' ), 403 );
		}
		// Honeypot: pretend success so bots learn nothing.
		if ( ! empty( $params['website'] ) ) {
			return array( 'ok' => true, 'status' => 200, 'message' => Nexora_Toolkit_Settings::get( 'newsletter_success' ) );
		}
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return $fail( __( 'Please enter a valid email address.', 'nexora-commerce-toolkit' ) );
		}
		if ( empty( $params['consent'] ) ) {
			return $fail( __( 'Please confirm that you agree to receive marketing emails.', 'nexora-commerce-toolkit' ) );
		}
		if ( ! self::rate_limit_ok() ) {
			return $fail( __( 'Too many attempts. Please wait a few minutes and try again.', 'nexora-commerce-toolkit' ), 429 );
		}

		$source = isset( $params['source'] ) ? sanitize_key( (string) $params['source'] ) : '';
		$id     = self::store_local( $email, $source );
		$sent   = self::forward( $email );
		if ( $id ) {
			update_post_meta( $id, '_nexora_provider_status', $sent );
		}

		do_action( 'nexora_newsletter_subscribed', $email, $source, $sent );

		return array( 'ok' => true, 'status' => 200, 'message' => Nexora_Toolkit_Settings::get( 'newsletter_success' ) );
	}

	/**
	 * Allow 5 attempts per IP per 10 minutes. The IP is stored only as a salted hash.
	 *
	 * @return bool
	 */
	private static function rate_limit_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'nexora_nl_' . substr( wp_hash( $ip ), 0, 20 );
		$hit = (int) get_transient( $key );
		if ( $hit >= 5 ) {
			return false;
		}
		set_transient( $key, $hit + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Find a subscriber record by email.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	private static function find( $email ) {
		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'private',
				'title'          => strtolower( $email ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $q->posts ? (int) $q->posts[0] : 0;
	}

	/**
	 * Create or update the local subscriber record with consent evidence.
	 *
	 * @param string $email  Email.
	 * @param string $source Form id.
	 * @return int Post ID.
	 */
	private static function store_local( $email, $source ) {
		$email = strtolower( $email );
		$id    = self::find( $email );
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'private',
					'post_title'  => $email,
				)
			);
		}
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_nexora_consent_at', gmdate( 'c' ) );
		update_post_meta( $id, '_nexora_consent_text', apply_filters( 'nexora_newsletter_consent_text', __( 'I agree to receive marketing emails and accept the privacy policy. I can unsubscribe at any time.', 'nexora-commerce-toolkit' ) ) );
		update_post_meta( $id, '_nexora_source', $source );
		return (int) $id;
	}

	/**
	 * Forward to the configured provider.
	 *
	 * @param string $email Email.
	 * @return string Status code for the record (local|sent|error:...|unconfigured).
	 */
	private static function forward( $email ) {
		$provider = Nexora_Toolkit_Settings::get( 'newsletter_provider' );
		$request  = null;

		switch ( $provider ) {
			case 'mailchimp':
				$key  = Nexora_Toolkit_Settings::get( 'mailchimp_api_key' );
				$list = Nexora_Toolkit_Settings::get( 'mailchimp_list_id' );
				if ( ! $key || ! $list || false === strpos( $key, '-' ) ) {
					return 'unconfigured';
				}
				$dc      = preg_replace( '/[^a-z0-9]/', '', substr( strrchr( $key, '-' ), 1 ) );
				$request = array(
					'url'  => sprintf( 'https://%1$s.api.mailchimp.com/3.0/lists/%2$s/members/%3$s', $dc, rawurlencode( $list ), md5( strtolower( $email ) ) ),
					'args' => array(
						'method'  => 'PUT',
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( 'nexora:' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
							'Content-Type'  => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'email_address' => $email,
								'status_if_new' => Nexora_Toolkit_Settings::get( 'mailchimp_double_optin' ) ? 'pending' : 'subscribed',
							)
						),
					),
				);
				break;

			case 'brevo':
				$key  = Nexora_Toolkit_Settings::get( 'brevo_api_key' );
				$list = (int) Nexora_Toolkit_Settings::get( 'brevo_list_id' );
				if ( ! $key || ! $list ) {
					return 'unconfigured';
				}
				$request = array(
					'url'  => 'https://api.brevo.com/v3/contacts',
					'args' => array(
						'method'  => 'POST',
						'headers' => array(
							'api-key'      => $key,
							'Content-Type' => 'application/json',
							'Accept'       => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'email'         => $email,
								'listIds'       => array( $list ),
								'updateEnabled' => true,
							)
						),
					),
				);
				break;

			case 'klaviyo':
				$key  = Nexora_Toolkit_Settings::get( 'klaviyo_private_key' );
				$list = Nexora_Toolkit_Settings::get( 'klaviyo_list_id' );
				if ( ! $key || ! $list ) {
					return 'unconfigured';
				}
				$request = array(
					'url'  => 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs',
					'args' => array(
						'method'  => 'POST',
						'headers' => array(
							'Authorization' => 'Klaviyo-API-Key ' . $key,
							'revision'      => apply_filters( 'nexora_klaviyo_revision', '2024-10-15' ),
							'Content-Type'  => 'application/vnd.api+json',
							'Accept'        => 'application/vnd.api+json',
						),
						'body'    => wp_json_encode(
							array(
								'data' => array(
									'type'          => 'profile-subscription-bulk-create-job',
									'attributes'    => array(
										'custom_source' => 'Freshtastic storefront',
										'profiles'      => array(
											'data' => array(
												array(
													'type'       => 'profile',
													'attributes' => array(
														'email'         => $email,
														'subscriptions' => array( 'email' => array( 'marketing' => array( 'consent' => 'SUBSCRIBED' ) ) ),
													),
												),
											),
										),
									),
									'relationships' => array( 'list' => array( 'data' => array( 'type' => 'list', 'id' => $list ) ) ),
								),
							)
						),
					),
				);
				break;

			default:
				return 'local';
		}

		$request['args']['timeout'] = 8;
		$response                   = wp_remote_request( $request['url'], $request['args'] );
		$code                       = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return 'sent';
		}
		$error = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
		self::log( sprintf( '%s signup failed: %s', $provider, $error ) );
		return 'error:' . $code;
	}

	/**
	 * Log via WooCommerce's logger when available (never logs keys or full responses).
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'nexora-newsletter' ) );
		}
	}

	/**
	 * Admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'      => $columns['cb'],
			'title'   => __( 'Email', 'nexora-commerce-toolkit' ),
			'consent' => __( 'Consent given', 'nexora-commerce-toolkit' ),
			'source'  => __( 'Form', 'nexora-commerce-toolkit' ),
			'status'  => __( 'Provider status', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $column, $post_id ) {
		$map = array( 'consent' => '_nexora_consent_at', 'source' => '_nexora_source', 'status' => '_nexora_provider_status' );
		if ( isset( $map[ $column ] ) ) {
			echo esc_html( (string) get_post_meta( $post_id, $map[ $column ], true ) );
		}
	}

	/**
	 * Export link above the subscriber list.
	 */
	public static function list_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . self::CPT !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=nexora_export_subscribers' ), 'nexora_export_subscribers' );
		printf(
			'<div class="notice notice-info"><p>%1$s <a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Subscribers who signed up through Freshtastic forms, with consent timestamps. Export to import into another provider.', 'nexora-commerce-toolkit' ),
			esc_url( $url ),
			esc_html__( 'Export CSV', 'nexora-commerce-toolkit' )
		);
	}

	/**
	 * CSV export (admins only).
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export subscribers.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_export_subscribers' );
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=nexora-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'email', 'consent_at', 'source', 'provider_status' ) );
		foreach ( $ids as $id ) {
			fputcsv( $out, array( get_the_title( $id ), get_post_meta( $id, '_nexora_consent_at', true ), get_post_meta( $id, '_nexora_source', true ), get_post_meta( $id, '_nexora_provider_status', true ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Register privacy exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nexora-newsletter'] = array(
			'exporter_friendly_name' => __( 'Freshtastic newsletter signup', 'nexora-commerce-toolkit' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Privacy exporter.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export_personal_data( $email ) {
		$id   = self::find( $email );
		$data = array();
		if ( $id ) {
			$data[] = array(
				'group_id'    => 'nexora-newsletter',
				'group_label' => __( 'Newsletter signup', 'nexora-commerce-toolkit' ),
				'item_id'     => 'nexora-subscriber-' . $id,
				'data'        => array(
					array( 'name' => __( 'Email', 'nexora-commerce-toolkit' ), 'value' => get_the_title( $id ) ),
					array( 'name' => __( 'Consent given at', 'nexora-commerce-toolkit' ), 'value' => get_post_meta( $id, '_nexora_consent_at', true ) ),
					array( 'name' => __( 'Consent text', 'nexora-commerce-toolkit' ), 'value' => get_post_meta( $id, '_nexora_consent_text', true ) ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Register privacy eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nexora-newsletter'] = array(
			'eraser_friendly_name' => __( 'Freshtastic newsletter signup', 'nexora-commerce-toolkit' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Privacy eraser (local record only — also remove the contact in your email provider).
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase_personal_data( $email ) {
		$id = self::find( $email );
		if ( $id ) {
			wp_delete_post( $id, true );
		}
		return array(
			'items_removed'  => (bool) $id,
			'items_retained' => false,
			'messages'       => $id ? array( __( 'Local newsletter record removed. Also remove this contact in your email marketing provider.', 'nexora-commerce-toolkit' ) ) : array(),
			'done'           => true,
		);
	}
}

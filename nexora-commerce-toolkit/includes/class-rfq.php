<?php
/**
 * Request for Quotation (RFQ).
 *
 * Shoppers ask for items that are not listed, out of stock, or needed in bulk. Requests are stored
 * privately (WooCommerce → Quotations), the store is notified, the shopper gets a reference number,
 * and staff can email a quote back from the request screen. Logged-in customers can follow their
 * requests under My Account → Quotations.
 *
 * Form: [nexora_rfq_form item="" product_id="" compact="0"] — prefilled from ?rfq_item= when present.
 * Like the newsletter form it has no nonce (cached pages); it uses a honeypot, per-IP rate limiting
 * and required consent.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * RFQ module.
 */
class Nexora_Toolkit_RFQ {

	const CPT = 'nexora_rfq';

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( ! Nexora_Toolkit_Settings::get( 'rfq_enabled' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'nexora_rfq_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_nopriv_nexora_rfq', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_nexora_rfq', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'nexora_rfq_enabled', '__return_true' );
		add_filter( 'nexora_rfq_url', array( __CLASS__, 'url' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_admin' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_quotations_endpoint', array( __CLASS__, 'account_tab' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Statuses.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'New', 'nexora-commerce-toolkit' ),
			'reviewing' => __( 'Sourcing', 'nexora-commerce-toolkit' ),
			'quoted'    => __( 'Quoted', 'nexora-commerce-toolkit' ),
			'accepted'  => __( 'Accepted', 'nexora-commerce-toolkit' ),
			'closed'    => __( 'Closed', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Quantity units offered on the form.
	 *
	 * @return array<string,string>
	 */
	public static function units() {
		return apply_filters(
			'nexora_rfq_units',
			array(
				'kg'     => __( 'kg', 'nexora-commerce-toolkit' ),
				'sack25' => __( 'sack (25 kg)', 'nexora-commerce-toolkit' ),
				'sack50' => __( 'sack (50 kg)', 'nexora-commerce-toolkit' ),
				'tray'   => __( 'tray (30 pcs)', 'nexora-commerce-toolkit' ),
				'pack'   => __( 'pack', 'nexora-commerce-toolkit' ),
				'piece'  => __( 'piece', 'nexora-commerce-toolkit' ),
				'bundle' => __( 'bundle', 'nexora-commerce-toolkit' ),
				'box'    => __( 'box / crate', 'nexora-commerce-toolkit' ),
				'liter'  => __( 'liter', 'nexora-commerce-toolkit' ),
			)
		);
	}

	/**
	 * Request frequencies.
	 *
	 * @return array<string,string>
	 */
	public static function frequencies() {
		return array(
			'once'    => __( 'One-time', 'nexora-commerce-toolkit' ),
			'weekly'  => __( 'Weekly', 'nexora-commerce-toolkit' ),
			'monthly' => __( 'Monthly', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Reference number for a request.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function ref( $id ) {
		return 'RFQ-' . str_pad( (string) $id, 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Register the post type (WooCommerce → Quotations) and account endpoint.
	 */
	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Quotation requests', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Quotation request', 'nexora-commerce-toolkit' ),
					'menu_name'     => __( 'Quotations', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Quotation request', 'nexora-commerce-toolkit' ),
					'search_items'  => __( 'Search requests', 'nexora-commerce-toolkit' ),
					'not_found'     => __( 'No quotation requests yet.', 'nexora-commerce-toolkit' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => class_exists( 'WooCommerce' ) ? 'woocommerce' : ( Nexora_Store::active() ? 'nexora-store' : true ),
				'menu_icon'       => 'dashicons-clipboard',
				'supports'        => array( 'title' ),
				'capability_type' => class_exists( 'WooCommerce' ) || Nexora_Store::active() ? 'shop_order' : 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * My Account endpoint.
	 */
	public static function endpoint() {
		add_rewrite_endpoint( 'quotations', EP_PAGES );
	}

	/**
	 * URL of the quotation page, optionally prefilled.
	 *
	 * @param string $url  Default.
	 * @param string $item Item to prefill.
	 * @return string
	 */
	public static function url( $url = '', $item = '' ) {
		$page = get_page_by_path( 'request-a-quote' );
		$base = $page && 'publish' === $page->post_status ? get_permalink( $page ) : ( $url ? $url : home_url( '/' ) );
		return $item ? add_query_arg( 'rfq_item', rawurlencode( $item ), $base ) : $base;
	}

	/**
	 * Front-end script.
	 */
	public static function assets() {
		wp_register_script( 'nexora-rfq', NEXORA_TOOLKIT_URL . 'assets/js/rfq.js', array(), NEXORA_TOOLKIT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'nexora-rfq',
			'nexoraRfq',
			array(
				'endpoint' => esc_url_raw( rest_url( 'nexora/v1/rfq' ) ),
				'i18n'     => array(
					'sending' => __( 'Sending your request…', 'nexora-commerce-toolkit' ),
					'error'   => __( 'Sorry, something went wrong. Please try again or contact us directly.', 'nexora-commerce-toolkit' ),
				),
			)
		);
	}

	/**
	 * Form shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'item' => '', 'product_id' => 0, 'compact' => '0', 'title' => '' ), $atts, 'nexora_rfq_form' );
		wp_enqueue_script( 'nexora-rfq' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only.
		$item       = $atts['item'] ? $atts['item'] : ( isset( $_GET['rfq_item'] ) ? sanitize_text_field( wp_unslash( $_GET['rfq_item'] ) ) : '' );
		$product_id = absint( $atts['product_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only.
		if ( ! $product_id && isset( $_GET['rfq_product'] ) ) {
			$product_id = absint( $_GET['rfq_product'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$compact = '1' === (string) $atts['compact'];
		$uid     = wp_unique_id( 'nexora-rfq-' );
		$user    = wp_get_current_user();
		$name    = $user->exists() ? trim( $user->first_name . ' ' . $user->last_name ) : '';
		$email   = $user->exists() ? $user->user_email : '';
		$phone   = $user->exists() ? (string) get_user_meta( $user->ID, 'billing_phone', true ) : '';
		$cats    = taxonomy_exists( 'product_cat' ) ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$sent    = isset( $_GET['rfq_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['rfq_sent'] ) ) : '';

		$field = static function ( $key, $label, $control, $required = false, $wide = false ) use ( $uid ) {
			return sprintf(
				'<p class="nexora-rfq__field%1$s"><label for="%2$s-%3$s">%4$s%5$s</label>%6$s</p>',
				$wide ? ' is-wide' : '',
				esc_attr( $uid ),
				esc_attr( $key ),
				esc_html( $label ),
				$required ? ' <span class="nexora-rfq__req" aria-hidden="true">*</span>' : ' <span class="nexora-rfq__opt">' . esc_html__( '(optional)', 'nexora-commerce-toolkit' ) . '</span>',
				$control
			);
		};
		$input = static function ( $key, $type = 'text', $value = '', $required = false, $extra = '' ) use ( $uid ) {
			return sprintf( '<input type="%1$s" id="%2$s-%3$s" name="%3$s" value="%4$s"%5$s %6$s>', esc_attr( $type ), esc_attr( $uid ), esc_attr( $key ), esc_attr( $value ), $required ? ' required' : '', $extra );
		};
		$select = static function ( $key, $options, $selected = '', $aria_label = '' ) use ( $uid ) {
			$html = sprintf( '<select id="%1$s-%2$s" name="%2$s"%3$s>', esc_attr( $uid ), esc_attr( $key ), $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : '' );
			foreach ( $options as $value => $label ) {
				$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
			}
			return $html . '</select>';
		};

		$cat_options = array( '' => __( 'Choose a category', 'nexora-commerce-toolkit' ) );
		foreach ( is_wp_error( $cats ) ? array() : $cats as $cat ) {
			$cat_options[ $cat->name ] = ( $cat->parent ? '— ' : '' ) . $cat->name;
		}
		$cat_options['Other'] = __( 'Other / not sure', 'nexora-commerce-toolkit' );

		ob_start();
		?>
		<div class="nexora-rfq<?php echo $compact ? ' is-compact' : ''; ?>" data-nexora-rfq>
			<?php if ( $sent ) : ?>
				<div class="nexora-rfq__done" role="status">
					<strong><?php esc_html_e( 'Request received.', 'nexora-commerce-toolkit' ); ?></strong>
					<?php
					/* translators: %s: reference number */
					echo esc_html( sprintf( __( 'Your reference is %s. We will email or call you with a quotation, usually within one business day.', 'nexora-commerce-toolkit' ), $sent ) );
					?>
				</div>
			<?php endif; ?>
			<?php if ( $atts['title'] ) : ?>
				<h2 class="nexora-rfq__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>
			<form class="nexora-rfq__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<input type="hidden" name="action" value="nexora_rfq">
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>">
				<div class="nexora-rfq__hp" aria-hidden="true"><label for="<?php echo esc_attr( $uid ); ?>-company_url"><?php esc_html_e( 'Leave this field empty', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="<?php echo esc_attr( $uid ); ?>-company_url" name="company_url" tabindex="-1" autocomplete="off"></div>

				<fieldset class="nexora-rfq__group">
					<legend><?php esc_html_e( 'What do you need?', 'nexora-commerce-toolkit' ); ?></legend>
					<div class="nexora-rfq__grid">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
						echo $field( 'item', __( 'Item', 'nexora-commerce-toolkit' ), $input( 'item', 'text', $item, true, 'maxlength="120" placeholder="' . esc_attr__( 'e.g. Dinorado rice, fresh bangus, native garlic', 'nexora-commerce-toolkit' ) . '"' ), true, true );
						echo $field( 'category', __( 'Category', 'nexora-commerce-toolkit' ), $select( 'category', $cat_options ) );
						echo $field( 'quantity', __( 'Quantity', 'nexora-commerce-toolkit' ), '<span class="nexora-rfq__qty">' . $input( 'quantity', 'number', '', true, 'min="0.1" step="any" inputmode="decimal"' ) . $select( 'unit', self::units(), 'kg', __( 'Unit', 'nexora-commerce-toolkit' ) ) . '</span>', true );
						echo $field( 'frequency', __( 'How often', 'nexora-commerce-toolkit' ), $select( 'frequency', self::frequencies(), 'once' ) );
						echo $field( 'target_price', __( 'Target price per unit (₱)', 'nexora-commerce-toolkit' ), $input( 'target_price', 'number', '', false, 'min="0" step="any" inputmode="decimal"' ) );
						if ( ! $compact ) {
							echo $field( 'needed_by', __( 'Needed by', 'nexora-commerce-toolkit' ), $input( 'needed_by', 'date', '', false, 'min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"' ) );
							echo $field( 'location', __( 'Delivery city / municipality', 'nexora-commerce-toolkit' ), $input( 'location', 'text', '', false, 'maxlength="120" autocomplete="address-level2"' ) );
						}
						?>
					</div>
				</fieldset>

				<fieldset class="nexora-rfq__group">
					<legend><?php esc_html_e( 'Your contact details', 'nexora-commerce-toolkit' ); ?></legend>
					<div class="nexora-rfq__grid">
						<?php
						echo $field( 'name', __( 'Name', 'nexora-commerce-toolkit' ), $input( 'name', 'text', $name, true, 'maxlength="100" autocomplete="name"' ), true );
						if ( ! $compact ) {
							echo $field( 'company', __( 'Business name', 'nexora-commerce-toolkit' ), $input( 'company', 'text', '', false, 'maxlength="100" autocomplete="organization"' ) );
						}
						echo $field( 'email', __( 'Email', 'nexora-commerce-toolkit' ), $input( 'email', 'email', $email, true, 'autocomplete="email"' ), true );
						echo $field( 'phone', __( 'Mobile number', 'nexora-commerce-toolkit' ), $input( 'phone', 'tel', $phone, true, 'autocomplete="tel" placeholder="09XX XXX XXXX"' ), true );
						echo $field( 'notes', __( 'Notes', 'nexora-commerce-toolkit' ), sprintf( '<textarea id="%1$s-notes" name="notes" rows="%2$d" maxlength="1500" placeholder="%3$s"></textarea>', esc_attr( $uid ), $compact ? 2 : 4, esc_attr__( 'Size, cut, grade, packaging, delivery time…', 'nexora-commerce-toolkit' ) ), false, true );
						// phpcs:enable
						?>
					</div>
				</fieldset>

				<label class="nexora-rfq__consent">
					<input type="checkbox" name="consent" value="1" required>
					<span>
						<?php esc_html_e( 'I agree to be contacted about this request. My details are used only to prepare and send the quotation.', 'nexora-commerce-toolkit' ); ?>
						<?php if ( get_privacy_policy_url() ) : ?>
							<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'nexora-commerce-toolkit' ); ?></a>
						<?php endif; ?>
					</span>
				</label>
				<p class="nexora-rfq__actions">
					<button type="submit" class="button alt nexora-rfq__submit"><?php esc_html_e( 'Send quotation request', 'nexora-commerce-toolkit' ); ?></button>
				</p>
				<p class="nexora-rfq__msg" role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'nexora/v1',
			'/rfq',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function ( $request ) {
					$r = self::submit( $request->get_params() );
					return new WP_REST_Response( $r, $r['ok'] ? 200 : $r['status'] );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * No-JS fallback.
	 */
	public static function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form; see class docblock.
		$r       = self::submit( wp_unslash( $_POST ) );
		$referer = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$url     = $r['ok'] ? add_query_arg( 'rfq_sent', rawurlencode( $r['ref'] ), remove_query_arg( 'rfq_sent', $referer ) ) : add_query_arg( 'rfq_error', '1', $referer );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Validate, store and notify.
	 *
	 * @param array $p Params.
	 * @return array{ok:bool,status:int,message:string,ref?:string,errors?:array}
	 */
	public static function submit( $p ) {
		$fail = static function ( $message, $status = 400, $errors = array() ) {
			return array( 'ok' => false, 'status' => $status, 'message' => $message, 'errors' => $errors );
		};
		if ( ! empty( $p['company_url'] ) ) {
			return array( 'ok' => true, 'status' => 200, 'message' => __( 'Thank you.', 'nexora-commerce-toolkit' ), 'ref' => '' );
		}

		$data = array(
			'item'         => isset( $p['item'] ) ? sanitize_text_field( (string) $p['item'] ) : '',
			'category'     => isset( $p['category'] ) ? sanitize_text_field( (string) $p['category'] ) : '',
			'quantity'     => isset( $p['quantity'] ) ? (float) $p['quantity'] : 0,
			'unit'         => isset( $p['unit'] ) ? sanitize_key( (string) $p['unit'] ) : '',
			'frequency'    => isset( $p['frequency'] ) ? sanitize_key( (string) $p['frequency'] ) : 'once',
			'target_price' => isset( $p['target_price'] ) && '' !== $p['target_price'] ? max( 0, (float) $p['target_price'] ) : '',
			'needed_by'    => isset( $p['needed_by'] ) ? sanitize_text_field( (string) $p['needed_by'] ) : '',
			'location'     => isset( $p['location'] ) ? sanitize_text_field( (string) $p['location'] ) : '',
			'name'         => isset( $p['name'] ) ? sanitize_text_field( (string) $p['name'] ) : '',
			'company'      => isset( $p['company'] ) ? sanitize_text_field( (string) $p['company'] ) : '',
			'email'        => isset( $p['email'] ) ? sanitize_email( (string) $p['email'] ) : '',
			'phone'        => isset( $p['phone'] ) ? preg_replace( '/[^0-9+ ()-]/', '', (string) $p['phone'] ) : '',
			'notes'        => isset( $p['notes'] ) ? sanitize_textarea_field( (string) $p['notes'] ) : '',
			'product_id'   => isset( $p['product_id'] ) ? absint( $p['product_id'] ) : 0,
		);

		$errors = array();
		if ( mb_strlen( $data['item'] ) < 2 ) {
			$errors['item'] = __( 'Tell us which item you need.', 'nexora-commerce-toolkit' );
		}
		if ( $data['quantity'] <= 0 ) {
			$errors['quantity'] = __( 'Enter a quantity greater than zero.', 'nexora-commerce-toolkit' );
		}
		if ( ! array_key_exists( $data['unit'], self::units() ) ) {
			$errors['unit'] = __( 'Choose a unit.', 'nexora-commerce-toolkit' );
		}
		if ( '' === $data['name'] ) {
			$errors['name'] = __( 'Enter your name.', 'nexora-commerce-toolkit' );
		}
		if ( ! is_email( $data['email'] ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'nexora-commerce-toolkit' );
		}
		if ( strlen( preg_replace( '/\D/', '', $data['phone'] ) ) < 7 ) {
			$errors['phone'] = __( 'Enter a mobile number so we can confirm details.', 'nexora-commerce-toolkit' );
		}
		if ( $data['needed_by'] && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['needed_by'] ) || $data['needed_by'] < wp_date( 'Y-m-d' ) ) ) {
			$errors['needed_by'] = __( 'Choose today or a later date.', 'nexora-commerce-toolkit' );
		}
		if ( empty( $p['consent'] ) ) {
			$errors['consent'] = __( 'Please agree to be contacted about this request.', 'nexora-commerce-toolkit' );
		}
		if ( ! array_key_exists( $data['frequency'], self::frequencies() ) ) {
			$data['frequency'] = 'once';
		}
		if ( $errors ) {
			return $fail( __( 'Please check the highlighted fields.', 'nexora-commerce-toolkit' ), 400, $errors );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'nexora_rfq_' . substr( wp_hash( $ip ), 0, 20 );
		$hit = (int) get_transient( $key );
		if ( $hit >= 4 ) {
			return $fail( __( 'Too many requests. Please wait a few minutes and try again.', 'nexora-commerce-toolkit' ), 429 );
		}
		set_transient( $key, $hit + 1, 10 * MINUTE_IN_SECONDS );

		$id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'private',
				'post_title'   => $data['item'],
				'post_content' => $data['notes'],
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $fail( __( 'Sorry, we could not save your request. Please try again.', 'nexora-commerce-toolkit' ), 500 );
		}
		foreach ( $data as $k => $v ) {
			if ( 'notes' !== $k ) {
				update_post_meta( $id, '_rfq_' . $k, $v );
			}
		}
		update_post_meta( $id, '_rfq_status', 'new' );
		update_post_meta( $id, '_rfq_user', get_current_user_id() );
		update_post_meta( $id, '_rfq_consent_at', gmdate( 'c' ) );
		wp_update_post( array( 'ID' => $id, 'post_title' => self::ref( $id ) . ' — ' . $data['item'] ) );

		self::notify( $id, $data );
		do_action( 'nexora_rfq_submitted', $id, $data );

		return array(
			'ok'      => true,
			'status'  => 200,
			'ref'     => self::ref( $id ),
			/* translators: %s: reference number */
			'message' => sprintf( __( 'Request received. Your reference is %s. We will email or call you with a quotation, usually within one business day.', 'nexora-commerce-toolkit' ), self::ref( $id ) ),
		);
	}

	/**
	 * Send an HTML (WooCommerce-styled when available) or plain email.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $heading Heading.
	 * @param array  $rows    Label => value rows.
	 * @param string $intro   Intro paragraph.
	 * @param string $reply   Reply-to address.
	 * @return bool
	 */
	private static function mail( $to, $subject, $heading, $rows, $intro = '', $reply = '' ) {
		$table = '<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse;width:100%;border-color:#e5e7eb">';
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				$table .= '<tr><th style="text-align:left;width:40%">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
			}
		}
		$table  .= '</table>';
		$body    = ( $intro ? '<p>' . nl2br( esc_html( $intro ) ) . '</p>' : '' ) . $table;
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			return (bool) WC()->mailer()->send( $to, $subject, WC()->mailer()->wrap_message( $heading, $body ), $headers );
		}
		return wp_mail( $to, $subject, '<h2>' . esc_html( $heading ) . '</h2>' . $body, $headers );
	}

	/**
	 * Request rows for emails and the admin screen.
	 *
	 * @param int $id Post ID.
	 * @return array<string,string>
	 */
	private static function rows( $id ) {
		$m     = static function ( $k ) use ( $id ) {
			return (string) get_post_meta( $id, '_rfq_' . $k, true );
		};
		$units = self::units();
		$freq  = self::frequencies();
		return array(
			__( 'Reference', 'nexora-commerce-toolkit' )        => self::ref( $id ),
			__( 'Item', 'nexora-commerce-toolkit' )             => $m( 'item' ),
			__( 'Category', 'nexora-commerce-toolkit' )         => $m( 'category' ),
			__( 'Quantity', 'nexora-commerce-toolkit' )         => $m( 'quantity' ) . ' ' . ( isset( $units[ $m( 'unit' ) ] ) ? $units[ $m( 'unit' ) ] : $m( 'unit' ) ),
			__( 'Frequency', 'nexora-commerce-toolkit' )        => isset( $freq[ $m( 'frequency' ) ] ) ? $freq[ $m( 'frequency' ) ] : '',
			__( 'Target price per unit', 'nexora-commerce-toolkit' ) => '' !== $m( 'target_price' ) ? '₱' . $m( 'target_price' ) : '',
			__( 'Needed by', 'nexora-commerce-toolkit' )        => $m( 'needed_by' ),
			__( 'Delivery location', 'nexora-commerce-toolkit' ) => $m( 'location' ),
			__( 'Name', 'nexora-commerce-toolkit' )             => $m( 'name' ),
			__( 'Business', 'nexora-commerce-toolkit' )         => $m( 'company' ),
			__( 'Email', 'nexora-commerce-toolkit' )            => $m( 'email' ),
			__( 'Mobile', 'nexora-commerce-toolkit' )           => $m( 'phone' ),
			__( 'Notes', 'nexora-commerce-toolkit' )            => (string) get_post_field( 'post_content', $id ),
		);
	}

	/**
	 * Notify the store and confirm to the customer.
	 *
	 * @param int   $id   Post ID.
	 * @param array $data Data.
	 */
	private static function notify( $id, $data ) {
		$store = Nexora_Toolkit_Settings::get( 'rfq_email' );
		$store = is_email( $store ) ? $store : get_option( 'admin_email' );
		$rows  = self::rows( $id );
		/* translators: 1: reference, 2: item */
		self::mail( $store, sprintf( __( 'New quotation request %1$s: %2$s', 'nexora-commerce-toolkit' ), self::ref( $id ), $data['item'] ), __( 'New quotation request', 'nexora-commerce-toolkit' ), $rows, __( 'Reply from WooCommerce → Quotations, or reply to this email to contact the customer.', 'nexora-commerce-toolkit' ), $data['email'] );
		self::mail(
			$data['email'],
			/* translators: 1: site name, 2: reference */
			sprintf( __( '[%1$s] We received your quotation request %2$s', 'nexora-commerce-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::ref( $id ) ),
			__( 'Quotation request received', 'nexora-commerce-toolkit' ),
			$rows,
			/* translators: %s: customer name */
			sprintf( __( 'Hi %s, thank you! Our team will check supply and pricing and get back to you with a quotation, usually within one business day.', 'nexora-commerce-toolkit' ), $data['name'] ),
			$store
		);
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * Meta boxes.
	 */
	public static function meta_boxes() {
		add_meta_box( 'nexora-rfq-details', __( 'Request details', 'nexora-commerce-toolkit' ), array( __CLASS__, 'box_details' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'nexora-rfq-quote', __( 'Status and quotation', 'nexora-commerce-toolkit' ), array( __CLASS__, 'box_quote' ), self::CPT, 'side', 'high' );
	}

	/**
	 * Details box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_details( $post ) {
		echo '<table class="widefat striped"><tbody>';
		foreach ( self::rows( $post->ID ) as $label => $value ) {
			printf( '<tr><th style="width:30%%">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), nl2br( esc_html( $value ) ) );
		}
		$product_id = (int) get_post_meta( $post->ID, '_rfq_product_id', true );
		if ( $product_id && get_post( $product_id ) ) {
			printf( '<tr><th>%1$s</th><td><a href="%2$s">%3$s</a></td></tr>', esc_html__( 'Asked from product', 'nexora-commerce-toolkit' ), esc_url( get_edit_post_link( $product_id ) ), esc_html( get_the_title( $product_id ) ) );
		}
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Consent given', 'nexora-commerce-toolkit' ), esc_html( (string) get_post_meta( $post->ID, '_rfq_consent_at', true ) ) );
		echo '</tbody></table>';

		$log = (array) get_post_meta( $post->ID, '_rfq_quote_log', true );
		if ( array_filter( $log ) ) {
			echo '<h4>' . esc_html__( 'Quotes sent', 'nexora-commerce-toolkit' ) . '</h4><ul>';
			foreach ( array_filter( $log ) as $entry ) {
				printf( '<li>%1$s — %2$s</li>', esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['t'] ) ), esc_html( $entry['summary'] ) );
			}
			echo '</ul>';
		}
	}

	/**
	 * Quote box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function box_quote( $post ) {
		wp_nonce_field( 'nexora_rfq_admin', 'nexora_rfq_nonce' );
		$status = (string) get_post_meta( $post->ID, '_rfq_status', true );
		$get    = static function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_rfq_' . $k, true );
		};
		?>
		<p><label for="rfq_status"><strong><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></strong></label><br>
		<select id="rfq_status" name="rfq_status" class="widefat">
			<?php foreach ( self::statuses() as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<p><label for="rfq_quote_price"><?php esc_html_e( 'Quoted price per unit (₱)', 'nexora-commerce-toolkit' ); ?></label>
		<input type="number" step="any" min="0" id="rfq_quote_price" name="rfq_quote_price" class="widefat" value="<?php echo esc_attr( $get( 'quote_price' ) ); ?>"></p>
		<p><label for="rfq_quote_total"><?php esc_html_e( 'Quoted total incl. delivery (₱)', 'nexora-commerce-toolkit' ); ?></label>
		<input type="number" step="any" min="0" id="rfq_quote_total" name="rfq_quote_total" class="widefat" value="<?php echo esc_attr( $get( 'quote_total' ) ); ?>"></p>
		<p><label for="rfq_quote_valid"><?php esc_html_e( 'Valid until', 'nexora-commerce-toolkit' ); ?></label>
		<input type="date" id="rfq_quote_valid" name="rfq_quote_valid" class="widefat" value="<?php echo esc_attr( $get( 'quote_valid' ) ); ?>"></p>
		<p><label for="rfq_quote_message"><?php esc_html_e( 'Message to customer', 'nexora-commerce-toolkit' ); ?></label>
		<textarea id="rfq_quote_message" name="rfq_quote_message" rows="4" class="widefat"><?php echo esc_textarea( $get( 'quote_message' ) ); ?></textarea></p>
		<p><label><input type="checkbox" name="rfq_send_quote" value="1"> <?php esc_html_e( 'Email this quotation to the customer on save (status becomes Quoted)', 'nexora-commerce-toolkit' ); ?></label></p>
		<?php
	}

	/**
	 * Save admin box; optionally email the quote.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_admin( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_rfq_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nexora_rfq_nonce'] ), 'nexora_rfq_admin' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['rfq_status'] ) ? sanitize_key( $_POST['rfq_status'] ) : 'new';
		$fields = array(
			'quote_price'   => isset( $_POST['rfq_quote_price'] ) && '' !== $_POST['rfq_quote_price'] ? (string) max( 0, (float) $_POST['rfq_quote_price'] ) : '',
			'quote_total'   => isset( $_POST['rfq_quote_total'] ) && '' !== $_POST['rfq_quote_total'] ? (string) max( 0, (float) $_POST['rfq_quote_total'] ) : '',
			'quote_valid'   => isset( $_POST['rfq_quote_valid'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['rfq_quote_valid'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['rfq_quote_valid'] ) ) : '',
			'quote_message' => isset( $_POST['rfq_quote_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rfq_quote_message'] ) ) : '',
		);
		foreach ( $fields as $k => $v ) {
			update_post_meta( $post_id, '_rfq_' . $k, $v );
		}

		if ( ! empty( $_POST['rfq_send_quote'] ) ) {
			$email = (string) get_post_meta( $post_id, '_rfq_email', true );
			if ( is_email( $email ) ) {
				$units = self::units();
				$unit  = (string) get_post_meta( $post_id, '_rfq_unit', true );
				$rows  = array(
					__( 'Reference', 'nexora-commerce-toolkit' )      => self::ref( $post_id ),
					__( 'Item', 'nexora-commerce-toolkit' )           => (string) get_post_meta( $post_id, '_rfq_item', true ),
					__( 'Quantity', 'nexora-commerce-toolkit' )       => get_post_meta( $post_id, '_rfq_quantity', true ) . ' ' . ( isset( $units[ $unit ] ) ? $units[ $unit ] : $unit ),
					__( 'Price per unit', 'nexora-commerce-toolkit' ) => '' !== $fields['quote_price'] ? wp_strip_all_tags( self::format_price( (float) $fields['quote_price'] ) ) : '',
					__( 'Total', 'nexora-commerce-toolkit' )          => '' !== $fields['quote_total'] ? wp_strip_all_tags( self::format_price( (float) $fields['quote_total'] ) ) : '',
					__( 'Valid until', 'nexora-commerce-toolkit' )    => $fields['quote_valid'],
				);
				self::mail(
					$email,
					/* translators: 1: site name, 2: reference */
					sprintf( __( '[%1$s] Your quotation %2$s', 'nexora-commerce-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::ref( $post_id ) ),
					__( 'Your quotation', 'nexora-commerce-toolkit' ),
					$rows,
					$fields['quote_message'] ? $fields['quote_message'] : __( 'Here is our quotation for your request. Reply to this email or call us to confirm.', 'nexora-commerce-toolkit' ),
					Nexora_Toolkit_Settings::get( 'rfq_email' ) ? Nexora_Toolkit_Settings::get( 'rfq_email' ) : get_option( 'admin_email' )
				);
				$log   = array_filter( (array) get_post_meta( $post_id, '_rfq_quote_log', true ) );
				$log[] = array( 't' => time(), 'summary' => trim( $rows[ __( 'Price per unit', 'nexora-commerce-toolkit' ) ] . ' · ' . $rows[ __( 'Total', 'nexora-commerce-toolkit' ) ], ' ·' ) );
				update_post_meta( $post_id, '_rfq_quote_log', $log );
				if ( in_array( $status, array( 'new', 'reviewing' ), true ) ) {
					$status = 'quoted';
				}
			}
		}
		update_post_meta( $post_id, '_rfq_status', array_key_exists( $status, self::statuses() ) ? $status : 'new' );
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'        => $columns['cb'],
			'title'     => __( 'Request', 'nexora-commerce-toolkit' ),
			'rfq_qty'   => __( 'Quantity', 'nexora-commerce-toolkit' ),
			'rfq_who'   => __( 'Customer', 'nexora-commerce-toolkit' ),
			'rfq_need'  => __( 'Needed by', 'nexora-commerce-toolkit' ),
			'rfq_state' => __( 'Status', 'nexora-commerce-toolkit' ),
			'date'      => $columns['date'],
		);
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public static function column( $column, $post_id ) {
		$m = static function ( $k ) use ( $post_id ) {
			return (string) get_post_meta( $post_id, '_rfq_' . $k, true );
		};
		$units = self::units();
		switch ( $column ) {
			case 'rfq_qty':
				echo esc_html( $m( 'quantity' ) . ' ' . ( isset( $units[ $m( 'unit' ) ] ) ? $units[ $m( 'unit' ) ] : $m( 'unit' ) ) );
				break;
			case 'rfq_who':
				echo esc_html( $m( 'name' ) ) . '<br><a href="mailto:' . esc_attr( $m( 'email' ) ) . '">' . esc_html( $m( 'email' ) ) . '</a><br>' . esc_html( $m( 'phone' ) );
				break;
			case 'rfq_need':
				echo esc_html( $m( 'needed_by' ) ? $m( 'needed_by' ) : '—' );
				break;
			case 'rfq_state':
				$s = self::statuses();
				printf( '<mark class="order-status status-%1$s"><span>%2$s</span></mark>', esc_attr( 'quoted' === $m( 'status' ) ? 'processing' : ( 'closed' === $m( 'status' ) ? 'cancelled' : ( 'accepted' === $m( 'status' ) ? 'completed' : 'on-hold' ) ) ), esc_html( isset( $s[ $m( 'status' ) ] ) ? $s[ $m( 'status' ) ] : $m( 'status' ) ) );
				break;
		}
	}

	/* --------------------------------------------------------------- account */

	/**
	 * WooCommerce query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars['quotations'] = 'quotations';
		return $vars;
	}

	/**
	 * Account menu item.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
		unset( $items['customer-logout'] );
		$items['quotations'] = __( 'Quotations', 'nexora-commerce-toolkit' );
		return $items + $logout;
	}

	/**
	 * Requests made by the current user (by account or email).
	 *
	 * @return WP_Post[]
	 */
	private static function user_requests() {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'numberposts' => 50,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array( 'key' => '_rfq_user', 'value' => $user->ID ),
					array( 'key' => '_rfq_email', 'value' => $user->user_email ),
				),
			)
		);
	}

	/**
	 * Number of requests for the logged-in customer (customer dashboard).
	 *
	 * @return int
	 */
	public static function count_for_current_user() {
		return count( self::user_requests() );
	}

	/**
	 * Price text with whichever store engine is running.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function format_price( $amount ) {
		return function_exists( 'wc_price' ) ? wc_price( $amount ) : ( Nexora_Store::active() ? Nexora_Store::price( $amount ) : number_format_i18n( $amount, 2 ) );
	}

	/**
	 * Account tab.
	 */
	public static function account_tab() {
		$requests = self::user_requests();
		$statuses = self::statuses();
		$units    = self::units();
		echo '<p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'New quotation request', 'nexora-commerce-toolkit' ) . '</a></p>';
		if ( ! $requests ) {
			echo '<p>' . esc_html__( 'You have not requested any quotations yet.', 'nexora-commerce-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="shop_table shop_table_responsive"><thead><tr><th>' . esc_html__( 'Reference', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Item', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Quantity', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Status', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Quote', 'nexora-commerce-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $requests as $r ) {
			$m      = static function ( $k ) use ( $r ) {
				return (string) get_post_meta( $r->ID, '_rfq_' . $k, true );
			};
			$quote = '' !== $m( 'quote_price' ) ? wp_strip_all_tags( self::format_price( (float) $m( 'quote_price' ) ) ) . ' / ' . ( isset( $units[ $m( 'unit' ) ] ) ? $units[ $m( 'unit' ) ] : '' ) : '—';
			printf(
				'<tr><td data-title="%6$s">%1$s</td><td data-title="%7$s">%2$s</td><td data-title="%8$s">%3$s</td><td data-title="%9$s">%4$s</td><td data-title="%10$s">%5$s</td></tr>',
				esc_html( self::ref( $r->ID ) ),
				esc_html( $m( 'item' ) ),
				esc_html( $m( 'quantity' ) . ' ' . ( isset( $units[ $m( 'unit' ) ] ) ? $units[ $m( 'unit' ) ] : '' ) ),
				esc_html( isset( $statuses[ $m( 'status' ) ] ) ? $statuses[ $m( 'status' ) ] : '' ),
				esc_html( $quote ),
				esc_attr__( 'Reference', 'nexora-commerce-toolkit' ),
				esc_attr__( 'Item', 'nexora-commerce-toolkit' ),
				esc_attr__( 'Quantity', 'nexora-commerce-toolkit' ),
				esc_attr__( 'Status', 'nexora-commerce-toolkit' ),
				esc_attr__( 'Quote', 'nexora-commerce-toolkit' )
			);
		}
		echo '</tbody></table>';
	}

	/* --------------------------------------------------------------- privacy */

	/**
	 * Requests by email.
	 *
	 * @param string $email Email.
	 * @return int[]
	 */
	private static function by_email( $email ) {
		return get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_rfq_email', 'meta_value' => $email ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nexora-rfq'] = array(
			'exporter_friendly_name' => __( 'Freshtastic quotation requests', 'nexora-commerce-toolkit' ),
			'callback'               => static function ( $email ) {
				$data = array();
				foreach ( self::by_email( $email ) as $id ) {
					$items = array();
					foreach ( self::rows( $id ) as $label => $value ) {
						$items[] = array( 'name' => $label, 'value' => $value );
					}
					$data[] = array( 'group_id' => 'nexora-rfq', 'group_label' => __( 'Quotation requests', 'nexora-commerce-toolkit' ), 'item_id' => 'nexora-rfq-' . $id, 'data' => $items );
				}
				return array( 'data' => $data, 'done' => true );
			},
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nexora-rfq'] = array(
			'eraser_friendly_name' => __( 'Freshtastic quotation requests', 'nexora-commerce-toolkit' ),
			'callback'             => static function ( $email ) {
				$ids = self::by_email( $email );
				foreach ( $ids as $id ) {
					wp_delete_post( $id, true );
				}
				return array( 'items_removed' => (bool) $ids, 'items_retained' => false, 'messages' => array(), 'done' => true );
			},
		);
		return $erasers;
	}
}

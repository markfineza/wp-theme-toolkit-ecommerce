<?php
/**
 * Coupon wallet in the customer account: claim a code, or an offer the store has listed, then see
 * what is ready to use and the full claim history. Works on the built-in store and WooCommerce.
 *
 * Claiming saves a coupon to the account. It does not reserve a use or change the coupon's own rules,
 * which are still checked when the customer applies it to a cart.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coupon claims.
 */
class Nexora_Toolkit_Coupon_Claims {

	const META       = '_nx_coupon_claims';
	const CLAIMABLE  = '_nx_claimable';
	const COUNT      = '_nx_claim_count';
	const MAX_CLAIMS = 100;
	const MAX_TRIES  = 10; // Wrong codes per customer in FAIL_WINDOW, so codes cannot be guessed here.
	const FAIL_WINDOW = 900;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'wc_menu_item' ) );
		add_action( 'woocommerce_account_coupons_endpoint', array( __CLASS__, 'account_tab' ) );
		add_filter( 'nexora_store_account_menu', array( __CLASS__, 'native_menu_item' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_shop_coupon', array( __CLASS__, 'admin_box' ) );
		add_action( 'save_post_shop_coupon', array( __CLASS__, 'save_box' ), 20, 2 );
	}

	/* ------------------------------------------------------------------ routes */

	/**
	 * Register the account pages.
	 */
	public static function endpoint() {
		add_rewrite_endpoint( 'coupons', EP_PAGES );
		add_rewrite_endpoint( 'points', EP_PAGES );
	}

	/**
	 * Save the new addresses once, including after an update that adds them.
	 */
	public static function maybe_flush() {
		if ( '1' !== (string) get_option( 'nexora_wallet_routes' ) ) {
			update_option( 'nexora_wallet_routes', '1' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Tell WooCommerce about the pages.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars['coupons'] = 'coupons';
		if ( Nexora_Toolkit_Points::enabled() ) {
			$vars['points'] = 'points';
		}
		return $vars;
	}

	/**
	 * Put new menu entries straight after Orders.
	 *
	 * @param array $items Items.
	 * @param array $add   Entries to add.
	 * @return array
	 */
	private static function after_orders( $items, $add ) {
		$out = array();
		foreach ( $items as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'orders' === $key ) {
				$out += $add;
			}
		}
		return $out + $add;
	}

	/**
	 * WooCommerce menu.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function wc_menu_item( $items ) {
		$add = array( 'coupons' => __( 'Coupons', 'nexora-commerce-toolkit' ) );
		if ( Nexora_Toolkit_Points::enabled() ) {
			$add['points'] = __( 'Points', 'nexora-commerce-toolkit' );
		}
		return self::after_orders( $items, $add );
	}

	/**
	 * Built-in store menu.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function native_menu_item( $items ) {
		$add = array( 'coupons' => array( 'tag', __( 'Coupons', 'nexora-commerce-toolkit' ), self::url() ) );
		if ( Nexora_Toolkit_Points::enabled() ) {
			$add['points'] = array( 'star', __( 'Points', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Points::url() );
		}
		return self::after_orders( $items, $add );
	}

	/**
	 * Address of the Coupons page.
	 *
	 * @return string
	 */
	public static function url() {
		return Nexora_Store::active() ? Nexora_Store::url( 'account', 'coupons' ) : wc_get_account_endpoint_url( 'coupons' );
	}

	/**
	 * Styles on account pages.
	 */
	public static function assets() {
		$account = Nexora_Store::active() ? Nexora_Store::is_page( 'account' ) : ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( $account && is_user_logged_in() ) {
			wp_enqueue_style( 'nexora-wallet', NEXORA_TOOLKIT_URL . 'assets/css/wallet.css', array(), NEXORA_TOOLKIT_VERSION );
		}
	}

	/* -------------------------------------------------------------------- data */

	/**
	 * A customer's claims, newest first. A personal welcome reward counts as claimed.
	 *
	 * @param int $user User.
	 * @return array<int,array>
	 */
	public static function claims( $user ) {
		$claims = get_user_meta( $user, self::META, true );
		$claims = is_array( $claims ) ? $claims : array();
		$reward = get_user_meta( $user, Nexora_Toolkit_Rewards::USER_META, true );
		if ( ! empty( $reward['coupon'] ) && ! isset( $claims[ (int) $reward['coupon'] ] ) ) {
			$claims[ (int) $reward['coupon'] ] = self::claim_row( (int) $reward['coupon'], ! empty( $reward['at'] ) ? (int) $reward['at'] : time(), 'welcome' );
			update_user_meta( $user, self::META, $claims );
		}
		uasort(
			$claims,
			static function ( $a, $b ) {
				return (int) $b['at'] <=> (int) $a['at'];
			}
		);
		return $claims;
	}

	/**
	 * What is kept for a claim. Code and reward are copied, so history still reads well if the coupon is deleted.
	 *
	 * @param int    $coupon Coupon.
	 * @param int    $at     When.
	 * @param string $source code|offer|welcome.
	 * @return array
	 */
	private static function claim_row( $coupon, $at, $source ) {
		return array(
			'at'     => $at,
			'code'   => get_the_title( $coupon ),
			'reward' => Nexora_Toolkit_Rewards::reward_text( $coupon ),
			'source' => $source,
		);
	}

	/**
	 * How many times this customer has used a coupon.
	 *
	 * @param int $coupon Coupon.
	 * @param int $user   User.
	 * @return int
	 */
	public static function uses( $coupon, $user ) {
		$account = get_userdata( $user );
		$mine    = array_filter( array( (string) $user, $account ? strtolower( $account->user_email ) : '' ) );
		$count   = 0;
		foreach ( (array) get_post_meta( $coupon, '_used_by' ) as $who ) {
			$count += in_array( strtolower( (string) $who ), $mine, true ) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Where a claimed coupon stands for this customer.
	 *
	 * @param int $coupon Coupon.
	 * @param int $user   User.
	 * @return array{key:string,label:string,uses:int}
	 */
	public static function state( $coupon, $user ) {
		$uses = 'publish' === get_post_status( $coupon ) ? self::uses( $coupon, $user ) : 0;
		$make = static function ( $key, $label ) use ( $uses ) {
			return array(
				'key'   => $key,
				'label' => $label,
				'uses'  => $uses,
			);
		};
		if ( 'publish' !== get_post_status( $coupon ) ) {
			return $make( 'gone', __( 'No longer available', 'nexora-commerce-toolkit' ) );
		}
		$per_user = (int) get_post_meta( $coupon, 'usage_limit_per_user', true );
		$limit    = (int) get_post_meta( $coupon, 'usage_limit', true );
		$full     = $limit && (int) get_post_meta( $coupon, 'usage_count', true ) >= $limit;
		$expires  = (int) get_post_meta( $coupon, 'date_expires', true );
		if ( $uses && ( ( $per_user && $uses >= $per_user ) || $full ) ) {
			return $make( 'used', __( 'Used', 'nexora-commerce-toolkit' ) );
		}
		if ( $expires && time() > $expires ) {
			return $make( 'expired', __( 'Expired', 'nexora-commerce-toolkit' ) );
		}
		if ( $full ) {
			return $make( 'full', __( 'Fully used', 'nexora-commerce-toolkit' ) );
		}
		return $make( 'ready', __( 'Ready to use', 'nexora-commerce-toolkit' ) );
	}

	/**
	 * Claimed coupons still ready to use.
	 *
	 * @param int $user User.
	 * @return int
	 */
	public static function ready_count( $user ) {
		$count = 0;
		foreach ( array_keys( self::claims( $user ) ) as $coupon ) {
			$count += 'ready' === self::state( $coupon, $user )['key'] ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Offers this customer can claim from the list.
	 *
	 * @param int $user User.
	 * @return int[]
	 */
	public static function offers( $user ) {
		$ids     = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'publish',
				'numberposts' => 30,
				'fields'      => 'ids',
				'meta_key'    => self::CLAIMABLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$claimed = self::claims( $user );
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) use ( $claimed, $user ) {
					return ! isset( $claimed[ $id ] ) && ! is_wp_error( self::can_claim( $id, $user ) );
				}
			)
		);
	}

	/**
	 * Can this customer claim this coupon? A coupon meant for someone else reads the same as a wrong code,
	 * so this form never reveals that another customer's code exists.
	 *
	 * @param int $coupon Coupon.
	 * @param int $user   User.
	 * @return true|WP_Error
	 */
	private static function can_claim( $coupon, $user ) {
		$owner = Nexora_Toolkit_Rewards::owner( $coupon );
		$found = Nexora_Store_Coupons::find( get_the_title( $coupon ) );
		if ( ! $found || ( $owner && $owner !== (int) $user ) || ( $found['emails'] && ! Nexora_Store_Coupons::email_allowed( $found['emails'], $user, '' ) ) ) {
			return new WP_Error( 'nx_claim_code', __( 'That code is not valid. Please check it and try again.', 'nexora-commerce-toolkit' ) );
		}
		$state = self::state( $coupon, $user );
		if ( 'expired' === $state['key'] ) {
			return new WP_Error( 'nx_claim_expired', __( 'This coupon has expired.', 'nexora-commerce-toolkit' ) );
		}
		if ( 'used' === $state['key'] ) {
			return new WP_Error( 'nx_claim_used', __( 'You have already used this coupon.', 'nexora-commerce-toolkit' ) );
		}
		if ( 'ready' !== $state['key'] ) {
			return new WP_Error( 'nx_claim_full', __( 'This coupon has reached its usage limit.', 'nexora-commerce-toolkit' ) );
		}
		return true;
	}

	/**
	 * Save a claim.
	 *
	 * @param int    $coupon Coupon.
	 * @param int    $user   User.
	 * @param string $source code|offer.
	 * @return true|WP_Error
	 */
	public static function claim( $coupon, $user, $source ) {
		$allowed = self::can_claim( $coupon, $user );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$claims = self::claims( $user );
		if ( isset( $claims[ $coupon ] ) ) {
			return new WP_Error( 'nx_claim_twice', __( 'You have already claimed this coupon.', 'nexora-commerce-toolkit' ) );
		}
		if ( count( $claims ) >= self::MAX_CLAIMS ) {
			return new WP_Error( 'nx_claim_max', __( 'Your coupon list is full. Please contact us for help.', 'nexora-commerce-toolkit' ) );
		}
		$claims[ $coupon ] = self::claim_row( $coupon, time(), $source );
		update_user_meta( $user, self::META, $claims );
		update_post_meta( $coupon, self::COUNT, (int) get_post_meta( $coupon, self::COUNT, true ) + 1 );
		return true;
	}

	/* --------------------------------------------------------------- requests */

	/**
	 * Show a message on the next page.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		if ( Nexora_Store::active() ) {
			Nexora_Store_Cart::notice( $type, $message );
		} elseif ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( esc_html( $message ), $type );
		}
	}

	/**
	 * Claim and apply buttons.
	 */
	public static function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
		$action = isset( $_POST['nx_wallet_action'] ) ? sanitize_key( wp_unslash( $_POST['nx_wallet_action'] ) ) : '';
		if ( ! in_array( $action, array( 'claim_code', 'claim_offer', 'apply' ), true ) || ! is_user_logged_in() ) {
			return;
		}
		$user = get_current_user_id();
		if ( ! isset( $_POST['_nx_wallet'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_nx_wallet'] ) ), 'nexora_wallet' ) ) {
			self::notice( 'error', __( 'Your session expired. Please try again.', 'nexora-commerce-toolkit' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		$coupon = isset( $_POST['coupon'] ) ? absint( $_POST['coupon'] ) : 0;
		if ( 'apply' === $action ) {
			self::apply( $coupon, $user );
		}
		if ( 'claim_offer' === $action ) {
			// Only listed offers can be claimed by ID, otherwise this would hand out private codes.
			$result = 'yes' === get_post_meta( $coupon, self::CLAIMABLE, true ) && 'shop_coupon' === get_post_type( $coupon )
				? self::claim( $coupon, $user, 'offer' )
				: new WP_Error( 'nx_claim_code', __( 'That offer is no longer available.', 'nexora-commerce-toolkit' ) );
		} else {
			$result = self::claim_by_code( isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '', $user );
		}
		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );
		} else {
			self::notice( 'success', __( 'Coupon claimed. It is saved below, ready to use.', 'nexora-commerce-toolkit' ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Claim a typed code, with a limit on wrong tries.
	 *
	 * @param string $code Code.
	 * @param int    $user User.
	 * @return true|WP_Error
	 */
	private static function claim_by_code( $code, $user ) {
		$key   = 'nx_claim_fail_' . $user;
		$tries = (int) get_transient( $key );
		if ( $tries >= self::MAX_TRIES ) {
			return new WP_Error( 'nx_claim_wait', __( 'Too many tries. Please wait 15 minutes and try again.', 'nexora-commerce-toolkit' ) );
		}
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'nx_claim_empty', __( 'Please enter a coupon code.', 'nexora-commerce-toolkit' ) );
		}
		$found  = Nexora_Store_Coupons::find( $code );
		$result = $found ? self::claim( (int) $found['id'], $user, 'code' ) : new WP_Error( 'nx_claim_code', __( 'That code is not valid. Please check it and try again.', 'nexora-commerce-toolkit' ) );
		if ( is_wp_error( $result ) && 'nx_claim_code' === $result->get_error_code() ) {
			set_transient( $key, $tries + 1, self::FAIL_WINDOW );
		}
		return $result;
	}

	/**
	 * Apply a claimed coupon to the cart and go there. The coupon's own rules decide if it fits.
	 *
	 * @param int $coupon Coupon.
	 * @param int $user   User.
	 */
	private static function apply( $coupon, $user ) {
		$claims = self::claims( $user );
		if ( ! isset( $claims[ $coupon ] ) || 'publish' !== get_post_status( $coupon ) ) {
			self::notice( 'error', __( 'That coupon is not in your list.', 'nexora-commerce-toolkit' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		$code = get_the_title( $coupon );
		if ( Nexora_Store::active() ) {
			$result = Nexora_Store_Cart::apply_coupon( $code );
			self::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Coupon applied.', 'nexora-commerce-toolkit' ) );
			wp_safe_redirect( Nexora_Store::url( 'cart' ) );
			exit;
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->apply_coupon( $code );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Is the cart empty?
	 *
	 * @return bool
	 */
	private static function cart_empty() {
		if ( Nexora_Store::active() ) {
			return ! Nexora_Store_Cart::count();
		}
		return ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty();
	}

	/**
	 * Is this code on the cart already?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	private static function in_cart( $code ) {
		if ( Nexora_Store::active() ) {
			return in_array( strtolower( $code ), array_map( 'strtolower', Nexora_Store_Cart::coupon_codes() ), true );
		}
		return function_exists( 'WC' ) && WC()->cart && WC()->cart->has_discount( $code );
	}

	/**
	 * Shop page.
	 *
	 * @return string
	 */
	private static function shop_url() {
		return Nexora_Store::active() ? Nexora_Store::shop_url() : wc_get_page_permalink( 'shop' );
	}

	/* ---------------------------------------------------------------- screens */

	/**
	 * The hidden fields every wallet form needs.
	 *
	 * @param string $action Action.
	 * @param int    $coupon Coupon.
	 */
	private static function fields( $action, $coupon = 0 ) {
		wp_nonce_field( 'nexora_wallet', '_nx_wallet', false );
		echo '<input type="hidden" name="nx_wallet_action" value="' . esc_attr( $action ) . '">';
		if ( $coupon ) {
			echo '<input type="hidden" name="coupon" value="' . esc_attr( (string) $coupon ) . '">';
		}
	}

	/**
	 * The claim form.
	 *
	 * @param string $id Field ID.
	 */
	private static function claim_form( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="nx-claim-form">
			<?php self::fields( 'claim_code' ); ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Coupon code', 'nexora-commerce-toolkit' ); ?></label>
			<div class="nx-claim-form__row">
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="code" class="input-text" maxlength="60" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
				<button type="submit" class="button nx-wallet-btn"><?php esc_html_e( 'Claim', 'nexora-commerce-toolkit' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Words for a coupon's limits.
	 *
	 * @param int $coupon Coupon.
	 * @return string
	 */
	private static function terms( $coupon ) {
		$parts = array();
		$min   = (float) get_post_meta( $coupon, 'minimum_amount', true );
		if ( $min > 0 ) {
			/* translators: %s: amount */
			$parts[] = sprintf( __( 'Minimum order %s.', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Analytics_Data::money( $min ) );
		}
		if ( array_filter( (array) get_post_meta( $coupon, 'product_categories', true ) ) || array_filter( (array) get_post_meta( $coupon, 'product_ids', true ) ) ) {
			$parts[] = __( 'On selected items.', 'nexora-commerce-toolkit' );
		}
		$expires = (int) get_post_meta( $coupon, 'date_expires', true );
		if ( $expires ) {
			/* translators: %s: date */
			$parts[] = sprintf( __( 'Use by %s.', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ), $expires ) );
		}
		$per_user = (int) get_post_meta( $coupon, 'usage_limit_per_user', true );
		if ( 1 === $per_user ) {
			$parts[] = __( 'One use per customer.', 'nexora-commerce-toolkit' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * The Coupons page.
	 */
	public static function account_tab() {
		$user    = get_current_user_id();
		$claims  = self::claims( $user );
		$offers  = self::offers( $user );
		$states  = array();
		foreach ( array_keys( $claims ) as $coupon ) {
			$states[ $coupon ] = self::state( $coupon, $user );
		}
		$ready   = array_keys(
			array_filter(
				$states,
				static function ( $state ) {
					return 'ready' === $state['key'];
				}
			)
		);
		$empty   = self::cart_empty();
		$sources = array(
			'code'    => __( 'Code entered', 'nexora-commerce-toolkit' ),
			'offer'   => __( 'Store offer', 'nexora-commerce-toolkit' ),
			'welcome' => __( 'Welcome reward', 'nexora-commerce-toolkit' ),
		);
		?>
		<div class="nx-wallet">
			<header class="nx-wallet__head">
				<h2><?php esc_html_e( 'Coupons', 'nexora-commerce-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Claim a code to save it to your account, then use it on your next order.', 'nexora-commerce-toolkit' ); ?></p>
			</header>

			<section class="nx-wallet-card nx-wallet-card--claim" aria-labelledby="nx-claim-title">
				<h3 id="nx-claim-title"><?php esc_html_e( 'Claim a coupon', 'nexora-commerce-toolkit' ); ?></h3>
				<?php self::claim_form( 'nx-claim-code' ); ?>
			</section>

			<?php if ( $offers ) : ?>
				<section aria-labelledby="nx-offers-title">
					<h3 id="nx-offers-title"><?php esc_html_e( 'Offers you can claim', 'nexora-commerce-toolkit' ); ?></h3>
					<ul class="nx-tickets">
						<?php foreach ( $offers as $coupon ) : ?>
							<li class="nx-ticket">
								<div class="nx-ticket__body">
									<p class="nx-ticket__title"><?php echo esc_html( Nexora_Toolkit_Rewards::reward_text( $coupon ) ); ?></p>
									<?php if ( self::terms( $coupon ) ) : ?><p class="nx-ticket__terms"><?php echo esc_html( self::terms( $coupon ) ); ?></p><?php endif; ?>
								</div>
								<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="nx-ticket__actions">
									<?php self::fields( 'claim_offer', $coupon ); ?>
									<button type="submit" class="button nx-wallet-btn"><?php esc_html_e( 'Claim offer', 'nexora-commerce-toolkit' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section aria-labelledby="nx-ready-title">
				<h3 id="nx-ready-title"><?php esc_html_e( 'Ready to use', 'nexora-commerce-toolkit' ); ?></h3>
				<?php if ( $ready ) : ?>
					<ul class="nx-tickets">
						<?php
						foreach ( $ready as $coupon ) :
							$code = get_the_title( $coupon );
							?>
							<li class="nx-ticket is-ready">
								<div class="nx-ticket__body">
									<p class="nx-ticket__title"><?php echo esc_html( Nexora_Toolkit_Rewards::reward_text( $coupon ) ); ?></p>
									<?php if ( self::terms( $coupon ) ) : ?><p class="nx-ticket__terms"><?php echo esc_html( self::terms( $coupon ) ); ?></p><?php endif; ?>
									<button type="button" class="nx-ticket__code" data-nexora-copy="<?php echo esc_attr( $code ); ?>"><code><?php echo esc_html( $code ); ?></code> <span data-nexora-copy-label><?php esc_html_e( 'Copy code', 'nexora-commerce-toolkit' ); ?></span></button>
								</div>
								<div class="nx-ticket__actions">
									<?php if ( self::in_cart( $code ) ) : ?>
										<span class="nx-ticket__note"><?php esc_html_e( 'In your cart', 'nexora-commerce-toolkit' ); ?></span>
									<?php elseif ( $empty ) : ?>
										<a class="button nx-wallet-btn" href="<?php echo esc_url( self::shop_url() ); ?>"><?php esc_html_e( 'Shop now', 'nexora-commerce-toolkit' ); ?></a>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( self::url() ); ?>">
											<?php self::fields( 'apply', $coupon ); ?>
											<button type="submit" class="button nx-wallet-btn"><?php esc_html_e( 'Use on my cart', 'nexora-commerce-toolkit' ); ?></button>
										</form>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="nx-wallet-empty"><?php esc_html_e( 'You have no coupons ready to use. Claimed coupons will appear here.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>

			<section aria-labelledby="nx-history-title">
				<h3 id="nx-history-title"><?php esc_html_e( 'Claim history', 'nexora-commerce-toolkit' ); ?></h3>
				<?php if ( $claims ) : ?>
					<div class="nx-wallet-scroll" tabindex="0" role="group" aria-labelledby="nx-history-title">
						<table class="nx-wallet-table">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Code', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Reward', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Claimed', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'From', 'nexora-commerce-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $claims as $coupon => $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['code'] ); ?></code></td>
									<td><?php echo esc_html( $row['reward'] ); ?></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
									<td><?php echo esc_html( isset( $sources[ $row['source'] ] ) ? $sources[ $row['source'] ] : '' ); ?></td>
									<td>
										<span class="nx-wallet-status is-<?php echo esc_attr( $states[ $coupon ]['key'] ); ?>"><?php echo esc_html( $states[ $coupon ]['label'] ); ?></span>
										<?php if ( 'ready' === $states[ $coupon ]['key'] && $states[ $coupon ]['uses'] ) : ?>
											<?php /* translators: %d: number of times used */ ?>
											<small><?php echo esc_html( sprintf( _n( 'Used %d time', 'Used %d times', $states[ $coupon ]['uses'], 'nexora-commerce-toolkit' ), $states[ $coupon ]['uses'] ) ); ?></small>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="nx-wallet-empty"><?php esc_html_e( 'Coupons you claim will be listed here.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Rewards panel for the dashboard: points, coupons and a quick claim box.
	 */
	public static function dashboard_panel() {
		$user   = get_current_user_id();
		$ready  = self::ready_count( $user );
		$points = Nexora_Toolkit_Points::enabled();
		?>
		<section class="nx-dash-panel nx-wallet-panel" aria-labelledby="nx-wallet-panel-title">
			<header class="nx-dash-panel__head">
				<h2 id="nx-wallet-panel-title"><?php esc_html_e( 'Your rewards', 'nexora-commerce-toolkit' ); ?></h2>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'View coupons', 'nexora-commerce-toolkit' ); ?></a>
			</header>
			<ul class="nx-wallet-panel__nums">
				<li><a href="<?php echo esc_url( self::url() ); ?>"><strong><?php echo esc_html( number_format_i18n( $ready ) ); ?></strong><span><?php esc_html_e( 'Coupons ready', 'nexora-commerce-toolkit' ); ?></span></a></li>
				<?php if ( $points ) : ?>
					<li><a href="<?php echo esc_url( Nexora_Toolkit_Points::url() ); ?>"><strong><?php echo esc_html( number_format_i18n( Nexora_Toolkit_Points::balance( $user ) ) ); ?></strong><span><?php esc_html_e( 'Points earned', 'nexora-commerce-toolkit' ); ?></span></a></li>
				<?php endif; ?>
			</ul>
			<?php self::claim_form( 'nx-claim-code-dash' ); ?>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------- admin */

	/**
	 * Box on the coupon screen.
	 */
	public static function admin_box() {
		add_meta_box( 'nx-coupon-claim', __( 'Customer accounts', 'nexora-commerce-toolkit' ), array( __CLASS__, 'render_box' ), 'shop_coupon', 'side', 'default' );
	}

	/**
	 * Box contents.
	 *
	 * @param WP_Post $post Coupon.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'nexora_claim_box', '_nx_claim_box' );
		?>
		<p><label><input type="checkbox" name="nx_claimable" value="yes" <?php checked( 'yes', get_post_meta( $post->ID, self::CLAIMABLE, true ) ); ?>> <?php esc_html_e( 'List as an offer customers can claim', 'nexora-commerce-toolkit' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'It shows on their Coupons page. Customers can also claim any valid code by typing it. Claiming does not reserve a use.', 'nexora-commerce-toolkit' ); ?></p>
		<?php /* translators: %s: number of customers */ ?>
		<p><?php echo esc_html( sprintf( __( 'Claimed by: %s', 'nexora-commerce-toolkit' ), number_format_i18n( (int) get_post_meta( $post->ID, self::COUNT, true ) ) ) ); ?></p>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_box( $post_id, $post ) {
		if ( ! isset( $_POST['_nx_claim_box'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_nx_claim_box'] ) ), 'nexora_claim_box' ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['nx_claimable'] ) ) {
			update_post_meta( $post->ID, self::CLAIMABLE, 'yes' );
		} else {
			delete_post_meta( $post->ID, self::CLAIMABLE );
		}
	}
}

<?php
/**
 * Meta (Facebook and Instagram) connection for the shop, with or without WooCommerce.
 *
 * - Catalog sync: products are sent to a Meta catalog (Commerce Manager) through the Catalog Batch
 *   API when they are saved, sold out or removed, so they can be used in Facebook and Instagram
 *   shops and ads.
 * - Data feed: a private CSV address Commerce Manager can read on a schedule (no token needed).
 * - Auto-post: a product marked "Share" is posted to the Facebook Page and/or Instagram once it is
 *   published.
 *
 * The access token and app secret are write-only in the admin, can be set as wp-config constants
 * and are only ever sent to graph.facebook.com.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta connector.
 */
class Nexora_Toolkit_Meta {

	const OPTION  = 'nexora_meta';
	const QUEUE   = 'nexora_meta_queue';
	const LOG     = 'nexora_meta_log';
	const PAGE    = 'nexora-meta';
	const CRON    = 'nexora_meta_run';
	const CHECK   = 'nexora_meta_check';
	const BATCH   = 500;
	const SECRETS = array(
		'token'      => 'NEXORA_META_ACCESS_TOKEN',
		'app_secret' => 'NEXORA_META_APP_SECRET',
	);

	/**
	 * Page access token for this request.
	 *
	 * @var string|null
	 */
	private static $page_token = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_nexora_meta_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_nexora_meta_sync_all', array( __CLASS__, 'handle_sync_all' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( self::CHECK, array( __CLASS__, 'check' ), 10, 2 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_feed' ) ); // After post types, so links are pretty permalinks.

		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_box' ), 15, 2 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'after_save' ), 20, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'removed' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'removed' ) );
		add_action( 'nexora_store_stock_changed', array( __CLASS__, 'stock_changed' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'wc_stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'wc_stock_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'stock_changed' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEXORA_TOOLKIT_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'token'        => '',
			'app_secret'   => '',
			'api_version'  => 'v25.0',
			'catalog_id'   => '',
			'page_id'      => '',
			'ig_id'        => '',
			'catalog_sync' => true,
			'brand'        => '',
			'condition'    => 'new',
			'skip_demo'    => true,
			'feed'         => true,
			'feed_key'     => '',
			'post_fb'      => false,
			'post_ig'      => false,
			'share_new'    => true,
			'template'     => '',
		);
	}

	/**
	 * Default post text.
	 *
	 * @return string
	 */
	public static function default_template() {
		/* translators: keep the {placeholders} as they are. */
		return __( "New at {site_name}: {title}\nPrice: {price}\n\n{short_description}\n\nOrder here: {link}", 'nexora-commerce-toolkit' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		foreach ( self::SECRETS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$s[ $key ] = (string) constant( $constant );
			}
		}
		if ( '' === $s['feed_key'] ) {
			$s['feed_key'] = wp_generate_password( 24, false );
			$raw           = (array) get_option( self::OPTION, array() );
			$raw           = wp_parse_args( $raw, self::defaults() );
			$raw['feed_key'] = $s['feed_key'];
			update_option( self::OPTION, $raw, false );
		}
		return $s;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/**
	 * Token and catalog are set.
	 *
	 * @return bool
	 */
	public static function catalog_ready() {
		$s = self::settings();
		return $s['catalog_sync'] && '' !== $s['token'] && '' !== $s['catalog_id'];
	}

	/**
	 * Auto-posting is switched on for at least one channel with an ID.
	 *
	 * @return bool
	 */
	public static function posting_ready() {
		$s = self::settings();
		return '' !== $s['token'] && ( ( $s['post_fb'] && '' !== $s['page_id'] ) || ( $s['post_ig'] && '' !== $s['ig_id'] ) );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting( 'nexora_meta', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Sanitize. Empty secret inputs keep the stored value.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array(); // options.php has already unslashed it.
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$out     = self::defaults();

		foreach ( array_keys( self::SECRETS ) as $key ) {
			$value       = isset( $input[ $key ] ) ? preg_replace( '/\s+/', '', sanitize_text_field( $input[ $key ] ) ) : '';
			$out[ $key ] = '' !== $value ? $value : $current[ $key ];
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			}
		}
		$version            = isset( $input['api_version'] ) ? strtolower( trim( sanitize_text_field( $input['api_version'] ) ) ) : '';
		$out['api_version'] = preg_match( '/^v\d{2,3}\.\d$/', $version ) ? $version : self::defaults()['api_version'];
		foreach ( array( 'catalog_id', 'page_id', 'ig_id' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? preg_replace( '/\D/', '', (string) $input[ $key ] ) : '';
		}
		foreach ( array( 'catalog_sync', 'skip_demo', 'feed', 'post_fb', 'post_ig', 'share_new' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}
		$out['brand']     = isset( $input['brand'] ) ? mb_substr( sanitize_text_field( $input['brand'] ), 0, 100 ) : '';
		$out['condition'] = isset( $input['condition'] ) && in_array( $input['condition'], array( 'new', 'refurbished', 'used' ), true ) ? $input['condition'] : 'new';
		$template         = isset( $input['template'] ) ? trim( sanitize_textarea_field( $input['template'] ) ) : '';
		$out['template']  = $template === self::default_template() ? '' : mb_substr( $template, 0, 2000 );
		$out['feed_key']  = ! empty( $input['feed_new'] ) || '' === $current['feed_key'] ? wp_generate_password( 24, false ) : $current['feed_key'];

		if ( $out['catalog_sync'] && ( $out['catalog_id'] !== $current['catalog_id'] || ( '' === $current['token'] && '' !== $out['token'] ) ) && '' !== $out['catalog_id'] ) {
			// A new catalog: send everything once.
			add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'queue_all' ) );
		}
		return $out;
	}

	/* -------------------------------------------------------------- Graph API */

	/**
	 * Call the Graph API.
	 *
	 * @param string      $method GET|POST.
	 * @param string      $path   Path after the version, e.g. "123/items_batch".
	 * @param array       $params Parameters.
	 * @param string|null $token  Token (default: the saved one).
	 * @return array|WP_Error
	 */
	public static function api( $method, $path, $params = array(), $token = null ) {
		$s     = self::settings();
		$token = null === $token ? $s['token'] : $token;
		if ( '' === (string) $token ) {
			return new WP_Error( 'nexora_meta', __( 'No Meta access token is saved.', 'nexora-commerce-toolkit' ) );
		}
		$params['access_token'] = $token;
		if ( '' !== $s['app_secret'] ) {
			$params['appsecret_proof'] = hash_hmac( 'sha256', $token, $s['app_secret'] );
		}
		$url  = 'https://graph.facebook.com/' . $s['api_version'] . '/' . ltrim( $path, '/' );
		$args = array(
			'timeout' => 30,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url . '?' . http_build_query( $params, '', '&' ), $args );
		} else {
			$args['body'] = $params;
			$response     = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error */
			return new WP_Error( 'nexora_meta', sprintf( __( 'Could not reach Meta: %s', 'nexora-commerce-toolkit' ), $response->get_error_message() ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			/* translators: %d: HTTP status */
			return new WP_Error( 'nexora_meta', sprintf( __( 'Meta sent an unexpected answer (HTTP %d).', 'nexora-commerce-toolkit' ), (int) wp_remote_retrieve_response_code( $response ) ) );
		}
		if ( isset( $body['error'] ) ) {
			$e   = (array) $body['error'];
			$msg = isset( $e['error_user_msg'] ) ? $e['error_user_msg'] : ( isset( $e['message'] ) ? $e['message'] : __( 'Unknown error', 'nexora-commerce-toolkit' ) );
			return new WP_Error( 'nexora_meta', sprintf( '(#%s) %s', isset( $e['code'] ) ? (int) $e['code'] : 0, wp_strip_all_tags( (string) $msg ) ), $e );
		}
		return $body;
	}

	/**
	 * Page access token (asked from Meta with the saved token; a Page token is used as it is).
	 *
	 * @return string|WP_Error
	 */
	private static function page_token() {
		if ( null !== self::$page_token ) {
			return self::$page_token;
		}
		$page = self::api( 'GET', self::get( 'page_id' ), array( 'fields' => 'access_token' ) );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		self::$page_token = ! empty( $page['access_token'] ) ? (string) $page['access_token'] : (string) self::get( 'token' );
		return self::$page_token;
	}

	/* ------------------------------------------------------------- products */

	/**
	 * Catalog ID (retailer ID) of a product or variation: the SKU, else the post ID. It is the same
	 * ID the analytics events send, so Meta can match visits and sales to catalog items.
	 *
	 * @param Nexora_Store_Product $p Product.
	 * @return string
	 */
	public static function retailer_id( $p ) {
		$id = '' !== $p->get_sku() ? $p->get_sku() : (string) $p->get_id();
		return (string) apply_filters( 'nexora_meta_retailer_id', mb_substr( $id, 0, 100 ), $p );
	}

	/**
	 * Why a product is not sent to the catalog, or '' when it is.
	 *
	 * @param Nexora_Store_Product $p Product.
	 * @return string
	 */
	public static function skip_reason( $p ) {
		if ( 'publish' !== $p->post->post_status || ! empty( $p->post->post_password ) ) {
			return __( 'Not published.', 'nexora-commerce-toolkit' );
		}
		if ( 'yes' === $p->get_meta( '_nx_meta_exclude' ) ) {
			return __( 'Hidden from Facebook and Instagram on this product.', 'nexora-commerce-toolkit' );
		}
		if ( has_term( 'exclude-from-catalog', 'product_visibility', $p->post ) ) {
			return __( 'Hidden from the shop.', 'nexora-commerce-toolkit' );
		}
		if ( self::get( 'skip_demo' ) && $p->get_meta( '_nexora_demo' ) ) {
			return __( 'Demo product.', 'nexora-commerce-toolkit' );
		}
		if ( ! $p->get_image_id() ) {
			return __( 'Add a product image. Meta needs one.', 'nexora-commerce-toolkit' );
		}
		// Not is_purchasable(): maintenance mode switches that off, and the catalog should stay.
		if ( $p->is_type( 'variable' ) ? ! $p->get_variations() : '' === $p->get_price() ) {
			return __( 'No price.', 'nexora-commerce-toolkit' );
		}
		return '';
	}

	/**
	 * Store currency.
	 *
	 * @return string
	 */
	private static function currency() {
		if ( class_exists( 'WooCommerce' ) && function_exists( 'get_woocommerce_currency' ) ) {
			return get_woocommerce_currency();
		}
		return (string) Nexora_Store::get( 'currency' );
	}

	/**
	 * Price as Meta wants it: "125.00 PHP".
	 *
	 * @param string|float $amount Amount.
	 * @return string
	 */
	private static function meta_price( $amount ) {
		return number_format( (float) $amount, 2, '.', '' ) . ' ' . self::currency();
	}

	/**
	 * Price as shown to people, e.g. "₱125.00".
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return Nexora_Store::money( $amount );
	}

	/**
	 * Plain text, at most $max characters.
	 *
	 * @param string $html Text.
	 * @param int    $max  Limit.
	 * @return string
	 */
	private static function plain( $html, $max ) {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $html ), true ), ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/[ \t]+/', ' ', $text ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Catalog items for a product (one, or one per option).
	 *
	 * @param Nexora_Store_Product $p Product.
	 * @return array[]
	 */
	public static function items( $p ) {
		$s     = self::settings();
		$brand = '' !== $s['brand'] ? $s['brand'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$desc  = self::plain( '' !== trim( $p->get_short_description() ) ? $p->get_short_description() : $p->get_description(), 5000 );
		$desc  = '' !== $desc ? $desc : $p->get_name();
		$terms = get_the_terms( $p->get_id(), 'product_cat' );
		$extra = array();
		foreach ( array_slice( $p->get_gallery_image_ids(), 0, 10 ) as $img ) {
			$url = wp_get_attachment_image_url( $img, 'full' );
			if ( $url ) {
				$extra[] = $url;
			}
		}
		$base = array(
			'description'  => $desc,
			'condition'    => $s['condition'],
			'link'         => $p->get_permalink(),
			'brand'        => mb_substr( $brand, 0, 100 ),
			'product_type' => $terms && ! is_wp_error( $terms ) ? $terms[0]->name : '',
		);
		if ( $extra ) {
			$base['additional_image_link'] = $extra;
		}

		$rows = $p->is_type( 'variable' ) ? $p->get_variations() : array( $p );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( '' === $row->get_price() ) {
				continue;
			}
			$label = $row->get_parent_id() ? $row->option_label() : '';
			$item  = $base + array(
				'id'           => self::retailer_id( $row ),
				'title'        => self::plain( $p->get_name() . ( '' !== $label ? ' – ' . $label : '' ), 100 ),
				'availability' => $row->is_in_stock() ? 'in stock' : 'out of stock',
				'price'        => self::meta_price( '' !== $row->get_regular_price() ? $row->get_regular_price() : $row->get_price() ),
				'image_link'   => (string) wp_get_attachment_image_url( $row->get_image_id(), 'full' ),
			);
			if ( $row->is_on_sale() ) {
				$item['sale_price'] = self::meta_price( $row->get_sale_price() );
				$from               = (int) $row->get_meta( '_sale_price_dates_from' );
				$to                 = (int) $row->get_meta( '_sale_price_dates_to' );
				if ( $from && $to ) {
					$item['sale_price_effective_date'] = wp_date( 'Y-m-d\TH:iO', $from ) . '/' . wp_date( 'Y-m-d\TH:iO', $to );
				}
			}
			if ( $row->get_parent_id() ) {
				$item['item_group_id'] = self::retailer_id( $p );
			}
			if ( '' === $item['product_type'] ) {
				unset( $item['product_type'] );
			}
			$out[] = (array) apply_filters( 'nexora_meta_item', $item, $row, $p );
		}
		return $out;
	}

	/* --------------------------------------------------------------- queue */

	/**
	 * Queue.
	 *
	 * @return array{sync:array,delete:array,post:array}
	 */
	private static function queue() {
		return wp_parse_args( (array) get_option( self::QUEUE, array() ), array( 'sync' => array(), 'delete' => array(), 'post' => array() ) );
	}

	/**
	 * Add to the queue and make sure a run is scheduled.
	 *
	 * @param string     $list sync|delete|post.
	 * @param int|string $key  Product ID or retailer ID.
	 */
	public static function enqueue( $list, $key ) {
		$q                  = self::queue();
		$q[ $list ][ $key ] = 1;
		update_option( self::QUEUE, $q, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 10, self::CRON );
		}
	}

	/**
	 * Queue every product for the catalog.
	 */
	public static function queue_all() {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		$q   = self::queue();
		foreach ( $ids as $id ) {
			$q['sync'][ (int) $id ] = 1;
		}
		update_option( self::QUEUE, $q, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
		return count( $ids );
	}

	/**
	 * A product was saved: queue the catalog update and a share.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function after_save( $post_id, $post ) {
		if ( ! $post || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( self::catalog_ready() && ( 'publish' === $post->post_status || get_post_meta( $post_id, '_nx_meta_ids', true ) ) ) {
			self::enqueue( 'sync', (int) $post_id );
		}
		if ( 'publish' === $post->post_status && 'yes' === get_post_meta( $post_id, '_nx_meta_share', true ) && self::posting_ready() ) {
			self::enqueue( 'post', (int) $post_id );
		}
	}

	/**
	 * A product is going to the trash or being deleted: remove its items from the catalog.
	 *
	 * @param int $post_id Post.
	 */
	public static function removed( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) || ! self::catalog_ready() ) {
			return;
		}
		foreach ( (array) get_post_meta( $post_id, '_nx_meta_ids', true ) as $rid ) {
			if ( '' !== (string) $rid ) {
				self::enqueue( 'delete', (string) $rid );
			}
		}
		delete_post_meta( $post_id, '_nx_meta_ids' );
		$q = self::queue();
		unset( $q['sync'][ (int) $post_id ], $q['post'][ (int) $post_id ] );
		update_option( self::QUEUE, $q, false );
	}

	/**
	 * Stock changed through an order.
	 *
	 * @param int $id Product or variation ID.
	 */
	public static function stock_changed( $id ) {
		if ( ! self::catalog_ready() ) {
			return;
		}
		$id     = (int) $id;
		$parent = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
		if ( $parent && 'publish' === get_post_status( $parent ) ) {
			self::enqueue( 'sync', $parent );
		}
	}

	/**
	 * WooCommerce stock change (passes the product object).
	 *
	 * @param WC_Product $product Product.
	 */
	public static function wc_stock_changed( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::stock_changed( $product->get_id() );
		}
	}

	/**
	 * Work through the queue: one catalog batch, deletions and up to 5 shares per run.
	 */
	public static function run() {
		if ( get_transient( 'nexora_meta_lock' ) ) {
			wp_schedule_single_event( time() + 60, self::CRON );
			return;
		}
		set_transient( 'nexora_meta_lock', 1, 5 * MINUTE_IN_SECONDS );

		$q     = self::queue();
		$sync  = array_slice( array_keys( $q['sync'] ), 0, self::BATCH );
		$dels  = array_slice( array_keys( $q['delete'] ), 0, self::BATCH * 2 );
		$posts = array_slice( array_keys( $q['post'] ), 0, 5 );
		foreach ( $sync as $id ) {
			unset( $q['sync'][ $id ] );
		}
		foreach ( $dels as $rid ) {
			unset( $q['delete'][ $rid ] );
		}
		foreach ( $posts as $id ) {
			unset( $q['post'][ $id ] );
		}
		update_option( self::QUEUE, $q, false );

		if ( self::catalog_ready() && ( $sync || $dels ) ) {
			self::send_batch( array_map( 'intval', $sync ), array_map( 'strval', $dels ) );
		}
		foreach ( $posts as $id ) {
			self::share( (int) $id );
		}

		delete_transient( 'nexora_meta_lock' );
		$q = self::queue();
		if ( ( $q['sync'] || $q['delete'] || $q['post'] ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/**
	 * Send products and deletions to the catalog in one batch.
	 *
	 * @param int[]    $ids  Products.
	 * @param string[] $dels Retailer IDs to delete.
	 */
	private static function send_batch( $ids, $dels ) {
		$requests = array();
		$map      = array();
		$sent     = array();
		foreach ( $ids as $id ) {
			$p = Nexora_Store_Product::get( $id );
			if ( ! $p || 'product' !== $p->post->post_type ) {
				continue;
			}
			$p->post = get_post( $id ); // The product cache may hold an older status from this request.
			$before = array_filter( array_map( 'strval', (array) get_post_meta( $id, '_nx_meta_ids', true ) ) );
			$reason = self::skip_reason( $p );
			$items  = '' === $reason ? self::items( $p ) : array();
			if ( '' === $reason && ! $items ) {
				$reason = __( 'No price.', 'nexora-commerce-toolkit' );
			}
			$now = array();
			foreach ( $items as $item ) {
				$requests[]         = array( 'method' => 'UPDATE', 'data' => $item );
				$map[ $item['id'] ] = $id;
				$now[]              = (string) $item['id'];
			}
			foreach ( array_diff( $before, $now ) as $old ) {
				$dels[] = $old;
			}
			$sent[ $id ] = array( $now, $reason );
		}
		foreach ( array_unique( $dels ) as $rid ) {
			if ( ! isset( $map[ $rid ] ) ) {
				$requests[] = array( 'method' => 'DELETE', 'data' => array( 'id' => $rid ) );
			}
		}
		if ( ! $requests ) {
			foreach ( $sent as $id => $row ) {
				self::set_status( $id, 'skipped', $row[1] );
			}
			return;
		}

		$res = self::api(
			'POST',
			self::get( 'catalog_id' ) . '/items_batch',
			array(
				'item_type'    => 'PRODUCT_ITEM',
				'allow_upsert' => 'true',
				'requests'     => wp_json_encode( $requests ),
			)
		);
		if ( is_wp_error( $res ) ) {
			self::log( 'error', sprintf( /* translators: %s: error */ __( 'Catalog update failed: %s', 'nexora-commerce-toolkit' ), $res->get_error_message() ) );
			foreach ( $sent as $id => $row ) {
				self::set_status( $id, 'error', $res->get_error_message() );
			}
			$q = self::queue();
			foreach ( array_keys( $sent ) as $id ) {
				$q['sync'][ $id ] = 1; // Try again later.
			}
			update_option( self::QUEUE, $q, false );
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::CRON );
			return;
		}

		$errors = array();
		foreach ( isset( $res['validation_status'] ) ? (array) $res['validation_status'] : array() as $v ) {
			if ( ! empty( $v['errors'] ) && isset( $v['retailer_id'], $map[ $v['retailer_id'] ] ) ) {
				$errors[ $map[ $v['retailer_id'] ] ] = implode( ' ', wp_list_pluck( (array) $v['errors'], 'message' ) );
			}
		}
		foreach ( $sent as $id => $row ) {
			if ( isset( $errors[ $id ] ) ) {
				self::set_status( $id, 'error', $errors[ $id ] );
			} elseif ( '' !== $row[1] ) {
				self::set_status( $id, 'skipped', $row[1] );
			} else {
				self::set_status( $id, 'sent', '' );
			}
			if ( $row[0] ) {
				update_post_meta( $id, '_nx_meta_ids', $row[0] );
			} else {
				delete_post_meta( $id, '_nx_meta_ids' );
			}
		}
		$handle = ! empty( $res['handles'][0] ) ? (string) $res['handles'][0] : '';
		if ( $handle ) {
			set_transient( 'nexora_meta_h_' . md5( $handle ), $map, DAY_IN_SECONDS );
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CHECK, array( $handle, 1 ) );
		}
		$updated = count( $map );
		$removed = count( $requests ) - $updated;
		self::log(
			$errors ? 'error' : 'info',
			sprintf(
				/* translators: 1: number of items sent, 2: number removed, 3: number with errors */
				__( 'Catalog: %1$d items sent, %2$d removed, %3$d with problems.', 'nexora-commerce-toolkit' ),
				$updated,
				$removed,
				count( $errors )
			)
		);
		update_option( 'nexora_meta_last_sync', time(), false );
	}

	/**
	 * Check how Meta processed a batch and note rejected items on their products.
	 *
	 * @param string $handle  Batch handle.
	 * @param int    $attempt Attempt number.
	 */
	public static function check( $handle, $attempt = 1 ) {
		$res = self::api(
			'GET',
			self::get( 'catalog_id' ) . '/check_batch_request_status',
			array(
				'handle'                       => $handle,
				'load_ids_of_invalid_requests' => 'true',
				'fields'                       => 'handle,status,errors,warnings,errors_total_count,ids_of_invalid_requests',
			)
		);
		if ( is_wp_error( $res ) ) {
			return;
		}
		$row = ! empty( $res['data'][0] ) ? (array) $res['data'][0] : array();
		if ( empty( $row['status'] ) || 'finished' !== $row['status'] ) {
			if ( $attempt < 6 ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CHECK, array( $handle, $attempt + 1 ) );
			}
			return;
		}
		$map = (array) get_transient( 'nexora_meta_h_' . md5( $handle ) );
		$bad = 0;
		foreach ( isset( $row['errors'] ) ? (array) $row['errors'] : array() as $e ) {
			$rid = isset( $e['id'] ) ? (string) $e['id'] : '';
			if ( isset( $map[ $rid ] ) ) {
				self::set_status( $map[ $rid ], 'error', isset( $e['message'] ) ? (string) $e['message'] : __( 'Rejected by Meta.', 'nexora-commerce-toolkit' ) );
				++$bad;
			}
		}
		delete_transient( 'nexora_meta_h_' . md5( $handle ) );
		if ( $bad || ! empty( $row['errors_total_count'] ) ) {
			/* translators: %d: number of items */
			self::log( 'error', sprintf( __( 'Meta rejected %d items. Open the product to see why.', 'nexora-commerce-toolkit' ), max( $bad, (int) $row['errors_total_count'] ) ) );
		}
	}

	/**
	 * Catalog status of a product.
	 *
	 * @param int    $id    Product.
	 * @param string $state sent|error|skipped.
	 * @param string $msg   Message.
	 */
	private static function set_status( $id, $state, $msg ) {
		update_post_meta( $id, '_nx_meta_status', array( 'state' => $state, 'msg' => self::plain( $msg, 500 ), 'time' => time() ) );
	}

	/* --------------------------------------------------------------- sharing */

	/**
	 * Post text for a product.
	 *
	 * @param Nexora_Store_Product $p Product.
	 * @return string
	 */
	public static function message( $p ) {
		$template = (string) self::get( 'template' );
		$template = '' !== trim( $template ) ? $template : self::default_template();
		if ( $p->is_type( 'variable' ) ) {
			$min   = $p->get_variation_price( 'min' );
			$max   = $p->get_variation_price( 'max' );
			/* translators: %s: lowest price */
			$price = $max > $min ? sprintf( __( 'from %s', 'nexora-commerce-toolkit' ), self::money( $min ) ) : self::money( $min );
		} else {
			$price = '' !== $p->get_price() ? self::money( (float) $p->get_price() ) : '';
			if ( $p->is_on_sale() ) {
				/* translators: 1: sale price, 2: regular price */
				$price = sprintf( __( '%1$s (was %2$s)', 'nexora-commerce-toolkit' ), $price, self::money( (float) $p->get_regular_price() ) );
			}
		}
		$unit = (string) $p->get_meta( '_nexora_unit' );
		if ( '' !== $price && $unit && class_exists( 'Nexora_Toolkit_Market' ) ) {
			$price .= ' / ' . Nexora_Toolkit_Market::unit_label( $unit );
		}
		$terms = get_the_terms( $p->get_id(), 'product_cat' );
		$vars  = array(
			'{title}'             => $p->get_name(),
			'{price}'             => $price,
			'{short_description}' => self::plain( '' !== trim( $p->get_short_description() ) ? $p->get_short_description() : $p->get_description(), 600 ),
			'{link}'              => $p->get_permalink(),
			'{category}'          => $terms && ! is_wp_error( $terms ) ? $terms[0]->name : '',
			'{site_name}'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		if ( '' === $price ) {
			$template = preg_replace( '/^.*\{price\}.*(\R|$)/m', '', $template );
		}
		$text = preg_replace( "/\n{3,}/", "\n\n", strtr( $template, $vars ) );
		return (string) apply_filters( 'nexora_meta_post_message', trim( $text ), $p );
	}

	/**
	 * Post a product to the Facebook Page and/or Instagram (each channel once).
	 *
	 * @param int $id Product.
	 */
	public static function share( $id ) {
		$p = Nexora_Store_Product::get( $id );
		if ( ! $p || 'publish' !== $p->post->post_status || 'yes' !== $p->get_meta( '_nx_meta_share' ) ) {
			return;
		}
		$s      = self::settings();
		$posted = get_post_meta( $id, '_nx_meta_posted', true );
		$posted = is_array( $posted ) ? $posted : array();
		$text   = self::message( $p );
		$image  = $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'full' ) : '';

		if ( $s['post_fb'] && '' !== $s['page_id'] && empty( $posted['fb']['id'] ) ) {
			$posted['fb'] = self::post_facebook( $s['page_id'], $text, $image, $p->get_permalink() );
			self::log_share( 'Facebook', $p, $posted['fb'] );
		}
		if ( $s['post_ig'] && '' !== $s['ig_id'] && empty( $posted['ig']['id'] ) ) {
			$posted['ig'] = '' !== $image ? self::post_instagram( $s['ig_id'], $text, $image ) : array( 'error' => __( 'Instagram needs a product image.', 'nexora-commerce-toolkit' ), 'time' => time() );
			self::log_share( 'Instagram', $p, $posted['ig'] );
		}
		update_post_meta( $id, '_nx_meta_posted', $posted );
		// Each channel is tried once; "Share again" in the product box starts a new attempt.
		update_post_meta( $id, '_nx_meta_share', 'done' );
	}

	/**
	 * Facebook Page post: a photo with the text, or a link post without an image.
	 *
	 * @param string $page  Page ID.
	 * @param string $text  Text.
	 * @param string $image Image URL.
	 * @param string $link  Product URL.
	 * @return array{id?:string,url?:string,error?:string,time:int}
	 */
	private static function post_facebook( $page, $text, $image, $link ) {
		$token = self::page_token();
		if ( is_wp_error( $token ) ) {
			return array( 'error' => $token->get_error_message(), 'time' => time() );
		}
		$res = '' !== $image
			? self::api( 'POST', $page . '/photos', array( 'url' => $image, 'caption' => $text, 'published' => 'true' ), $token )
			: self::api( 'POST', $page . '/feed', array( 'message' => $text, 'link' => $link ), $token );
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message(), 'time' => time() );
		}
		$post_id = ! empty( $res['post_id'] ) ? (string) $res['post_id'] : ( isset( $res['id'] ) ? (string) $res['id'] : '' );
		return array( 'id' => $post_id, 'url' => 'https://www.facebook.com/' . rawurlencode( $post_id ), 'time' => time() );
	}

	/**
	 * Instagram post: create the media, wait until it is ready, publish it.
	 *
	 * @param string $ig    Instagram account ID.
	 * @param string $text  Caption.
	 * @param string $image Image URL (JPEG).
	 * @return array{id?:string,url?:string,error?:string,time:int}
	 */
	private static function post_instagram( $ig, $text, $image ) {
		$media = self::api( 'POST', $ig . '/media', array( 'image_url' => $image, 'caption' => mb_substr( $text, 0, 2200 ) ) );
		if ( is_wp_error( $media ) || empty( $media['id'] ) ) {
			return array( 'error' => is_wp_error( $media ) ? $media->get_error_message() : __( 'Instagram did not accept the image.', 'nexora-commerce-toolkit' ), 'time' => time() );
		}
		for ( $i = 0; $i < 6; $i++ ) {
			$state = self::api( 'GET', $media['id'], array( 'fields' => 'status_code' ) );
			$code  = ! is_wp_error( $state ) && isset( $state['status_code'] ) ? $state['status_code'] : '';
			if ( 'FINISHED' === $code || '' === $code ) {
				break;
			}
			if ( 'ERROR' === $code || 'EXPIRED' === $code ) {
				return array( 'error' => __( 'Instagram could not process the image. Use a JPEG image.', 'nexora-commerce-toolkit' ), 'time' => time() );
			}
			sleep( 3 );
		}
		$done = self::api( 'POST', $ig . '/media_publish', array( 'creation_id' => $media['id'] ) );
		if ( is_wp_error( $done ) || empty( $done['id'] ) ) {
			return array( 'error' => is_wp_error( $done ) ? $done->get_error_message() : __( 'Instagram did not publish the post.', 'nexora-commerce-toolkit' ), 'time' => time() );
		}
		$link = self::api( 'GET', $done['id'], array( 'fields' => 'permalink' ) );
		return array(
			'id'   => (string) $done['id'],
			'url'  => ! is_wp_error( $link ) && ! empty( $link['permalink'] ) ? esc_url_raw( $link['permalink'] ) : '',
			'time' => time(),
		);
	}

	/**
	 * Log a share result.
	 *
	 * @param string               $where  Facebook|Instagram.
	 * @param Nexora_Store_Product $p      Product.
	 * @param array                $result Result.
	 */
	private static function log_share( $where, $p, $result ) {
		if ( ! empty( $result['error'] ) ) {
			/* translators: 1: Facebook or Instagram, 2: product, 3: error */
			self::log( 'error', sprintf( __( '%1$s post for "%2$s" failed: %3$s', 'nexora-commerce-toolkit' ), $where, $p->get_name(), $result['error'] ) );
		} else {
			/* translators: 1: Facebook or Instagram, 2: product */
			self::log( 'info', sprintf( __( 'Posted "%2$s" on %1$s.', 'nexora-commerce-toolkit' ), $where, $p->get_name() ) );
		}
	}

	/* -------------------------------------------------------------- feed */

	/**
	 * Feed address.
	 *
	 * @return string
	 */
	public static function feed_url() {
		return add_query_arg( 'nexora_meta_feed', self::get( 'feed_key' ), home_url( '/' ) );
	}

	/**
	 * Serve the CSV product feed for Commerce Manager's scheduled data feed.
	 */
	public static function maybe_feed() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public feed, protected by its key.
		if ( ! isset( $_GET['nexora_meta_feed'] ) ) {
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_GET['nexora_meta_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s   = self::settings();
		if ( ! $s['feed'] || ! hash_equals( (string) $s['feed_key'], $key ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="meta-catalog.csv"' );
		header( 'X-Robots-Tag: noindex' );
		$cols = array( 'id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'sale_price_effective_date', 'link', 'image_link', 'additional_image_link', 'brand', 'item_group_id', 'product_type' );
		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, $cols );
		$page = 1;
		do {
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 200, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) {
				$p = Nexora_Store_Product::get( $id );
				if ( ! $p || '' !== self::skip_reason( $p ) ) {
					continue;
				}
				foreach ( self::items( $p ) as $item ) {
					$line = array();
					foreach ( $cols as $c ) {
						$v      = isset( $item[ $c ] ) ? $item[ $c ] : '';
						$v      = is_array( $v ) ? implode( ',', $v ) : (string) $v;
						$line[] = preg_match( '/^[=+@]/', $v ) ? "'" . $v : $v; // No spreadsheet formulas.
					}
					fputcsv( $out, $line );
				}
			}
			++$page;
		} while ( count( $ids ) === 200 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------------- log */

	/**
	 * Add to the activity log (last 30 entries).
	 *
	 * @param string $type info|error.
	 * @param string $msg  Message.
	 */
	private static function log( $type, $msg ) {
		$log = (array) get_option( self::LOG, array() );
		array_unshift( $log, array( 'time' => time(), 'type' => $type, 'msg' => self::plain( $msg, 600 ) ) );
		update_option( self::LOG, array_slice( $log, 0, 30 ), false );
	}

	/* ---------------------------------------------------------- product box */

	/**
	 * Product box.
	 */
	public static function add_box() {
		add_meta_box( 'nx-meta', __( 'Facebook & Instagram', 'nexora-commerce-toolkit' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	/**
	 * Render the product box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$s      = self::settings();
		$status = (array) get_post_meta( $post->ID, '_nx_meta_status', true );
		$posted = get_post_meta( $post->ID, '_nx_meta_posted', true );
		$posted = is_array( $posted ) ? $posted : array();
		$share  = (string) get_post_meta( $post->ID, '_nx_meta_share', true );
		$fresh  = 'auto-draft' === $post->post_status || ( '' === $share && 'publish' !== $post->post_status );
		wp_nonce_field( 'nexora_meta_box', 'nexora_meta_box' );

		if ( ! self::catalog_ready() && ! self::posting_ready() ) {
			echo '<p>' . esc_html__( 'Not connected yet.', 'nexora-commerce-toolkit' ) . ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Connect Meta', 'nexora-commerce-toolkit' ) . '</a></p>';
			return;
		}
		if ( self::catalog_ready() ) {
			printf(
				'<p><label><input type="checkbox" name="nx_meta_show" value="1" %s> %s</label></p>',
				checked( 'yes' !== get_post_meta( $post->ID, '_nx_meta_exclude', true ), true, false ),
				esc_html__( 'Show in the Facebook & Instagram catalog', 'nexora-commerce-toolkit' )
			);
			if ( ! empty( $status['state'] ) ) {
				$labels = array(
					'sent'    => __( 'Sent to the catalog', 'nexora-commerce-toolkit' ),
					'error'   => __( 'Problem', 'nexora-commerce-toolkit' ),
					'skipped' => __( 'Not in the catalog', 'nexora-commerce-toolkit' ),
				);
				$color  = 'sent' === $status['state'] ? '#008a20' : ( 'error' === $status['state'] ? '#d63638' : '#646970' );
				printf(
					'<p style="margin:0 0 10px"><strong style="color:%1$s">%2$s</strong> · %3$s%4$s</p>',
					esc_attr( $color ),
					esc_html( isset( $labels[ $status['state'] ] ) ? $labels[ $status['state'] ] : $status['state'] ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['time'] ) ),
					'' !== (string) $status['msg'] ? '<br>' . esc_html( $status['msg'] ) : ''
				);
			}
		}
		if ( self::posting_ready() ) {
			$channels = array();
			if ( $s['post_fb'] && '' !== $s['page_id'] ) {
				$channels['fb'] = __( 'Facebook Page', 'nexora-commerce-toolkit' );
			}
			if ( $s['post_ig'] && '' !== $s['ig_id'] ) {
				$channels['ig'] = __( 'Instagram', 'nexora-commerce-toolkit' );
			}
			$open = false;
			foreach ( $channels as $key => $label ) {
				if ( ! empty( $posted[ $key ]['id'] ) ) {
					printf(
						'<p style="margin:0 0 6px">%1$s %2$s%3$s</p>',
						/* translators: %s: Facebook Page or Instagram */
						esc_html( sprintf( __( 'Posted on %s', 'nexora-commerce-toolkit' ), $label ) ),
						esc_html( wp_date( get_option( 'date_format' ), (int) $posted[ $key ]['time'] ) ),
						! empty( $posted[ $key ]['url'] ) ? ' · <a href="' . esc_url( $posted[ $key ]['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'nexora-commerce-toolkit' ) . '</a>' : ''
					);
				} else {
					$open = true;
					if ( ! empty( $posted[ $key ]['error'] ) ) {
						/* translators: 1: Facebook Page or Instagram, 2: error */
						echo '<p style="margin:0 0 6px;color:#d63638">' . esc_html( sprintf( __( '%1$s post failed: %2$s', 'nexora-commerce-toolkit' ), $label, $posted[ $key ]['error'] ) ) . '</p>';
					}
				}
			}
			if ( $open ) {
				$checked = 'yes' === $share || ( $fresh && $s['share_new'] );
				$text    = 'publish' === $post->post_status
					/* translators: %s: Facebook Page and/or Instagram */
					? sprintf( __( 'Post on %s when I click Update', 'nexora-commerce-toolkit' ), implode( ' + ', $channels ) )
					/* translators: %s: Facebook Page and/or Instagram */
					: sprintf( __( 'Post on %s when published', 'nexora-commerce-toolkit' ), implode( ' + ', $channels ) );
				if ( 'publish' === $post->post_status && 'yes' === $share ) {
					echo '<p style="margin:0 0 6px;color:#646970">' . esc_html__( 'Posting in the background, usually within a minute. Reload to see the link.', 'nexora-commerce-toolkit' ) . '</p>';
				}
				printf( '<p><label><input type="checkbox" name="nx_meta_share" value="1" %s> %s</label></p>', checked( $checked, true, false ), esc_html( $text ) );
			}
			if ( ! empty( $posted['fb']['id'] ) || ! empty( $posted['ig']['id'] ) ) {
				printf( '<p><label><input type="checkbox" name="nx_meta_reshare" value="1"> %s</label></p>', esc_html__( 'Post again when I click Update', 'nexora-commerce-toolkit' ) );
			}
		}
	}

	/**
	 * Save the product box.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_box( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_meta_box'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_meta_box'] ) ), 'nexora_meta_box' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		unset( $post );
		if ( self::catalog_ready() ) {
			if ( empty( $_POST['nx_meta_show'] ) ) {
				update_post_meta( $post_id, '_nx_meta_exclude', 'yes' );
			} else {
				delete_post_meta( $post_id, '_nx_meta_exclude' );
			}
		}
		if ( self::posting_ready() ) {
			if ( ! empty( $_POST['nx_meta_reshare'] ) ) {
				delete_post_meta( $post_id, '_nx_meta_posted' );
				update_post_meta( $post_id, '_nx_meta_share', 'yes' );
			} elseif ( isset( $_POST['nx_meta_share'] ) ) {
				update_post_meta( $post_id, '_nx_meta_share', 'yes' );
			} elseif ( 'done' !== get_post_meta( $post_id, '_nx_meta_share', true ) ) {
				update_post_meta( $post_id, '_nx_meta_share', 'no' );
			}
		}
	}

	/* --------------------------------------------------------------- admin */

	/**
	 * Menu.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Meta', 'nexora-commerce-toolkit' ), __( 'Freshtastic Meta', 'nexora-commerce-toolkit' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Plugin list link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Facebook & Instagram', 'nexora-commerce-toolkit' ) . '</a>';
		return $links;
	}

	/**
	 * Test the connection and report names (never tokens).
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_meta_test' );
		$s    = self::settings();
		$rows = array();
		$me   = self::api( 'GET', 'me', array( 'fields' => 'id,name' ) );
		$rows[] = array( __( 'Access token', 'nexora-commerce-toolkit' ), is_wp_error( $me ) ? $me->get_error_message() : $me['name'], ! is_wp_error( $me ) );
		if ( ! is_wp_error( $me ) ) {
			if ( '' !== $s['catalog_id'] ) {
				$c      = self::api( 'GET', $s['catalog_id'], array( 'fields' => 'name,product_count' ) );
				/* translators: 1: catalog name, 2: number of items */
				$rows[] = array( __( 'Catalog', 'nexora-commerce-toolkit' ), is_wp_error( $c ) ? $c->get_error_message() : sprintf( __( '%1$s (%2$d items)', 'nexora-commerce-toolkit' ), $c['name'], isset( $c['product_count'] ) ? (int) $c['product_count'] : 0 ), ! is_wp_error( $c ) );
			}
			if ( '' !== $s['page_id'] ) {
				$pg     = self::api( 'GET', $s['page_id'], array( 'fields' => 'name,access_token,instagram_business_account{id,username}' ) );
				$ok     = ! is_wp_error( $pg ) && ( ! empty( $pg['access_token'] ) || $s['page_id'] === (string) $me['id'] );
				$rows[] = array(
					__( 'Facebook Page', 'nexora-commerce-toolkit' ),
					is_wp_error( $pg ) ? $pg->get_error_message() : $pg['name'] . ( $ok ? '' : ' — ' . __( 'the token cannot post to this Page. Give it the pages_manage_posts permission and access to the Page.', 'nexora-commerce-toolkit' ) ),
					$ok,
				);
				// No Instagram ID yet: take the one linked to the Page.
				if ( '' === $s['ig_id'] && ! is_wp_error( $pg ) && ! empty( $pg['instagram_business_account']['id'] ) ) {
					$found         = preg_replace( '/\D/', '', (string) $pg['instagram_business_account']['id'] );
					$raw           = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
					$raw['ig_id']  = $found;
					update_option( self::OPTION, $raw, false );
					$rows[] = array(
						__( 'Instagram', 'nexora-commerce-toolkit' ),
						/* translators: 1: Instagram username, 2: account ID */
						sprintf( __( '@%1$s found through your Page. Its ID (%2$s) is now saved. Tick "Post products on Instagram" to use it.', 'nexora-commerce-toolkit' ), isset( $pg['instagram_business_account']['username'] ) ? $pg['instagram_business_account']['username'] : '', $found ),
						true,
					);
				}
			}
			if ( '' !== $s['ig_id'] ) {
				$ig     = self::api( 'GET', $s['ig_id'], array( 'fields' => 'username' ) );
				$rows[] = array( __( 'Instagram', 'nexora-commerce-toolkit' ), is_wp_error( $ig ) ? $ig->get_error_message() : '@' . $ig['username'], ! is_wp_error( $ig ) );
			}
		}
		set_transient( 'nexora_meta_test_' . get_current_user_id(), $rows, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tested=1' ) );
		exit;
	}

	/**
	 * Send every product now.
	 */
	public static function handle_sync_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_meta_sync_all' );
		$count = 0;
		if ( self::catalog_ready() ) {
			$count = self::queue_all();
			wp_clear_scheduled_hook( self::CRON );
			self::run();
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&synced=' . (int) $count ) );
		exit;
	}

	/**
	 * Secret field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help.
	 */
	private static function secret_field( $key, $label, $help ) {
		$constant = self::SECRETS[ $key ];
		$saved    = '' !== (string) self::get( $key );
		echo '<tr><th scope="row"><label for="nx-meta-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( defined( $constant ) ) {
			/* translators: %s: constant */
			echo '<p><span class="dashicons dashicons-lock"></span> ' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'nexora-commerce-toolkit' ), $constant ) ) . '</p></td></tr>';
			return;
		}
		printf(
			'<input type="password" class="regular-text" id="nx-meta-%1$s" name="%2$s[%1$s]" value="" autocomplete="new-password" placeholder="%3$s">',
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			esc_attr( $saved ? __( '•••••••• saved — leave blank to keep', 'nexora-commerce-toolkit' ) : '' )
		);
		if ( $saved ) {
			printf( ' <label><input type="checkbox" name="%1$s[%2$s_clear]" value="1"> %3$s</label>', esc_attr( self::OPTION ), esc_attr( $key ), esc_html__( 'Remove', 'nexora-commerce-toolkit' ) );
		}
		/* translators: 1: help text, 2: constant */
		echo '<p class="description">' . wp_kses( sprintf( __( '%1$s Safer: define %2$s in wp-config.php instead.', 'nexora-commerce-toolkit' ), $help, '<code>' . $constant . '</code>' ), array( 'code' => array(), 'strong' => array() ) ) . '</p></td></tr>';
	}

	/**
	 * Text field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $help  Help.
	 * @param string $class CSS class.
	 */
	private static function text_field( $key, $label, $help = '', $class = 'regular-text' ) {
		printf(
			'<tr><th scope="row"><label for="nx-meta-%1$s">%2$s</label></th><td><input type="text" class="%5$s" id="nx-meta-%1$s" name="%3$s[%1$s]" value="%4$s">',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( (string) self::get( $key ) ),
			esc_attr( $class )
		);
		if ( '' !== $help ) {
			echo '<p class="description">' . wp_kses( $help, array( 'code' => array(), 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkbox field.
	 *
	 * @param string $key   Key.
	 * @param string $label Row label.
	 * @param string $text  Checkbox text.
	 * @param string $help  Help.
	 */
	private static function check_field( $key, $label, $text, $help = '' ) {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> %5$s</label>',
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( (bool) self::get( $key ), true, false ),
			esc_html( $text )
		);
		if ( '' !== $help ) {
			echo '<p class="description">' . wp_kses( $help, array( 'code' => array(), 'strong' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Step-by-step connection guide with links to Meta.
	 */
	private static function steps() {
		$link  = static function ( $url, $text ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . ' <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'nexora-commerce-toolkit' ) . '</span></a>';
		};
		$allow = array(
			'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
			'span'   => array( 'class' => array(), 'style' => array(), 'aria-hidden' => array() ),
			'strong' => array(),
			'code'   => array(),
			'em'     => array(),
		);
		$steps = array(
			array(
				__( 'Get ready', 'nexora-commerce-toolkit' ),
				/* translators: %s: link */
				sprintf( __( 'You need a Facebook Page and a Meta Business portfolio that owns it. For Instagram, switch your Instagram to a professional (business) account and link it to the Page. No portfolio yet? Create one at %s.', 'nexora-commerce-toolkit' ), $link( 'https://business.facebook.com/overview', 'business.facebook.com' ) ),
			),
			array(
				__( 'Create a catalog', 'nexora-commerce-toolkit' ),
				/* translators: %s: link */
				sprintf( __( 'Open %s → <strong>Add catalog</strong> → type <strong>E-commerce</strong> → <strong>Upload product info</strong> → choose your business. Then open the catalog → <strong>Settings</strong> and copy the <strong>Catalog ID</strong>.', 'nexora-commerce-toolkit' ), $link( 'https://business.facebook.com/commerce', __( 'Commerce Manager', 'nexora-commerce-toolkit' ) ) ),
			),
			array(
				__( 'Create a Meta app', 'nexora-commerce-toolkit' ),
				/* translators: %s: link */
				sprintf( __( 'Open %s → <strong>Create app</strong>. Choose <strong>Other</strong>, then the type <strong>Business</strong>, give it a name (for example "Freshtastic Shop") and connect it to your business portfolio. You do not need to publish the app or send it for review.', 'nexora-commerce-toolkit' ), $link( 'https://developers.facebook.com/apps/', __( 'Meta for Developers → My Apps', 'nexora-commerce-toolkit' ) ) ),
			),
			array(
				__( 'Add a system user', 'nexora-commerce-toolkit' ),
				/* translators: %s: link */
				sprintf( __( 'Open %s → <strong>Add</strong>. Name it (for example "Website"), role <strong>Admin</strong>. Then <strong>Assign assets</strong> and give it <strong>full control</strong> of: your app, your catalog, your Facebook Page and your Instagram account.', 'nexora-commerce-toolkit' ), $link( 'https://business.facebook.com/settings/system-users', __( 'Business Settings → Users → System users', 'nexora-commerce-toolkit' ) ) ),
			),
			array(
				__( 'Generate the token', 'nexora-commerce-toolkit' ),
				__( 'On the same system user click <strong>Generate token</strong>, choose your app, set <strong>Token expiration: Never</strong> and tick: <code>catalog_management</code> <code>business_management</code> <code>pages_show_list</code> <code>pages_read_engagement</code> <code>pages_manage_posts</code> <code>instagram_basic</code> <code>instagram_content_publish</code>. Copy the token right away (Meta shows it once) and paste it into <strong>Access token</strong> below.', 'nexora-commerce-toolkit' ),
			),
			array(
				__( 'Find your Page ID', 'nexora-commerce-toolkit' ),
				/* translators: %s: link */
				sprintf( __( 'Open %s, select your Page and copy its <strong>ID</strong>. (Or on Facebook: your Page → <strong>About</strong> → <strong>Page transparency</strong>.) Leave the Instagram ID empty: <strong>Test connection</strong> finds it through your Page.', 'nexora-commerce-toolkit' ), $link( 'https://business.facebook.com/settings/pages', __( 'Business Settings → Accounts → Pages', 'nexora-commerce-toolkit' ) ) ),
			),
			array(
				__( 'Save and test', 'nexora-commerce-toolkit' ),
				__( 'Paste the token, Catalog ID and Page ID below, tick what you want under <em>Post new products automatically</em>, click <strong>Save Changes</strong>, then <strong>Test connection</strong> at the bottom. Every line should get a green tick. Finally click <strong>Send all products now</strong>.', 'nexora-commerce-toolkit' ),
			),
		);
		$open = '' === (string) self::get( 'token' ) ? ' open' : '';
		echo '<details class="nx-meta-steps"' . esc_attr( $open ) . ' style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin:12px 0 4px;max-width:900px">';
		echo '<summary style="cursor:pointer;font-weight:600;font-size:14px">' . esc_html__( 'How to connect: step by step (about 15 minutes)', 'nexora-commerce-toolkit' ) . '</summary>';
		echo '<ol style="margin:12px 0 4px 20px">';
		foreach ( $steps as $step ) {
			echo '<li style="margin-bottom:10px"><strong>' . esc_html( $step[0] ) . '.</strong> ' . wp_kses( $step[1], $allow ) . '</li>';
		}
		echo '</ol>';
		/* translators: 1: link, 2: link */
		echo '<p class="description" style="margin:0">' . wp_kses( sprintf( __( 'Stuck? Check what a token can do in the %1$s, and read Meta\'s guide to %2$s. Meta sometimes renames menus; the links still lead to the right place after you sign in.', 'nexora-commerce-toolkit' ), $link( 'https://developers.facebook.com/tools/debug/accesstoken/', __( 'Access Token Debugger', 'nexora-commerce-toolkit' ) ), $link( 'https://developers.facebook.com/docs/marketing-api/system-users/', __( 'system users', 'nexora-commerce-toolkit' ) ) ), $allow ) . '</p>';
		echo '</details>';
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$q      = self::queue();
		$log    = (array) get_option( self::LOG, array() );
		$last   = (int) get_option( 'nexora_meta_last_sync', 0 );
		$tested = get_transient( 'nexora_meta_test_' . get_current_user_id() );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Facebook & Instagram (Meta)', 'nexora-commerce-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Send your products to a Meta catalog for Facebook and Instagram shops and ads, and post new products to your Facebook Page and Instagram automatically. Works with the built-in store and with WooCommerce.', 'nexora-commerce-toolkit' ); ?></p>
			<?php
			settings_errors();
			if ( class_exists( 'WC_Facebookcommerce' ) || defined( 'WC_FACEBOOKCOMMERCE_VERSION' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Meta for WooCommerce is also active. Use one of the two for the catalog, or products may be listed twice.', 'nexora-commerce-toolkit' ) . '</p></div>';
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
			if ( isset( $_GET['synced'] ) ) {
				/* translators: %d: number of products */
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d product queued for the catalog. The first batch was sent; the rest follow in the background.', '%d products queued for the catalog. The first batch was sent; the rest follow in the background.', absint( $_GET['synced'] ), 'nexora-commerce-toolkit' ), absint( $_GET['synced'] ) ) ) . '</p></div>';
			}
			if ( isset( $_GET['tested'] ) && is_array( $tested ) ) {
				delete_transient( 'nexora_meta_test_' . get_current_user_id() );
				echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Connection test', 'nexora-commerce-toolkit' ) . '</strong></p><ul>';
				foreach ( $tested as $row ) {
					printf( '<li><span class="dashicons %1$s" style="color:%2$s"></span> <strong>%3$s:</strong> %4$s</li>', esc_attr( $row[2] ? 'dashicons-yes-alt' : 'dashicons-warning' ), esc_attr( $row[2] ? '#008a20' : '#d63638' ), esc_html( $row[0] ), esc_html( $row[1] ) );
				}
				echo '</ul></div>';
			}
			// phpcs:enable
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_meta' ); ?>
				<h2><?php esc_html_e( 'Connection', 'nexora-commerce-toolkit' ); ?></h2>
				<?php self::steps(); ?>
				<table class="form-table" role="presentation">
					<?php
					self::secret_field( 'token', __( 'Access token', 'nexora-commerce-toolkit' ), __( 'System user token from Meta Business Settings.', 'nexora-commerce-toolkit' ) );
					self::text_field( 'catalog_id', __( 'Catalog ID', 'nexora-commerce-toolkit' ), __( 'Commerce Manager → your catalog → Settings. Numbers only.', 'nexora-commerce-toolkit' ) );
					self::text_field( 'page_id', __( 'Facebook Page ID', 'nexora-commerce-toolkit' ), __( 'Your Page → About → Page transparency, or Business Settings → Pages.', 'nexora-commerce-toolkit' ) );
					self::text_field( 'ig_id', __( 'Instagram account ID', 'nexora-commerce-toolkit' ), __( 'Leave empty and click Test connection: it is found through your Page. It must be a professional (business or creator) account linked to the Page.', 'nexora-commerce-toolkit' ) );
					self::secret_field( 'app_secret', __( 'App secret (optional)', 'nexora-commerce-toolkit' ), __( 'Only needed if your Meta app has "Require app secret" switched on.', 'nexora-commerce-toolkit' ) );
					self::text_field( 'api_version', __( 'Graph API version', 'nexora-commerce-toolkit' ), __( 'Leave as it is unless Meta asks you to move to a newer version.', 'nexora-commerce-toolkit' ), 'code' );
					?>
				</table>

				<h2><?php esc_html_e( 'Catalog sync', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::check_field( 'catalog_sync', __( 'Sync', 'nexora-commerce-toolkit' ), __( 'Send products to the catalog when they are saved, sold out, back in stock or removed', 'nexora-commerce-toolkit' ), __( 'Each product with options is sent as one item per option. Products without an image, hidden products and drafts are left out.', 'nexora-commerce-toolkit' ) );
					self::text_field( 'brand', __( 'Brand', 'nexora-commerce-toolkit' ), __( 'Meta requires a brand. Empty uses your site title.', 'nexora-commerce-toolkit' ) );
					?>
					<tr><th scope="row"><label for="nx-meta-condition"><?php esc_html_e( 'Condition', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<select id="nx-meta-condition" name="<?php echo esc_attr( self::OPTION ); ?>[condition]">
							<?php foreach ( array( 'new' => __( 'New', 'nexora-commerce-toolkit' ), 'refurbished' => __( 'Refurbished', 'nexora-commerce-toolkit' ), 'used' => __( 'Used', 'nexora-commerce-toolkit' ) ) as $k => $v ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['condition'], $k ); ?>><?php echo esc_html( $v ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<?php self::check_field( 'skip_demo', __( 'Demo products', 'nexora-commerce-toolkit' ), __( 'Leave imported demo products out', 'nexora-commerce-toolkit' ) ); ?>
				</table>

				<h2><?php esc_html_e( 'Data feed (works without a token)', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::check_field( 'feed', __( 'Feed', 'nexora-commerce-toolkit' ), __( 'Publish a private product feed', 'nexora-commerce-toolkit' ), sprintf( /* translators: %s: Commerce Manager link */ __( 'In %s open your catalog → <strong>Data sources</strong> → <strong>Add items</strong> → <strong>Data feed</strong> → <strong>Scheduled feed</strong>, paste this address and pick a daily or hourly schedule.', 'nexora-commerce-toolkit' ), '<a href="https://business.facebook.com/commerce" target="_blank" rel="noopener noreferrer">Commerce Manager</a>' ) ); ?>
					<tr><th scope="row"><?php esc_html_e( 'Feed address', 'nexora-commerce-toolkit' ); ?></th><td>
						<input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::feed_url() ); ?>" onclick="this.select()">
						<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[feed_new]" value="1"> <?php esc_html_e( 'Make a new address (the old one stops working)', 'nexora-commerce-toolkit' ); ?></label></p>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Post new products automatically', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::check_field( 'post_fb', __( 'Facebook Page', 'nexora-commerce-toolkit' ), __( 'Post products on the Facebook Page', 'nexora-commerce-toolkit' ) );
					self::check_field( 'post_ig', __( 'Instagram', 'nexora-commerce-toolkit' ), __( 'Post products on Instagram', 'nexora-commerce-toolkit' ), __( 'Instagram needs a JPEG product image, and links in captions are not clickable there.', 'nexora-commerce-toolkit' ) );
					self::check_field( 'share_new', __( 'New products', 'nexora-commerce-toolkit' ), __( 'Tick "Post when published" on new products by default', 'nexora-commerce-toolkit' ), __( 'Each product is posted once, when it is published with the box ticked. Imported products are never posted.', 'nexora-commerce-toolkit' ) );
					?>
					<tr><th scope="row"><label for="nx-meta-template"><?php esc_html_e( 'Post text', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<textarea id="nx-meta-template" class="large-text" rows="7" name="<?php echo esc_attr( self::OPTION ); ?>[template]" placeholder="<?php echo esc_attr( self::default_template() ); ?>"><?php echo esc_textarea( $s['template'] ); ?></textarea>
						<p class="description"><?php echo wp_kses( __( 'Empty uses the text shown in grey. Placeholders: <code>{title}</code> <code>{price}</code> <code>{short_description}</code> <code>{link}</code> <code>{category}</code> <code>{site_name}</code>', 'nexora-commerce-toolkit' ), array( 'code' => array() ) ); ?></p>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></h2>
			<p>
				<?php
				if ( $last ) {
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Last catalog update: %s.', 'nexora-commerce-toolkit' ), wp_date( $fmt, $last ) ) ) . ' ';
				}
				$waiting = count( $q['sync'] ) + count( $q['delete'] ) + count( $q['post'] );
				/* translators: %d: number of updates */
				echo esc_html( $waiting ? sprintf( _n( '%d update waiting to be sent.', '%d updates waiting to be sent.', $waiting, 'nexora-commerce-toolkit' ), $waiting ) : __( 'Nothing waiting.', 'nexora-commerce-toolkit' ) );
				?>
			</p>
			<p>
				<?php if ( '' !== $s['token'] ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_meta_test' ), 'nexora_meta_test' ) ); ?>"><?php esc_html_e( 'Test connection', 'nexora-commerce-toolkit' ); ?></a>
				<?php endif; ?>
				<?php if ( self::catalog_ready() ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_meta_sync_all' ), 'nexora_meta_sync_all' ) ); ?>"><?php esc_html_e( 'Send all products now', 'nexora-commerce-toolkit' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( $log ) : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th style="width:170px"><?php esc_html_e( 'When', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'What happened', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr><td><?php echo esc_html( wp_date( $fmt, (int) $row['time'] ) ); ?></td><td<?php echo 'error' === $row['type'] ? ' style="color:#d63638"' : ''; ?>><?php echo esc_html( $row['msg'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

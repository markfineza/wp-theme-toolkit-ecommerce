<?php
/**
 * Demo content importer and cleanup (Tools → Freshtastic Demo Content, or `wp nexora demo`).
 *
 * Safety rules:
 *  - Admin-only (manage_options + manage_woocommerce), nonce-protected, explicit confirmation.
 *  - Everything created is tagged with `_nexora_demo` (post/term/comment meta) plus a stable
 *    `_nexora_demo_key`, so re-running skips existing items instead of duplicating them.
 *  - Cleanup removes only tagged items. Orders, customers and users are never touched.
 *  - Changed settings are backed up and restored on cleanup only if they still hold the demo value.
 *  - Products are created through the WooCommerce CRUD API, never by writing to commerce tables.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demo importer.
 */
class Nexora_Toolkit_Demo_Importer {

	const META      = '_nexora_demo';
	const KEY       = '_nexora_demo_key';
	const STATE     = 'nexora_demo_state';
	const BATCH     = 5;

	/**
	 * Cross-sells ("Frequently bought together") by SKU.
	 *
	 * @var array<string,string[]>
	 */
	const CROSS_SELLS = array(
		'NX-RG-001' => array( 'NX-DG-003', 'NX-PE-003', 'NX-DG-002' ),
		'NX-RG-003' => array( 'NX-DG-003', 'NX-DG-001', 'NX-PE-003' ),
		'NX-MT-001' => array( 'NX-DG-005', 'NX-DG-004', 'NX-VG-004' ),
		'NX-MT-002' => array( 'NX-DG-004', 'NX-DG-005', 'NX-VG-005' ),
		'NX-FS-001' => array( 'NX-VG-002', 'NX-FR-003', 'NX-DG-005' ),
		'NX-FS-003' => array( 'NX-DG-005', 'NX-FR-003', 'NX-RG-003' ),
		'NX-VG-001' => array( 'NX-VG-008', 'NX-MT-002' ),
		'NX-VG-004' => array( 'NX-VG-005', 'NX-VG-002' ),
		'NX-PE-001' => array( 'NX-VG-007', 'NX-DG-004' ),
		'NX-FR-002' => array( 'NX-RG-005', 'NX-FR-001' ),
	);

	/**
	 * Hook up admin UI and CLI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_nexora_demo_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'admin_post_nexora_demo_run', array( __CLASS__, 'nojs_run' ) );
	}

	/* ------------------------------------------------------------------
	 * Data
	 * --------------------------------------------------------------- */

	/**
	 * Load a demo data file.
	 *
	 * @param string $name products|content.
	 * @return array
	 */
	private static function data( $name ) {
		static $cache = array();
		if ( ! isset( $cache[ $name ] ) ) {
			$raw            = file_get_contents( NEXORA_TOOLKIT_DIR . 'demo/data/' . $name . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
			$cache[ $name ] = json_decode( (string) $raw, true );
		}
		return $cache[ $name ];
	}

	/**
	 * Persistent importer state.
	 *
	 * @return array
	 */
	private static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE, array() ),
			array(
				'imported_at' => '',
				'options'     => array(),
				'theme_mods'  => array(),
				'attributes'  => array(),
				'zone_id'     => 0,
				'menus'       => array(),
				'locations'   => array(),
			)
		);
	}

	/**
	 * Save state.
	 *
	 * @param array $state State.
	 */
	private static function save_state( $state ) {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Import steps. Each returns ['done' => bool, 'messages' => string[], 'offset' => int].
	 *
	 * @param array $choices Which optional groups to import.
	 * @return array<string,string>
	 */
	public static function steps( $choices ) {
		$steps = array( 'check' => __( 'Checking requirements', 'nexora-commerce-toolkit' ) );
		if ( Nexora_Store::active() ) {
			$steps['catalog'] = __( 'Creating categories, products and images', 'nexora-commerce-toolkit' );
		} else {
			if ( ! empty( $choices['settings'] ) ) {
				$steps['settings'] = __( 'Applying Philippine store settings', 'nexora-commerce-toolkit' );
			}
			$steps['categories'] = __( 'Creating categories and attributes', 'nexora-commerce-toolkit' );
			$steps['products']   = __( 'Creating products and images', 'nexora-commerce-toolkit' );
			$steps['relations']  = __( 'Linking related products', 'nexora-commerce-toolkit' );
		}
		if ( ! empty( $choices['reviews'] ) ) {
			$steps['reviews'] = __( 'Adding labelled sample reviews and testimonials', 'nexora-commerce-toolkit' );
		}
		if ( ! empty( $choices['content'] ) ) {
			$steps['posts'] = __( 'Creating journal posts', 'nexora-commerce-toolkit' );
			$steps['pages'] = __( 'Creating pages', 'nexora-commerce-toolkit' );
			$steps['menus'] = __( 'Creating menus', 'nexora-commerce-toolkit' );
		}
		if ( ! empty( $choices['homepage'] ) ) {
			$steps['homepage'] = __( 'Setting up the homepage', 'nexora-commerce-toolkit' );
		}
		$steps['finish'] = __( 'Finishing up', 'nexora-commerce-toolkit' );
		return $steps;
	}

	/**
	 * Run one step.
	 *
	 * @param string $step   Step.
	 * @param int    $offset Offset for batched steps.
	 * @return array
	 */
	public static function run_step( $step, $offset = 0 ) {
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$method = 'step_' . $step;
		if ( ! method_exists( __CLASS__, $method ) ) {
			return array( 'done' => true, 'messages' => array( 'Unknown step.' ), 'error' => true );
		}
		return wp_parse_args( call_user_func( array( __CLASS__, $method ), (int) $offset ), array( 'done' => true, 'messages' => array(), 'offset' => 0 ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	/**
	 * Find a demo object by key.
	 *
	 * @param string $key       Key.
	 * @param string $post_type Post type.
	 * @return int
	 */
	private static function find_post( $key, $post_type = 'any' ) {
		$q = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	/**
	 * Tag a post as demo content.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Stable key.
	 */
	private static function tag_post( $id, $key ) {
		update_post_meta( $id, self::META, 1 );
		update_post_meta( $id, self::KEY, $key );
	}

	/**
	 * Import a bundled image into the media library once.
	 *
	 * @param string $file   File name in demo/images.
	 * @param string $alt    Alt text.
	 * @param int    $parent Parent post.
	 * @return int Attachment ID or 0.
	 */
	private static function image( $file, $alt, $parent = 0 ) {
		$key = 'image:' . $file;
		$id  = self::find_post( $key, 'attachment' );
		if ( $id ) {
			return $id;
		}
		$path = NEXORA_TOOLKIT_DIR . 'demo/images/' . $file;
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload = wp_upload_bits( 'nexora-demo-' . $file, null, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $alt,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		self::tag_post( $id, $key );
		return (int) $id;
	}

	/**
	 * Get or create a term, tagging it only if the importer created it.
	 *
	 * @param string $name     Name.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Args (slug, description).
	 * @return int
	 */
	private static function term( $name, $taxonomy, $args = array() ) {
		$slug     = isset( $args['slug'] ) ? $args['slug'] : sanitize_title( $name );
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}
		$created = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $created ) ) {
			return isset( $created->error_data['term_exists'] ) ? (int) $created->error_data['term_exists'] : 0;
		}
		update_term_meta( $created['term_id'], self::META, 1 );
		return (int) $created['term_id'];
	}

	/**
	 * Remember an option value before changing it (first change only).
	 *
	 * @param array  $state State (by reference).
	 * @param string $option Option.
	 * @param mixed  $value New value.
	 */
	private static function set_option( &$state, $option, $value ) {
		if ( ! array_key_exists( $option, $state['options'] ) ) {
			$state['options'][ $option ] = array( 'old' => get_option( $option, null ), 'new' => $value );
		} else {
			$state['options'][ $option ]['new'] = $value;
		}
		update_option( $option, $value );
	}

	/* ------------------------------------------------------------------
	 * Steps
	 * --------------------------------------------------------------- */

	/**
	 * Requirements.
	 *
	 * @return array
	 */
	private static function step_check() {
		$messages = array();
		if ( ! class_exists( 'WooCommerce' ) && ! Nexora_Store::active() ) {
			return array( 'done' => true, 'error' => true, 'messages' => array( __( 'WooCommerce must be active before importing demo products.', 'nexora-commerce-toolkit' ) ) );
		}
		if ( ! is_writable( wp_upload_dir()['basedir'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return array( 'done' => true, 'error' => true, 'messages' => array( __( 'The uploads folder is not writable, so demo images cannot be added.', 'nexora-commerce-toolkit' ) ) );
		}
		if ( 'nexora-commerce' !== get_template() ) {
			$messages[] = __( 'Note: the Freshtastic Food Hub theme is not active, so homepage images will not be applied to its Customizer settings.', 'nexora-commerce-toolkit' );
		}
		$state = self::state();
		if ( $state['imported_at'] ) {
			$messages[] = __( 'Demo content was imported before — existing demo items will be skipped, missing ones re-created.', 'nexora-commerce-toolkit' );
		}
		$messages[] = __( 'Requirements met.', 'nexora-commerce-toolkit' );
		return array( 'messages' => $messages );
	}

	/**
	 * Philippine store settings, PHP currency, a demo shipping zone and offline payment methods.
	 *
	 * @return array
	 */
	private static function step_settings() {
		$state = self::state();
		self::set_option( $state, 'woocommerce_currency', 'PHP' );
		self::set_option( $state, 'woocommerce_currency_pos', 'left' );
		self::set_option( $state, 'woocommerce_price_thousand_sep', ',' );
		self::set_option( $state, 'woocommerce_price_decimal_sep', '.' );
		self::set_option( $state, 'woocommerce_price_num_decimals', '2' );
		self::set_option( $state, 'woocommerce_default_country', 'PH:00' );
		self::set_option( $state, 'woocommerce_allowed_countries', 'specific' );
		self::set_option( $state, 'woocommerce_specific_allowed_countries', array( 'PH' ) );
		self::set_option( $state, 'woocommerce_ship_to_countries', '' );
		self::set_option( $state, 'woocommerce_enable_reviews', 'yes' );
		self::set_option( $state, 'woocommerce_review_rating_verification_label', 'yes' );
		self::set_option( $state, 'woocommerce_enable_ajax_add_to_cart', 'yes' );
		// Riders and pickup staff need a number to call.
		self::set_option( $state, 'woocommerce_checkout_phone_field', 'required' );

		$cod                 = array_filter( (array) get_option( 'woocommerce_cod_settings', array() ), 'is_string', ARRAY_FILTER_USE_KEY );
		$cod['enabled']      = 'yes';
		$cod['title']        = __( 'Pay at pickup (cash)', 'nexora-commerce-toolkit' );
		$cod['description']  = __( 'Pay in cash when you collect your order at the store. (Demo store — no real orders are fulfilled.)', 'nexora-commerce-toolkit' );
		$cod['enable_for_methods'] = array( Nexora_Toolkit_Fulfilment::PICKUP );
		self::set_option( $state, 'woocommerce_cod_settings', $cod );

		$bacs                = array_filter( (array) get_option( 'woocommerce_bacs_settings', array() ), 'is_string', ARRAY_FILTER_USE_KEY );
		$bacs['enabled']     = 'yes';
		$bacs['title']       = __( 'Bank transfer', 'nexora-commerce-toolkit' );
		$bacs['description'] = __( 'Demo bank transfer. Replace with your real bank account details in WooCommerce → Settings → Payments before going live.', 'nexora-commerce-toolkit' );
		self::set_option( $state, 'woocommerce_bacs_settings', $bacs );

		$messages = array( __( 'Currency set to Philippine Peso (₱) and store country to Metro Manila, Philippines.', 'nexora-commerce-toolkit' ) );

		if ( ! $state['zone_id'] || ! WC_Shipping_Zones::get_zone( $state['zone_id'] ) ) {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name( __( 'Philippines (Freshtastic demo)', 'nexora-commerce-toolkit' ) );
			$zone->set_zone_order( 0 );
			$zone->add_location( 'PH', 'country' );
			$zone->save();

			WC_Cache_Helper::get_transient_version( 'shipping', true );
			$state['zone_id'] = $zone->get_id();
			$messages[]       = __( 'Created shipping zone “Philippines (Freshtastic demo)”.', 'nexora-commerce-toolkit' );
		}
		$messages = array_merge( $messages, self::add_fulfilment_methods( WC_Shipping_Zones::get_zone( $state['zone_id'] ) ) );
		$messages[] = __( 'Enabled Pay at pickup (cash, store pickup only) and Bank transfer (offline methods). GCash, Maya and cards need a payment gateway plugin — see docs.', 'nexora-commerce-toolkit' );
		self::save_state( $state );
		return array( 'messages' => $messages );
	}

	/**
	 * Courier delivery (customer books) and store pickup on the demo zone, once each.
	 *
	 * @param WC_Shipping_Zone|false $zone Zone.
	 * @return string[] Messages.
	 */
	private static function add_fulfilment_methods( $zone ) {
		if ( ! $zone ) {
			return array();
		}
		$have = array();
		foreach ( $zone->get_shipping_methods() as $method ) {
			$have[] = $method->id;
		}
		$added = array();
		if ( ! in_array( Nexora_Toolkit_Fulfilment::PICKUP, $have, true ) ) {
			$id = $zone->add_shipping_method( Nexora_Toolkit_Fulfilment::PICKUP );
			update_option(
				'woocommerce_' . Nexora_Toolkit_Fulfilment::PICKUP . '_' . $id . '_settings',
				array(
					'title'      => __( 'Store pickup', 'nexora-commerce-toolkit' ),
					'locations'  => __( 'Main stall | Stall 12, Freshtastic Public Market, Aurora Blvd, Cubao, Quezon City | Mon–Sat 6:00 am–6:00 pm', 'nexora-commerce-toolkit' ) . "\n" . __( 'Dry goods warehouse | 45 Kalayaan Ave, Diliman, Quezon City | Mon–Fri 8:00 am–5:00 pm', 'nexora-commerce-toolkit' ),
					'fee'        => '0',
					'lead_hours' => '2',
					'days_ahead' => '6',
					'slots'      => "07:00-09:00\n09:00-11:00\n13:00-15:00\n15:00-17:00",
				)
			);
			$added[] = __( 'Store pickup', 'nexora-commerce-toolkit' );
		}
		if ( ! in_array( Nexora_Toolkit_Fulfilment::COURIER, $have, true ) ) {
			$id = $zone->add_shipping_method( Nexora_Toolkit_Fulfilment::COURIER );
			update_option(
				'woocommerce_' . Nexora_Toolkit_Fulfilment::COURIER . '_' . $id . '_settings',
				array(
					'title'         => __( 'Courier delivery — you book the rider', 'nexora-commerce-toolkit' ),
					'pickup_point'  => __( 'Stall 12, Freshtastic Public Market, Aurora Blvd, Cubao, Quezon City', 'nexora-commerce-toolkit' ),
					'contact_phone' => '+63 917 000 0000',
					'fee'           => '0',
					'lead_hours'    => '2',
					'days_ahead'    => '6',
				)
			);
			$added[] = __( 'Courier delivery (customer books)', 'nexora-commerce-toolkit' );
		}
		if ( ! $added ) {
			return array();
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		/* translators: %s: shipping method names */
		return array( sprintf( __( 'Added shipping options: %s.', 'nexora-commerce-toolkit' ), implode( ', ', $added ) ) );
	}

	/**
	 * Categories (with images) and global attributes.
	 *
	 * @return array
	 */
	private static function step_categories() {
		$data     = self::data( 'products' );
		$messages = array();
		$order    = 0;
		foreach ( $data['categories'] as $cat ) {
			$args = array( 'slug' => $cat['slug'], 'description' => $cat['description'] );
			if ( ! empty( $cat['parent'] ) ) {
				$parent = get_term_by( 'slug', $cat['parent'], 'product_cat' );
				if ( $parent ) {
					$args['parent'] = (int) $parent->term_id;
				}
			}
			$id = self::term( $cat['name'], 'product_cat', $args );
			if ( ! $id ) {
				continue;
			}
			update_term_meta( $id, 'order', $order++ );
			if ( ! get_term_meta( $id, 'thumbnail_id', true ) ) {
				$img = self::image( 'category-' . $cat['slug'] . '.jpg', $cat['name'] );
				if ( $img ) {
					update_term_meta( $id, 'thumbnail_id', $img );
				}
			}
		}
		$messages[] = sprintf( /* translators: %d: count */ __( '%d product categories ready.', 'nexora-commerce-toolkit' ), count( $data['categories'] ) );

		$state = self::state();
		$labels = array( 'color' => __( 'Color', 'nexora-commerce-toolkit' ), 'size' => __( 'Size', 'nexora-commerce-toolkit' ) );
		$used   = array();
		foreach ( $data['products'] as $prod ) {
			foreach ( $prod['attributes'] as $attr ) {
				if ( ! empty( $attr['taxonomy'] ) && isset( $labels[ $attr['taxonomy'] ] ) ) {
					$used[ $attr['taxonomy'] ] = $labels[ $attr['taxonomy'] ];
				}
			}
		}
		foreach ( $used as $slug => $label ) {
			if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
				$attr_id = wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
				if ( ! is_wp_error( $attr_id ) ) {
					$state['attributes'][] = (int) $attr_id;
					$messages[]            = sprintf( /* translators: %s: attribute */ __( 'Created global attribute “%s”.', 'nexora-commerce-toolkit' ), $label );
				}
			}
			self::ensure_taxonomy( 'pa_' . $slug, $label );
		}
		self::save_state( $state );
		return array( 'messages' => $messages );
	}

	/**
	 * Register an attribute taxonomy created during this request.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $label    Label.
	 */
	private static function ensure_taxonomy( $taxonomy, $label ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'label' => $label, 'query_var' => true, 'rewrite' => false ) );
		}
	}

	/**
	 * Products, in batches.
	 *
	 * @param int $offset Offset.
	 * @return array
	 */
	private static function step_catalog( $offset ) {
		$total    = count( self::data( 'products' )['products'] );
		$messages = array();
		// Reviews are their own step, so unticking them is respected.
		foreach ( Nexora_Store_Importer::seed_batch( $offset, self::BATCH, false ) as $name => $created ) {
			$messages[] = $created
				/* translators: %s: product name */
				? sprintf( __( 'Created “%s”.', 'nexora-commerce-toolkit' ), $name )
				/* translators: %s: product name */
				: sprintf( __( 'Skipped “%s” (a product with its SKU already exists).', 'nexora-commerce-toolkit' ), $name );
		}
		$next = $offset + self::BATCH;
		return array(
			'done'     => $next >= $total,
			'offset'   => $next,
			'progress' => array( min( $next, $total ), $total ),
			'messages' => $messages,
		);
	}

	/**
	 * Product ID by SKU, with either store engine.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	private static function product_id_by_sku( $sku ) {
		global $wpdb;
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			return (int) wc_get_product_id_by_sku( $sku );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type = 'product' LIMIT 1", $sku ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete a product and its options, with either store engine.
	 *
	 * @param int $id Product.
	 */
	private static function delete_product( $id ) {
		$children = get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			foreach ( $children as $child ) {
				$variation = wc_get_product( $child );
				if ( $variation ) {
					$variation->delete( true );
				}
			}
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
			return;
		}
		foreach ( $children as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}

	/**
	 * Create products.
	 *
	 * @param int $offset Offset.
	 * @return array
	 */
	private static function step_products( $offset ) {
		$products = self::data( 'products' )['products'];
		$batch    = array_slice( $products, $offset, self::BATCH );
		$messages = array();
		self::ensure_taxonomy( 'pa_color', 'Color' );
		self::ensure_taxonomy( 'pa_size', 'Size' );

		foreach ( $batch as $i => $p ) {
			$p['_index'] = $offset + $i;
			$existing    = wc_get_product_id_by_sku( $p['sku'] );
			if ( $existing ) {
				$messages[] = get_post_meta( $existing, self::META, true )
					/* translators: %s: product name */
					? sprintf( __( 'Skipped “%s” (already imported).', 'nexora-commerce-toolkit' ), $p['name'] )
					/* translators: 1: product name, 2: SKU */
					: sprintf( __( 'Skipped “%1$s”: a non-demo product already uses SKU %2$s.', 'nexora-commerce-toolkit' ), $p['name'], $p['sku'] );
				continue;
			}
			$id         = self::create_product( $p );
			/* translators: %s: product name */
			$messages[] = $id ? sprintf( __( 'Created “%s”.', 'nexora-commerce-toolkit' ), $p['name'] ) : sprintf( __( 'Could not create “%s”.', 'nexora-commerce-toolkit' ), $p['name'] );
		}

		$next = $offset + self::BATCH;
		return array(
			'done'     => $next >= count( $products ),
			'offset'   => $next,
			'progress' => array( min( $next, count( $products ) ), count( $products ) ),
			'messages' => $messages,
		);
	}

	/**
	 * Create one product (simple or variable) through the WooCommerce CRUD API.
	 *
	 * @param array $p Product data.
	 * @return int
	 */
	private static function create_product( $p ) {
		$is_variable = 'variable' === $p['type'];
		$product     = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$cat         = get_term_by( 'slug', $p['category'], 'product_cat' );

		$product->set_name( $p['name'] );
		$product->set_slug( $p['slug'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( wp_kses_post( $p['description'] ) );
		$product->set_short_description( wp_kses_post( $p['short_description'] ) );
		$product->set_sku( $p['sku'] );
		$product->set_featured( ! empty( $p['featured'] ) );
		$product->set_reviews_allowed( true );
		$product->set_total_sales( isset( $p['total_sales'] ) ? (int) $p['total_sales'] : 0 );
		// Stagger publish dates so "New" badges and "New arrivals" are meaningful (about one in five is recent).
		$index    = isset( $p['_index'] ) ? (int) $p['_index'] : 0;
		$days_ago = 0 === $index % 5 ? 2 + $index : 35 + ( $index * 7 ) % 120;
		$product->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		if ( $cat ) {
			$product->set_category_ids( array( $cat->term_id ) );
		}
		$tags = array();
		foreach ( (array) ( isset( $p['tags'] ) ? $p['tags'] : array() ) as $tag ) {
			$tags[] = self::term( ucwords( str_replace( '-', ' ', $tag ) ), 'product_tag', array( 'slug' => $tag ) );
		}
		$product->set_tag_ids( array_filter( $tags ) );

		if ( ! $is_variable ) {
			$product->set_regular_price( $p['regular_price'] );
			if ( ! empty( $p['sale_price'] ) ) {
				$product->set_sale_price( $p['sale_price'] );
			}
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $p['stock_qty'] );
			$product->set_low_stock_amount( 5 );
			if ( isset( $p['unit'] ) && 'kg' === $p['unit'] ) {
				$product->set_weight( '1' );
			} elseif ( ! empty( $p['measure'] ) && isset( $p['measure_unit'] ) && 'kg' === $p['measure_unit'] ) {
				$product->set_weight( (string) $p['measure'] );
			}
			if ( ! empty( $p['measure'] ) ) {
				$product->update_meta_data( '_nexora_measure', (string) $p['measure'] );
			}
		}
		$product->update_meta_data( '_nexora_unit', isset( $p['unit'] ) ? $p['unit'] : '' );
		$product->update_meta_data( '_nexora_origin', isset( $p['origin'] ) ? $p['origin'] : '' );
		$product->update_meta_data( '_nexora_measure_unit', isset( $p['measure_unit'] ) ? $p['measure_unit'] : '' );

		// Attributes.
		$attributes = array();
		$position   = 0;
		foreach ( $p['attributes'] as $a ) {
			$attr = new WC_Product_Attribute();
			if ( ! empty( $a['taxonomy'] ) ) {
				$taxonomy = 'pa_' . $a['taxonomy'];
				$term_ids = array();
				foreach ( $a['options'] as $option ) {
					$term_ids[] = self::term( $option, $taxonomy );
				}
				$attr->set_id( wc_attribute_taxonomy_id_by_name( $a['taxonomy'] ) );
				$attr->set_name( $taxonomy );
				$attr->set_options( array_filter( $term_ids ) );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( $a['name'] );
				$attr->set_options( $a['options'] );
			}
			$attr->set_position( $position++ );
			$attr->set_visible( true );
			$attr->set_variation( $is_variable && ! empty( $a['variation'] ) );
			$attributes[] = $attr;
		}
		$product->set_attributes( $attributes );
		$id = $product->save();
		if ( ! $id ) {
			return 0;
		}
		self::tag_post( $id, 'product:' . $p['sku'] );

		// Images: main + detail gallery image.
		$main   = self::image( $p['slug'] . '.jpg', $p['name'], $id );
		$detail = self::image( $p['slug'] . '-detail.jpg', sprintf( /* translators: %s: product name */ __( '%s — detail view', 'nexora-commerce-toolkit' ), $p['name'] ), $id );
		$product->set_image_id( $main );
		$product->set_gallery_image_ids( array_filter( array( $detail ) ) );

		// Variations.
		if ( $is_variable ) {
			$swatches = isset( $p['swatches'] ) ? $p['swatches'] : array();
			$gallery  = array_filter( array( $detail ) );
			$suffix   = 1;
			foreach ( $p['variations'] as $v ) {
				$variation  = new WC_Product_Variation();
				$variation->set_parent_id( $id );
				$var_attrs = array();
				foreach ( $v['attributes'] as $key => $value ) {
					$is_tax                                  = in_array( $key, array( 'color', 'size' ), true );
					$var_attrs[ $is_tax ? 'pa_' . $key : $key ] = $is_tax ? sanitize_title( $value ) : $value;
				}
				$variation->set_attributes( $var_attrs );
				$variation->set_sku( $p['sku'] . '-' . str_pad( (string) $suffix++, 2, '0', STR_PAD_LEFT ) );
				$variation->set_regular_price( $v['regular_price'] );
				if ( ! empty( $v['sale_price'] ) ) {
					$variation->set_sale_price( $v['sale_price'] );
				}
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( (int) $v['stock_qty'] );
				$variation->set_status( 'publish' );
				if ( ! empty( $v['measure'] ) ) {
					$variation->update_meta_data( '_nexora_measure', (string) $v['measure'] );
					if ( isset( $p['measure_unit'] ) && 'kg' === $p['measure_unit'] ) {
						$variation->set_weight( (string) $v['measure'] );
					}
				}
				if ( isset( $v['attributes']['color'], $swatches[ $v['attributes']['color'] ] ) ) {
					$color_file = strtolower( $swatches[ $v['attributes']['color'] ] ) === strtolower( $p['color'] )
						? $p['slug'] . '.jpg'
						: $p['slug'] . '-' . sanitize_title( $v['attributes']['color'] ) . '.jpg';
					$img        = self::image( $color_file, $p['name'] . ' — ' . $v['attributes']['color'], $id );
					if ( $img ) {
						$variation->set_image_id( $img );
						if ( $img !== $main ) {
							$gallery[] = $img;
						}
					}
				}
				$vid = $variation->save();
				self::tag_post( $vid, 'variation:' . $variation->get_sku() );
			}
			$product->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );
		}
		$product->save();
		if ( $is_variable ) {
			WC_Product_Variable::sync( $id );
		}

		// Seed an illustrative previous price so demo products show a market change (labelled demo content).
		if ( ! empty( $p['history'] ) && class_exists( 'Nexora_Toolkit_Market' ) ) {
			$fresh   = wc_get_product( $id );
			$current = $fresh ? Nexora_Toolkit_Market::reference_price( $fresh ) : 0;
			if ( $current > 0 ) {
				update_post_meta(
					$id,
					Nexora_Toolkit_Market::HISTORY,
					array(
						array( 't' => time() - (int) $p['history']['days_ago'] * DAY_IN_SECONDS, 'p' => (float) $p['history']['previous'] ),
						array( 't' => time() - DAY_IN_SECONDS, 'p' => $current ),
					)
				);
			}
		}

		// Optional SEO plugin fields, only when the plugin is active.
		$seo_desc = wp_strip_all_tags( $p['short_description'] );
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $id, '_yoast_wpseo_metadesc', wp_html_excerpt( $seo_desc, 155 ) );
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $id, 'rank_math_description', wp_html_excerpt( $seo_desc, 155 ) );
		}
		return $id;
	}

	/**
	 * Cross-sells.
	 *
	 * @return array
	 */
	private static function step_relations() {
		$count = 0;
		foreach ( self::CROSS_SELLS as $sku => $skus ) {
			$product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
			if ( ! $product || ! $product->get_meta( self::META ) ) {
				continue;
			}
			$ids = array_filter( array_map( 'wc_get_product_id_by_sku', $skus ) );
			$product->set_cross_sell_ids( $ids );
			$product->save();
			$count++;
		}
		/* translators: %d: count */
		return array( 'messages' => array( sprintf( __( 'Added “Frequently bought together” links for %d products.', 'nexora-commerce-toolkit' ), $count ) ) );
	}

	/**
	 * Sample reviews (labelled) and testimonials.
	 *
	 * @return array
	 */
	private static function step_reviews() {
		$added = 0;
		foreach ( self::data( 'products' )['products'] as $p ) {
			if ( empty( $p['reviews'] ) ) {
				continue;
			}
			$product_id = self::product_id_by_sku( $p['sku'] );
			if ( ! $product_id || ! get_post_meta( $product_id, self::META, true ) ) {
				continue;
			}
			// A catalogue added with "wp nexora store seed" already has its reviews.
			if ( Nexora_Store::active() && get_comments( array( 'post_id' => $product_id, 'type' => 'review', 'meta_key' => self::META, 'count' => true, 'status' => 'all' ) ) ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				continue;
			}
			foreach ( $p['reviews'] as $i => $r ) {
				$key      = 'review:' . $p['sku'] . ':' . $i;
				$existing = get_comments( array( 'type' => 'review', 'meta_key' => self::KEY, 'meta_value' => $key, 'count' => true, 'status' => 'all' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( $existing ) {
					continue;
				}
				$cid = wp_insert_comment(
					array(
						'comment_post_ID'      => $product_id,
						'comment_author'       => $r['author'],
						'comment_author_email' => 'sample-reviewer@example.invalid',
						'comment_content'      => $r['content'],
						'comment_type'         => 'review',
						'comment_approved'     => 1,
						'comment_date'         => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * 3 * DAY_IN_SECONDS ),
					)
				);
				if ( $cid ) {
					add_comment_meta( $cid, 'rating', (int) $r['rating'] );
					add_comment_meta( $cid, 'verified', 0 );
					add_comment_meta( $cid, self::META, 1 );
					add_comment_meta( $cid, self::KEY, $key );
					$added++;
				}
			}
			if ( Nexora_Store::active() ) {
				Nexora_Store_Catalog::update_rating( $product_id );
			} else {
				WC_Comments::clear_transients( $product_id );
			}
		}

		$testimonials = 0;
		if ( post_type_exists( 'nexora_testimonial' ) ) {
			foreach ( self::data( 'content' )['testimonials'] as $i => $t ) {
				$key = 'testimonial:' . $i;
				if ( self::find_post( $key, 'nexora_testimonial' ) ) {
					continue;
				}
				$id = wp_insert_post( array( 'post_type' => 'nexora_testimonial', 'post_status' => 'publish', 'post_title' => $t['name'], 'post_content' => $t['quote'] ) );
				if ( $id && ! is_wp_error( $id ) ) {
					update_post_meta( $id, 'nexora_role', $t['role'] );
					update_post_meta( $id, 'nexora_rating', (int) $t['rating'] );
					self::tag_post( $id, $key );
					$testimonials++;
				}
			}
		}
		return array(
			'messages' => array(
				/* translators: 1: reviews, 2: testimonials */
				sprintf( __( 'Added %1$d sample reviews and %2$d sample testimonials, all labelled as demo content.', 'nexora-commerce-toolkit' ), $added, $testimonials ),
			),
		);
	}

	/**
	 * Journal posts.
	 *
	 * @return array
	 */
	private static function step_posts() {
		$created = 0;
		foreach ( self::data( 'content' )['posts'] as $i => $post ) {
			$key = 'post:' . $post['key'];
			if ( self::find_post( $key, 'post' ) ) {
				continue;
			}
			$cat = self::term( $post['category'], 'category' );
			$id  = wp_insert_post(
				array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $post['title'],
					'post_excerpt'  => $post['excerpt'],
					'post_content'  => $post['content'],
					'post_category' => array( $cat ),
					'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * 4 * DAY_IN_SECONDS ),
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				self::tag_post( $id, $key );
				$img = self::image( $post['image'], $post['title'], $id );
				if ( $img ) {
					set_post_thumbnail( $id, $img );
				}
				$created++;
			}
		}
		/* translators: %d: count */
		return array( 'messages' => array( sprintf( __( 'Created %d journal posts.', 'nexora-commerce-toolkit' ), $created ) ) );
	}

	/**
	 * Pages. Existing pages with the same slug are left untouched.
	 *
	 * @return array
	 */
	private static function step_pages() {
		$created = 0;
		$skipped = array();
		foreach ( self::data( 'content' )['pages'] as $page ) {
			$key = 'page:' . $page['key'];
			if ( self::find_post( $key, 'page' ) ) {
				continue;
			}
			if ( get_page_by_path( $page['slug'] ) ) {
				$skipped[] = $page['title'];
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['content'] ) );
			if ( $id && ! is_wp_error( $id ) ) {
				self::tag_post( $id, $key );
				$created++;
			}
		}
		/* translators: %d: count */
		$messages = array( sprintf( __( 'Created %d pages.', 'nexora-commerce-toolkit' ), $created ) );
		if ( $skipped ) {
			/* translators: %s: page titles */
			$messages[] = sprintf( __( 'Kept your existing pages: %s.', 'nexora-commerce-toolkit' ), implode( ', ', $skipped ) );
		}
		return array( 'messages' => $messages );
	}

	/**
	 * URL of a demo page, falling back to an existing page with the slug.
	 *
	 * @param string $key  Page key.
	 * @param string $slug Slug.
	 * @return int
	 */
	private static function page_id( $key, $slug ) {
		$id = self::find_post( 'page:' . $key, 'page' );
		if ( ! $id ) {
			$page = get_page_by_path( $slug );
			$id   = $page ? $page->ID : 0;
		}
		return (int) $id;
	}

	/**
	 * Menus. Only fills theme locations that are currently empty.
	 *
	 * @return array
	 */
	private static function step_menus() {
		$state = self::state();
		$native  = Nexora_Store::active();
		$shop    = $native ? Nexora_Store::shop_url() : wc_get_page_permalink( 'shop' );
		$account = $native ? (int) Nexora_Store::page_id( 'account' ) : (int) wc_get_page_id( 'myaccount' );
		$menus = array(
			'primary'  => array(
				'name'  => __( 'Primary navigation', 'nexora-commerce-toolkit' ),
				'items' => array(
					array( 'term_slug', 'wet-market' ),
					array( 'term_slug', 'dry-market' ),
					array( 'custom', __( 'Price Board', 'nexora-commerce-toolkit' ), home_url( '/#price-board' ) ),
					array( 'custom', __( 'Deals', 'nexora-commerce-toolkit' ), add_query_arg( 'on_sale', '1', $shop ) ),
					array( 'page', self::page_id( 'journal', 'journal' ), __( 'Journal', 'nexora-commerce-toolkit' ) ),
				),
			),
			'footer-1' => array( 'name' => __( 'Shop', 'nexora-commerce-toolkit' ), 'items' => array() ),
			'footer-2' => array(
				'name'  => __( 'Customer service', 'nexora-commerce-toolkit' ),
				'items' => array(
					array( 'page', self::page_id( 'request-a-quote', 'request-a-quote' ) ),
					array( 'page', self::page_id( 'shipping-returns', 'shipping-returns' ) ),
					array( 'page', self::page_id( 'faq', 'faq' ) ),
					array( 'page', self::page_id( 'contact', 'contact' ) ),
					array( 'page', $account ),
					array( 'page', self::page_id( 'wishlist', 'wishlist' ) ),
				),
			),
			'footer-3' => array(
				'name'  => __( 'Company', 'nexora-commerce-toolkit' ),
				'items' => array(
					array( 'page', self::page_id( 'about', 'about' ) ),
					array( 'page', self::page_id( 'journal', 'journal' ) ),
					array( 'page', self::page_id( 'terms', 'terms-and-conditions' ) ),
				),
			),
			'legal'    => array(
				'name'  => __( 'Legal', 'nexora-commerce-toolkit' ),
				'items' => array(
					array( 'page', self::page_id( 'terms', 'terms-and-conditions' ) ),
					array( 'page', (int) get_option( 'wp_page_for_privacy_policy' ) ),
				),
			),
		);
		foreach ( self::data( 'products' )['categories'] as $cat ) {
			$term = get_term_by( 'slug', $cat['slug'], 'product_cat' );
			if ( $term && ! empty( $cat['parent'] ) ) {
				$menus['footer-1']['items'][] = array( 'term', $term->term_id );
			}
		}

		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$messages  = array();
		foreach ( $menus as $location => $def ) {
			// Reuse a menu this importer created earlier (tracked by ID), never a menu the owner made.
			$menu = null;
			foreach ( $state['menus'] as $known ) {
				$candidate = wp_get_nav_menu_object( $known );
				if ( $candidate && get_term_meta( $candidate->term_id, self::KEY, true ) === 'menu:' . $location ) {
					$menu = $candidate;
				}
			}
			if ( ! $menu ) {
				$name    = wp_get_nav_menu_object( $def['name'] ) ? $def['name'] . ' (Freshtastic)' : $def['name'];
				$menu_id = wp_create_nav_menu( $name );
				if ( is_wp_error( $menu_id ) ) {
					continue;
				}
				update_term_meta( $menu_id, self::META, 1 );
				update_term_meta( $menu_id, self::KEY, 'menu:' . $location );
				$state['menus'][] = (int) $menu_id;
				foreach ( $def['items'] as $item ) {
					$args = array( 'menu-item-status' => 'publish' );
					if ( 'custom' === $item[0] ) {
						$args += array( 'menu-item-type' => 'custom', 'menu-item-title' => $item[1], 'menu-item-url' => $item[2] );
					} elseif ( 'page' === $item[0] && $item[1] && 'publish' === get_post_status( $item[1] ) ) {
						$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $item[1] );
						if ( ! empty( $item[2] ) ) {
							$args['menu-item-title'] = $item[2];
						}
					} elseif ( 'term_slug' === $item[0] && get_term_by( 'slug', $item[1], 'product_cat' ) ) {
						$args += array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'product_cat', 'menu-item-object-id' => get_term_by( 'slug', $item[1], 'product_cat' )->term_id );
					} elseif ( 'term' === $item[0] ) {
						$args += array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $item[1] );
					} else {
						continue;
					}
					$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
					if ( ! is_wp_error( $item_id ) ) {
						update_post_meta( $item_id, self::META, 1 );
					}
				}
			} else {
				$menu_id = $menu->term_id;
			}
			if ( empty( $locations[ $location ] ) ) {
				$locations[ $location ]         = (int) $menu_id;
				$state['locations'][ $location ] = (int) $menu_id;
				/* translators: 1: menu, 2: location */
				$messages[] = sprintf( __( 'Assigned “%1$s” to %2$s.', 'nexora-commerce-toolkit' ), $def['name'], $location );
			}
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		self::save_state( $state );
		if ( ! $messages ) {
			$messages[] = __( 'Menus created. Your existing menu assignments were kept.', 'nexora-commerce-toolkit' );
		}
		return array( 'messages' => $messages );
	}

	/**
	 * Static front page + journal page + homepage images in the theme Customizer.
	 *
	 * @return array
	 */
	private static function step_homepage() {
		$state    = self::state();
		$messages = array();
		$home     = self::page_id( 'home', 'home' );
		$journal  = self::page_id( 'journal', 'journal' );

		if ( ! $home ) {
			$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_name' => 'home' ) );
			if ( $home && ! is_wp_error( $home ) ) {
				self::tag_post( $home, 'page:home' );
			}
		}
		if ( $home ) {
			self::set_option( $state, 'show_on_front', 'page' );
			self::set_option( $state, 'page_on_front', (int) $home );
			$messages[] = __( 'Set “Home” as the static front page.', 'nexora-commerce-toolkit' );
		}
		if ( $journal && ! get_option( 'page_for_posts' ) ) {
			self::set_option( $state, 'page_for_posts', (int) $journal );
		}

		if ( 'nexora-commerce' === get_template() ) {
			$images = array(
				'nexora_hero_image'      => array( 'hero-main.jpg', __( 'Rice sack, fresh fish on ice, cabbage, tomatoes and a tray of eggs over a market price chart', 'nexora-commerce-toolkit' ) ),
				'nexora_campaign_image'  => array( 'campaign-banner.jpg', __( 'Sacks of rice and sugar for bulk buyers', 'nexora-commerce-toolkit' ) ),
				'nexora_final_cta_image' => array( 'cta-banner.jpg', __( 'Mangoes, pechay and calamansi', 'nexora-commerce-toolkit' ) ),
			);
			foreach ( $images as $mod => $def ) {
				$img = self::image( $def[0], $def[1] );
				if ( $img ) {
					if ( ! array_key_exists( $mod, $state['theme_mods'] ) ) {
						$state['theme_mods'][ $mod ] = array( 'old' => get_theme_mod( $mod, null ), 'new' => $img );
					}
					set_theme_mod( $mod, $img );
				}
			}
			$messages[] = __( 'Applied hero, campaign and banner images in Customizer → Freshtastic Homepage.', 'nexora-commerce-toolkit' );
		}
		self::save_state( $state );
		return array( 'messages' => $messages );
	}

	/**
	 * Finish: record time, flush caches.
	 *
	 * @return array
	 */
	private static function step_finish() {
		$state                = self::state();
		$state['imported_at'] = gmdate( 'c' );
		self::save_state( $state );
		update_option( 'nexora_products_cache_v', time(), false );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		if ( class_exists( 'WC_Post_Data' ) && function_exists( 'wc_update_product_lookup_tables_is_running' ) && ! wc_update_product_lookup_tables_is_running() ) {
			wc_update_product_lookup_tables();
		}
		if ( Nexora_Store::active() ) {
			Nexora_Store_Catalog::flush();
		}
		flush_rewrite_rules( false );
		$messages = array( __( 'Demo store is ready. Visit the homepage to explore it.', 'nexora-commerce-toolkit' ) );
		if ( 'yes' === get_option( 'woocommerce_coming_soon' ) ) {
			$messages[] = __( 'Note: WooCommerce “Coming soon” mode is on, so logged-out visitors see a placeholder instead of shop pages. Change it in WooCommerce → Settings → Site visibility when you are ready.', 'nexora-commerce-toolkit' );
		}
		return array( 'messages' => $messages );
	}

	/* ------------------------------------------------------------------
	 * Cleanup
	 * --------------------------------------------------------------- */

	/**
	 * Counts of demo content currently present.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		$count = static function ( $type ) {
			return count(
				get_posts(
					array(
						'post_type'   => $type,
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
						'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					)
				)
			);
		};
		return array(
			'products'     => $count( 'product' ),
			'images'       => $count( 'attachment' ),
			'posts'        => $count( 'post' ),
			'pages'        => $count( 'page' ),
			'testimonials' => post_type_exists( 'nexora_testimonial' ) ? $count( 'nexora_testimonial' ) : 0,
			'reviews'      => (int) get_comments( array( 'type' => 'review', 'meta_key' => self::META, 'count' => true, 'status' => 'all' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);
	}

	/**
	 * Remove demo content in batches. Never touches orders, customers or untagged content.
	 *
	 * @param int $offset Unused (batched by repeated calls until nothing is left).
	 * @return array
	 */
	private static function step_cleanup( $offset ) {
		$messages = array();

		// 1. Reviews.
		// WooCommerce hides product reviews from generic comment queries, so ask for the review type explicitly.
		$comment_ids = get_comments( array( 'type' => 'review', 'meta_key' => self::META, 'fields' => 'ids', 'status' => 'all', 'number' => 200 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$touched     = array();
		foreach ( $comment_ids as $cid ) {
			$touched[] = (int) get_comment( $cid )->comment_post_ID;
			wp_delete_comment( $cid, true );
		}
		foreach ( array_unique( $touched ) as $pid ) {
			if ( 'product' === get_post_type( $pid ) && class_exists( 'WC_Comments' ) ) {
				WC_Comments::clear_transients( $pid );
			}
		}

		// 2. Posts of every type, a batch at a time (products delete their variations).
		$ids = get_posts(
			array(
				'post_type'   => array( 'product', 'post', 'page', 'nexora_testimonial', 'nav_menu_item' ),
				'post_status' => 'any',
				'numberposts' => 25,
				'fields'      => 'ids',
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		foreach ( $ids as $id ) {
			if ( 'product' === get_post_type( $id ) ) {
				self::delete_product( $id );
			} else {
				wp_delete_post( $id, true );
			}
		}
		if ( $ids ) {
			/* translators: %d: count */
			return array( 'done' => false, 'messages' => array( sprintf( __( 'Removed %d demo items…', 'nexora-commerce-toolkit' ), count( $ids ) ) ) );
		}

		// 3. Media.
		$attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => 30, 'fields' => 'ids', 'meta_key' => self::META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		if ( $attachments ) {
			/* translators: %d: count */
			return array( 'done' => false, 'messages' => array( sprintf( __( 'Removed %d demo images…', 'nexora-commerce-toolkit' ), count( $attachments ) ) ) );
		}

		// 4. Terms the importer created, only if nothing else uses them now.
		$state = self::state();
		foreach ( array( 'product_cat', 'product_tag', 'category', 'pa_color', 'pa_size' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'meta_key' => self::META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$in_use = get_objects_in_term( $term->term_id, $taxonomy );
				if ( ! $in_use && (int) get_option( 'default_product_cat' ) !== $term->term_id && (int) get_option( 'default_category' ) !== $term->term_id ) {
					$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
					wp_delete_term( $term->term_id, $taxonomy );
					if ( $thumb && get_post_meta( $thumb, self::META, true ) ) {
						wp_delete_attachment( $thumb, true );
					}
				}
			}
		}
		foreach ( function_exists( 'wc_get_attribute' ) ? $state['attributes'] : array() as $attr_id ) {
			$attr = wc_get_attribute( $attr_id );
			if ( $attr && ! get_terms( array( 'taxonomy' => $attr->slug, 'hide_empty' => false, 'fields' => 'ids' ) ) ) {
				wc_delete_attribute( $attr_id );
			}
		}

		// 5. Menus.
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		foreach ( $state['locations'] as $location => $menu_id ) {
			if ( isset( $locations[ $location ] ) && (int) $locations[ $location ] === (int) $menu_id ) {
				unset( $locations[ $location ] );
			}
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		foreach ( $state['menus'] as $menu_id ) {
			if ( get_term_meta( $menu_id, self::META, true ) ) {
				wp_delete_nav_menu( $menu_id );
			}
		}

		// 6. Shipping zone created by the importer.
		if ( $state['zone_id'] && ! class_exists( 'WC_Shipping_Zones' ) ) {
			$messages[] = __( 'The demo shipping zone is stored in WooCommerce, so it was left in place. You can delete it under WooCommerce → Settings → Shipping if you switch back.', 'nexora-commerce-toolkit' );
		} elseif ( $state['zone_id'] ) {
			$zone = WC_Shipping_Zones::get_zone( $state['zone_id'] );
			if ( $zone ) {
				$zone->delete();
			}
		}

		// 7. Restore settings and theme mods that still hold the demo value.
		$restored = 0;
		foreach ( $state['options'] as $option => $vals ) {
			if ( get_option( $option ) == $vals['new'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- options may round-trip as strings.
				null === $vals['old'] ? delete_option( $option ) : update_option( $option, $vals['old'] );
				$restored++;
			}
		}
		foreach ( $state['theme_mods'] as $mod => $vals ) {
			if ( (int) get_theme_mod( $mod ) === (int) $vals['new'] ) {
				null === $vals['old'] ? remove_theme_mod( $mod ) : set_theme_mod( $mod, $vals['old'] );
			}
		}

		delete_option( self::STATE );
		if ( Nexora_Store::active() ) {
			Nexora_Store_Catalog::flush();
		}
		update_option( 'nexora_products_cache_v', time(), false );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		flush_rewrite_rules( false );

		/* translators: %d: count */
		$messages[] = sprintf( __( 'Demo content removed. %d settings restored to their previous values. Orders, customers and your own content were not touched.', 'nexora-commerce-toolkit' ), $restored );
		return array( 'done' => true, 'messages' => $messages );
	}

	/**
	 * Run a full import or cleanup synchronously (CLI and no-JS fallback).
	 *
	 * @param string        $mode    import|cleanup.
	 * @param array         $choices Choices.
	 * @param callable|null $log     Logger.
	 * @return bool Success.
	 */
	public static function run_all( $mode, $choices = array(), $log = null ) {
		$steps = 'cleanup' === $mode ? array( 'cleanup' => '' ) : self::steps( $choices );
		foreach ( array_keys( $steps ) as $step ) {
			$offset = 0;
			do {
				$result = self::run_step( $step, $offset );
				foreach ( $result['messages'] as $m ) {
					if ( $log ) {
						call_user_func( $log, $m );
					}
				}
				if ( ! empty( $result['error'] ) ) {
					return false;
				}
				$offset = (int) $result['offset'];
			} while ( empty( $result['done'] ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Admin UI
	 * --------------------------------------------------------------- */

	/**
	 * Capability check used by every entry point.
	 *
	 * @return bool
	 */
	private static function can() {
		return current_user_can( 'manage_options' ) && current_user_can( Nexora_Store::active() ? Nexora_Store_Admin::CAP_STORE : 'manage_woocommerce' );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_management_page( __( 'Freshtastic Demo Content', 'nexora-commerce-toolkit' ), __( 'Freshtastic Demo Content', 'nexora-commerce-toolkit' ), 'manage_options', 'nexora-demo', array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Admin assets (importer screen only).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_nexora-demo' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'nexora-importer', NEXORA_TOOLKIT_URL . 'assets/js/importer.js', array(), NEXORA_TOOLKIT_VERSION, true );
		wp_localize_script(
			'nexora-importer',
			'nexoraImporter',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'nexora_demo' ),
				'i18n'   => array(
					'confirmImport'  => __( 'Import the Freshtastic demo store now? Existing content is kept; demo items are tagged so they can be removed later.', 'nexora-commerce-toolkit' ),
					'confirmCleanup' => __( 'Remove all Freshtastic demo content? Only items created by the importer are deleted. Orders, customers and your own content are not touched.', 'nexora-commerce-toolkit' ),
					'failed'         => __( 'The step failed. You can safely run the importer again; it will continue where it stopped.', 'nexora-commerce-toolkit' ),
					'done'           => __( 'Done.', 'nexora-commerce-toolkit' ),
				),
			)
		);
		wp_add_inline_style( 'common', '.nexora-demo .card{max-width:820px}.nexora-progress{height:10px;background:#e6e8f0;border-radius:6px;overflow:hidden;margin:12px 0}.nexora-progress span{display:block;height:100%;width:0;background:linear-gradient(90deg,#6759F5,#17C8B0);transition:width .3s}.nexora-log{max-height:280px;overflow:auto;background:#f6f7f7;padding:10px 14px;border-radius:4px;font-family:monospace;font-size:12px}.nexora-log li{margin:2px 0}.nexora-log .is-error{color:#b32d2e}.nexora-counts{display:flex;flex-wrap:wrap;gap:8px}.nexora-counts span{background:#f0f0f1;padding:4px 10px;border-radius:12px}' );
	}

	/**
	 * Admin page.
	 */
	public static function render() {
		if ( ! self::can() ) {
			echo '<div class="wrap"><p>' . esc_html__( 'You need administrator and shop manager permissions to import demo content.', 'nexora-commerce-toolkit' ) . '</p></div>';
			return;
		}
		$counts = self::counts();
		$state  = self::state();
		$has    = array_sum( $counts ) > 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['nexora_result'] ) ? sanitize_key( $_GET['nexora_result'] ) : '';
		?>
		<div class="wrap nexora-demo">
			<h1><?php esc_html_e( 'Freshtastic Demo Content', 'nexora-commerce-toolkit' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice <?php echo 'ok' === $notice ? 'notice-success' : 'notice-error'; ?>"><p><?php echo 'ok' === $notice ? esc_html__( 'Finished.', 'nexora-commerce-toolkit' ) : esc_html__( 'Something went wrong. Check requirements and try again.', 'nexora-commerce-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! class_exists( 'WooCommerce' ) && ! Nexora_Store::active() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Activate WooCommerce first.', 'nexora-commerce-toolkit' ); ?></p></div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Import the demo store', 'nexora-commerce-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Adds 36 fictional wet and dry market products (rice, vegetables, fruits, meat, poultry, eggs, fish, dry goods) in Wet Market and Dry Market categories, with units, origins, price history and original artwork, plus optional pages (including Request a Quote), journal posts, menus and labelled sample reviews. Running it again skips anything that already exists.', 'nexora-commerce-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nexora-import>
					<input type="hidden" name="action" value="nexora_demo_run">
					<input type="hidden" name="mode" value="import">
					<?php wp_nonce_field( 'nexora_demo', 'nexora_nonce' ); ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'nexora-commerce-toolkit' ); ?></legend>
						<p><label><input type="checkbox" checked disabled> <?php esc_html_e( 'Products, categories, attributes and images (required)', 'nexora-commerce-toolkit' ); ?></label></p>
						<p><label><input type="checkbox" name="choices[content]" value="1" checked> <?php esc_html_e( 'Pages (About, Request a Quote, Contact, Delivery and Returns, FAQ, Terms, Wishlist), journal posts and menus', 'nexora-commerce-toolkit' ); ?></label></p>
						<p><label><input type="checkbox" name="choices[reviews]" value="1" checked> <?php esc_html_e( 'Sample reviews and testimonials (always labelled “Sample”)', 'nexora-commerce-toolkit' ); ?></label></p>
						<p><label><input type="checkbox" name="choices[homepage]" value="1" checked> <?php esc_html_e( 'Set a static homepage and apply demo banner images', 'nexora-commerce-toolkit' ); ?></label></p>
						<?php if ( ! Nexora_Store::active() ) : ?>
							<p><label><input type="checkbox" name="choices[settings]" value="1"> <?php esc_html_e( 'Apply Philippine store settings: ₱ currency, PH-only selling, demo shipping zone with store pickup and courier delivery booked by the customer, Pay at pickup and Bank transfer', 'nexora-commerce-toolkit' ); ?></label></p>
						<?php endif; ?>
					</fieldset>
					<p><label><input type="checkbox" name="confirm" value="1" required> <strong><?php esc_html_e( 'I understand this adds demo content to this site.', 'nexora-commerce-toolkit' ); ?></strong></label></p>
					<?php submit_button( __( 'Import demo content', 'nexora-commerce-toolkit' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" data-nexora-progress hidden>
				<h2><?php esc_html_e( 'Progress', 'nexora-commerce-toolkit' ); ?></h2>
				<p data-nexora-current><?php esc_html_e( 'Starting…', 'nexora-commerce-toolkit' ); ?></p>
				<div class="nexora-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e( 'Import progress', 'nexora-commerce-toolkit' ); ?>"><span></span></div>
				<ul class="nexora-log" aria-live="polite"></ul>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Demo content on this site', 'nexora-commerce-toolkit' ); ?></h2>
				<p class="nexora-counts">
					<?php
					$labels = array(
						'products'     => __( 'Products', 'nexora-commerce-toolkit' ),
						'images'       => __( 'Images', 'nexora-commerce-toolkit' ),
						'posts'        => __( 'Posts', 'nexora-commerce-toolkit' ),
						'pages'        => __( 'Pages', 'nexora-commerce-toolkit' ),
						'reviews'      => __( 'Reviews', 'nexora-commerce-toolkit' ),
						'testimonials' => __( 'Testimonials', 'nexora-commerce-toolkit' ),
					);
					foreach ( $labels as $k => $label ) {
						printf( '<span>%1$s: <strong>%2$d</strong></span>', esc_html( $label ), (int) $counts[ $k ] );
					}
					?>
				</p>
				<?php if ( $state['imported_at'] ) : ?>
					<p>
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Last import: %s', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $state['imported_at'] ) ) ) );
						?>
					</p>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Remove demo content', 'nexora-commerce-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Deletes only items created by this importer (tagged products, variations, images, posts, pages, menus, sample reviews and testimonials), removes the demo shipping zone and restores settings it changed. Orders and customer accounts are never deleted; past orders keep their own copy of product names and prices.', 'nexora-commerce-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nexora-cleanup>
					<input type="hidden" name="action" value="nexora_demo_run">
					<input type="hidden" name="mode" value="cleanup">
					<?php wp_nonce_field( 'nexora_demo', 'nexora_nonce' ); ?>
					<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I understand demo products and pages will be permanently deleted.', 'nexora-commerce-toolkit' ); ?></label></p>
					<?php submit_button( __( 'Remove demo content', 'nexora-commerce-toolkit' ), 'delete', 'submit', false, $has ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Replacing demo products with your inventory', 'nexora-commerce-toolkit' ); ?></h2>
				<ol>
					<li><?php Nexora_Store::active() ? esc_html_e( 'Add your real products (Store → Add product).', 'nexora-commerce-toolkit' ) : esc_html_e( 'Add your real products (Products → Add New, or Products → Import for CSV).', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Reuse the demo categories or create your own; update category images under Products → Categories.', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Replace homepage images and text in Appearance → Customize → Freshtastic Homepage.', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Click “Remove demo content” above. Your own products, pages and orders stay.', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php esc_html_e( 'Untick “Label sample content” in Settings → Freshtastic Toolkit only after all demo content is gone.', 'nexora-commerce-toolkit' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize the choices array.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private static function choices( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( array( 'content', 'reviews', 'homepage', 'settings' ) as $k ) {
			$out[ $k ] = ! empty( $raw[ $k ] );
		}
		return $out;
	}

	/**
	 * AJAX: plan (step list) or run one step.
	 */
	public static function ajax_step() {
		check_ajax_referer( 'nexora_demo', 'nonce' );
		if ( ! self::can() ) {
			wp_send_json_error( array( 'messages' => array( __( 'Permission denied.', 'nexora-commerce-toolkit' ) ) ), 403 );
		}
		$mode    = isset( $_POST['mode'] ) && 'cleanup' === $_POST['mode'] ? 'cleanup' : 'import';
		$step    = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$choices = self::choices( isset( $_POST['choices'] ) ? wp_unslash( $_POST['choices'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by choices().

		if ( '' === $step ) {
			$steps = 'cleanup' === $mode ? array( 'cleanup' => __( 'Removing demo content', 'nexora-commerce-toolkit' ) ) : self::steps( $choices );
			wp_send_json_success( array( 'steps' => $steps ) );
		}
		$allowed = 'cleanup' === $mode ? array( 'cleanup' ) : array_keys( self::steps( $choices ) );
		if ( ! in_array( $step, $allowed, true ) ) {
			wp_send_json_error( array( 'messages' => array( 'Invalid step.' ) ), 400 );
		}
		$result = self::run_step( $step, $offset );
		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Without JavaScript: run everything in one request.
	 */
	public static function nojs_run() {
		check_admin_referer( 'nexora_demo', 'nexora_nonce' );
		if ( ! self::can() || empty( $_POST['confirm'] ) ) {
			wp_die( esc_html__( 'Permission denied or confirmation missing.', 'nexora-commerce-toolkit' ), 403 );
		}
		$mode = isset( $_POST['mode'] ) && 'cleanup' === $_POST['mode'] ? 'cleanup' : 'import';
		$ok   = self::run_all( $mode, self::choices( isset( $_POST['choices'] ) ? wp_unslash( $_POST['choices'] ) : array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( admin_url( 'tools.php?page=nexora-demo&nexora_result=' . ( $ok ? 'ok' : 'error' ) ) );
		exit;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Manage Freshtastic demo content.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nexora demo import --content --reviews --homepage --settings
	 *     wp nexora demo status
	 *     wp nexora demo cleanup --yes
	 */
	WP_CLI::add_command(
		'nexora demo',
		new class() {
			/**
			 * Import demo content.
			 *
			 * [--content]
			 * : Pages, posts and menus.
			 *
			 * [--reviews]
			 * : Labelled sample reviews and testimonials.
			 *
			 * [--homepage]
			 * : Static homepage and banner images.
			 *
			 * [--settings]
			 * : Philippine store settings, demo shipping zone and offline payment methods.
			 *
			 * @param array $args  Args.
			 * @param array $assoc Assoc args.
			 */
			public function import( $args, $assoc ) {
				$ok = Nexora_Toolkit_Demo_Importer::run_all(
					'import',
					array(
						'content'  => ! empty( $assoc['content'] ),
						'reviews'  => ! empty( $assoc['reviews'] ),
						'homepage' => ! empty( $assoc['homepage'] ),
						'settings' => ! empty( $assoc['settings'] ),
					),
					static function ( $m ) {
						WP_CLI::log( $m );
					}
				);
				$ok ? WP_CLI::success( 'Demo import complete.' ) : WP_CLI::error( 'Demo import stopped.' );
			}

			/**
			 * Remove demo content.
			 *
			 * [--yes]
			 * : Skip confirmation.
			 *
			 * @param array $args  Args.
			 * @param array $assoc Assoc args.
			 */
			public function cleanup( $args, $assoc ) {
				WP_CLI::confirm( 'Remove all Freshtastic demo content?', $assoc );
				Nexora_Toolkit_Demo_Importer::run_all(
					'cleanup',
					array(),
					static function ( $m ) {
						WP_CLI::log( $m );
					}
				);
				WP_CLI::success( 'Cleanup complete.' );
			}

			/**
			 * Show counts of demo content.
			 */
			public function status() {
				foreach ( Nexora_Toolkit_Demo_Importer::counts() as $k => $v ) {
					WP_CLI::log( sprintf( '%-13s %d', $k, $v ) );
				}
			}
		}
	);
}

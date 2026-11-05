<?php
/**
 * Market data for products: selling unit, origin, pack measures (for price per kg/L) and a
 * real price history recorded whenever a product's selling price changes.
 *
 * Price changes shown on the storefront are always "change since the previous recorded price",
 * computed from this history — never invented.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Market module.
 */
class Nexora_Toolkit_Market {

	const HISTORY = '_nexora_price_history';
	const MAX     = 30;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'record' ), 20 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'record' ), 20 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'unit_suffix' ), 20, 2 );
		add_filter( 'nexora_market_data', array( __CLASS__, 'market_data' ), 10, 2 );
		add_filter( 'nexora_unit_prices', array( __CLASS__, 'unit_prices' ), 10, 2 );
		add_filter( 'nexora_market_movers', array( __CLASS__, 'movers' ), 10, 2 );
	}

	/**
	 * Selling units offered in the product editor.
	 *
	 * @return array<string,string>
	 */
	public static function units() {
		return apply_filters(
			'nexora_market_units',
			array(
				''       => __( '— None —', 'nexora-commerce-toolkit' ),
				'kg'     => __( 'per kg', 'nexora-commerce-toolkit' ),
				'pack'   => __( 'per pack', 'nexora-commerce-toolkit' ),
				'sack'   => __( 'per sack', 'nexora-commerce-toolkit' ),
				'tray'   => __( 'per tray', 'nexora-commerce-toolkit' ),
				'bundle' => __( 'per bundle', 'nexora-commerce-toolkit' ),
				'piece'  => __( 'per piece', 'nexora-commerce-toolkit' ),
				'bottle' => __( 'per bottle', 'nexora-commerce-toolkit' ),
				'liter'  => __( 'per liter', 'nexora-commerce-toolkit' ),
				'box'    => __( 'per box', 'nexora-commerce-toolkit' ),
			)
		);
	}

	/**
	 * Short unit label, e.g. "kg".
	 *
	 * @param string $unit Unit key.
	 * @return string
	 */
	public static function unit_label( $unit ) {
		$labels = array(
			'kg'     => _x( 'kg', 'selling unit', 'nexora-commerce-toolkit' ),
			'pack'   => _x( 'pack', 'selling unit', 'nexora-commerce-toolkit' ),
			'sack'   => _x( 'sack', 'selling unit', 'nexora-commerce-toolkit' ),
			'tray'   => _x( 'tray', 'selling unit', 'nexora-commerce-toolkit' ),
			'bundle' => _x( 'bundle', 'selling unit', 'nexora-commerce-toolkit' ),
			'piece'  => _x( 'pc', 'selling unit', 'nexora-commerce-toolkit' ),
			'bottle' => _x( 'bottle', 'selling unit', 'nexora-commerce-toolkit' ),
			'liter'  => _x( 'L', 'selling unit', 'nexora-commerce-toolkit' ),
			'box'    => _x( 'box', 'selling unit', 'nexora-commerce-toolkit' ),
		);
		return isset( $labels[ $unit ] ) ? $labels[ $unit ] : (string) $unit;
	}

	/* --------------------------------------------------------------- admin */

	/**
	 * Product editor fields (General tab).
	 */
	public static function product_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => '_nexora_unit',
				'label'       => __( 'Selling unit', 'nexora-commerce-toolkit' ),
				'options'     => self::units(),
				'desc_tip'    => true,
				'description' => __( 'Shown after the price, e.g. ₱280 / kg. Quantity in the cart counts this unit.', 'nexora-commerce-toolkit' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_nexora_origin',
				'label'       => __( 'Origin', 'nexora-commerce-toolkit' ),
				'placeholder' => __( 'e.g. Benguet', 'nexora-commerce-toolkit' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_nexora_measure_unit',
				'label'       => __( 'Compare price per', 'nexora-commerce-toolkit' ),
				'options'     => array( '' => __( '— Off —', 'nexora-commerce-toolkit' ), 'kg' => 'kg', 'L' => 'L' ),
				'desc_tip'    => true,
				'description' => __( 'Shows price per kg or litre for packs and sacks. Set the pack contents below (simple products) or on each variation.', 'nexora-commerce-toolkit' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_nexora_measure',
				'label'             => __( 'Pack contents (kg or L)', 'nexora-commerce-toolkit' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
				'wrapper_class'     => 'show_if_simple',
			)
		);
		echo '</div>';
	}

	/**
	 * Save product fields (WooCommerce has already verified its nonce and capabilities).
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product_fields( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before this hook.
		$unit = isset( $_POST['_nexora_unit'] ) ? sanitize_key( wp_unslash( $_POST['_nexora_unit'] ) ) : '';
		$product->update_meta_data( '_nexora_unit', array_key_exists( $unit, self::units() ) ? $unit : '' );
		$product->update_meta_data( '_nexora_origin', isset( $_POST['_nexora_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['_nexora_origin'] ) ) : '' );
		$mu = isset( $_POST['_nexora_measure_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['_nexora_measure_unit'] ) ) : '';
		$product->update_meta_data( '_nexora_measure_unit', in_array( $mu, array( 'kg', 'L' ), true ) ? $mu : '' );
		$product->update_meta_data( '_nexora_measure', isset( $_POST['_nexora_measure'] ) ? (string) max( 0, (float) wc_format_decimal( wp_unslash( $_POST['_nexora_measure'] ) ) ) : '' );
		// phpcs:enable
	}

	/**
	 * Variation field.
	 *
	 * @param int     $loop      Index.
	 * @param array   $data      Data.
	 * @param WP_Post $variation Variation post.
	 */
	public static function variation_fields( $loop, $data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'                => "_nexora_measure_{$loop}",
				'name'              => "_nexora_measure[{$loop}]",
				'value'             => get_post_meta( $variation->ID, '_nexora_measure', true ),
				'label'             => __( 'Pack contents (kg or L)', 'nexora-commerce-toolkit' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);
	}

	/**
	 * Save variation field (WooCommerce verifies the variations nonce).
	 *
	 * @param int $variation_id Variation.
	 * @param int $i            Index.
	 */
	public static function save_variation_fields( $variation_id, $i ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce.
		$value = isset( $_POST['_nexora_measure'][ $i ] ) ? (float) wc_format_decimal( wp_unslash( $_POST['_nexora_measure'][ $i ] ) ) : 0;
		update_post_meta( $variation_id, '_nexora_measure', $value > 0 ? (string) $value : '' );
	}

	/* --------------------------------------------------------- price history */

	/**
	 * Current reference price used for price history: the selling price; for products sold in
	 * pack sizes, the lowest price per kg/L (so different pack sizes compare like for like);
	 * otherwise the lowest variation price.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function reference_price( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$per = self::lowest_unit_price( $product );
			return $per ? round( (float) $per['per'], 2 ) : (float) $product->get_variation_price( 'min' );
		}
		return (float) $product->get_price();
	}

	/**
	 * Append the current price to the history when it differs from the last entry.
	 *
	 * @param int $product_id Product.
	 */
	public static function record( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return;
		}
		$price = self::reference_price( $product );
		if ( $price <= 0 ) {
			return;
		}
		$history = self::history( $product_id );
		$last    = end( $history );
		if ( $last && abs( (float) $last['p'] - $price ) < 0.005 ) {
			return;
		}
		$history[] = array( 't' => time(), 'p' => $price );
		update_post_meta( $product_id, self::HISTORY, array_slice( $history, -self::MAX ) );
		update_option( 'nexora_market_v', time(), false );
	}

	/**
	 * Price history entries: array of ['t' => timestamp, 'p' => price].
	 *
	 * @param int $product_id Product.
	 * @return array
	 */
	public static function history( $product_id ) {
		$h = get_post_meta( $product_id, self::HISTORY, true );
		return is_array( $h ) ? array_values( array_filter( $h, static function ( $e ) {
			return isset( $e['t'], $e['p'] );
		} ) ) : array();
	}

	/**
	 * Market data used by the theme.
	 *
	 * @param array      $data    Default.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function market_data( $data, $product ) {
		$id      = $product->get_id();
		$history = self::history( $id );
		$change  = null;
		$prev    = null;
		$since   = null;
		$count   = count( $history );
		if ( $count >= 2 ) {
			$prev = (float) $history[ $count - 2 ]['p'];
			$cur  = (float) $history[ $count - 1 ]['p'];
			if ( $prev > 0 ) {
				$change = round( ( $cur - $prev ) / $prev * 100, 1 );
				$since  = (int) $history[ $count - 1 ]['t'];
			}
		}
		$unit = (string) $product->get_meta( '_nexora_unit' );
		return array(
			'unit'         => $unit,
			'unit_label'   => $unit ? self::unit_label( $unit ) : '',
			'origin'       => (string) $product->get_meta( '_nexora_origin' ),
			'change'       => $change,
			'previous'     => $prev,
			'since'        => $since,
			'history'      => $history,
			'measure_unit' => (string) $product->get_meta( '_nexora_measure_unit' ),
			'from_per'     => self::lowest_unit_price( $product ),
		);
	}

	/**
	 * Price per kg/L rows for a product: [label, price, per].
	 *
	 * @param array      $rows    Default.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function unit_prices( $rows, $product ) {
		$mu = (string) $product->get_meta( '_nexora_measure_unit' );
		if ( ! $mu ) {
			return $rows;
		}
		$rows  = array();
		$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		foreach ( $items as $item ) {
			$measure = (float) $item->get_meta( '_nexora_measure' );
			$price   = (float) wc_get_price_to_display( $item );
			if ( $measure > 0 && $price > 0 && $item->is_purchasable() ) {
				$rows[] = array(
					'label'    => $item->is_type( 'variation' ) ? wc_get_formatted_variation( $item, true, false ) : $product->get_name(),
					'price'    => $price,
					'per'      => $price / $measure,
					'measure'  => $mu,
					'in_stock' => $item->is_in_stock(),
				);
			}
		}
		usort( $rows, static function ( $a, $b ) {
			return $a['per'] <=> $b['per'];
		} );
		return $rows;
	}

	/**
	 * Lowest price per kg/L, for "from ₱x/kg" on cards. Null when not applicable.
	 *
	 * @param WC_Product $product Product.
	 * @return array|null ['per' => float, 'measure' => 'kg'|'L']
	 */
	public static function lowest_unit_price( $product ) {
		if ( ! $product->get_meta( '_nexora_measure_unit' ) || ! $product->is_type( 'variable' ) ) {
			return null;
		}
		$rows = self::unit_prices( array(), $product );
		return $rows ? array( 'per' => $rows[0]['per'], 'measure' => $rows[0]['measure'] ) : null;
	}

	/**
	 * Append the selling unit to price HTML ("₱280 / kg").
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function unit_suffix( $html, $product ) {
		$source = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$unit   = $source ? (string) $source->get_meta( '_nexora_unit' ) : '';
		if ( '' === $html || ! $unit || false !== strpos( $html, 'nexora-unit' ) ) {
			return $html;
		}
		/* translators: %s: selling unit, e.g. kg */
		return $html . ' <span class="nexora-unit">' . esc_html( sprintf( __( '/ %s', 'nexora-commerce-toolkit' ), self::unit_label( $unit ) ) ) . '</span>';
	}

	/**
	 * Products with the largest recent price changes (cached until a price changes).
	 *
	 * @param array $default Default.
	 * @param int   $limit   Limit.
	 * @return array<int,array{id:int,change:float}>
	 */
	public static function movers( $default, $limit = 12 ) {
		$key  = 'nexora_movers_' . md5( $limit . get_option( 'nexora_market_v', '1' ) . get_option( 'nexora_products_cache_v', '1' ) );
		$list = get_transient( $key );
		if ( false === $list ) {
			$ids  = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'numberposts' => 200,
					'fields'      => 'ids',
					'meta_key'    => self::HISTORY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);
			$list = array();
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! $product->is_visible() ) {
					continue;
				}
				$data = self::market_data( array(), $product );
				if ( null !== $data['change'] && 0.0 !== (float) $data['change'] ) {
					$list[] = array( 'id' => (int) $id, 'change' => (float) $data['change'], 'since' => (int) $data['since'] );
				}
			}
			usort( $list, static function ( $a, $b ) {
				return abs( $b['change'] ) <=> abs( $a['change'] );
			} );
			$list = array_slice( $list, 0, max( 1, (int) $limit ) );
			set_transient( $key, $list, 6 * HOUR_IN_SECONDS );
		}
		return $list;
	}
}

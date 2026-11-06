<?php
/**
 * Demo catalogue for the built-in store: the same sample products, categories and images as the
 * WooCommerce demo importer, created without WooCommerce. Re-runnable; existing SKUs are skipped.
 * Items are tagged so they can be removed; orders and customers are never touched.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demo catalogue.
 */
class Nexora_Store_Importer {

	const TAG = '_nexora_demo';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_post_nexora_store_seed', array( __CLASS__, 'handle_seed' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'nexora store seed', array( __CLASS__, 'cli_seed' ) );
			WP_CLI::add_command( 'nexora store clean', array( __CLASS__, 'cli_clean' ) );
		}
	}

	/**
	 * Admin button.
	 */
	public static function handle_seed() {
		if ( ! current_user_can( Nexora_Store_Admin::CAP_STORE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_store_seed' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::seed();
		wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/**
	 * CLI: import.
	 */
	public static function cli_seed() {
		$made = self::seed();
		WP_CLI::success( sprintf( '%d products created.', $made ) );
	}

	/**
	 * CLI: remove.
	 */
	public static function cli_clean() {
		WP_CLI::success( sprintf( '%d items removed.', self::clean() ) );
	}

	/**
	 * Attach a bundled demo image, reusing it if already imported.
	 *
	 * @param string $file File in demo/images.
	 * @param string $alt  Alt text.
	 * @return int
	 */
	private static function image( $file, $alt ) {
		$path = NEXORA_TOOLKIT_DIR . 'demo/images/' . $file;
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_nexora_demo_key', 'meta_value' => 'image:' . $file ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return (int) $found[0];
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$upload = wp_upload_bits( 'nexora-demo-' . $file, null, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $alt, 'post_status' => 'inherit' ), $upload['file'] );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $id, self::TAG, 1 );
		update_post_meta( $id, '_nexora_demo_key', 'image:' . $file );
		return (int) $id;
	}

	/**
	 * Create the demo catalogue.
	 *
	 * @return int Products created.
	 */
	public static function seed() {
		Nexora_Store_Catalog::register();
		$raw  = file_get_contents( NEXORA_TOOLKIT_DIR . 'demo/data/products.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			return 0;
		}
		foreach ( $data['categories'] as $order => $cat ) {
			$term = get_term_by( 'slug', $cat['slug'], 'product_cat' );
			if ( ! $term ) {
				$parent = ! empty( $cat['parent'] ) ? get_term_by( 'slug', $cat['parent'], 'product_cat' ) : null;
				$made   = wp_insert_term(
					$cat['name'],
					'product_cat',
					array(
						'slug'        => $cat['slug'],
						'description' => isset( $cat['description'] ) ? $cat['description'] : '',
						'parent'      => $parent ? $parent->term_id : 0,
					)
				);
				if ( is_wp_error( $made ) ) {
					continue;
				}
				update_term_meta( $made['term_id'], self::TAG, 1 );
				update_term_meta( $made['term_id'], 'order', $order );
				update_term_meta( $made['term_id'], 'thumbnail_id', self::image( 'category-' . $cat['slug'] . '.jpg', $cat['name'] ) );
			}
		}
		$created = 0;
		foreach ( $data['products'] as $index => $p ) {
			global $wpdb;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1", $p['sku'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $exists && self::create_product( $p, $index ) ) {
				++$created;
			}
		}
		Nexora_Store_Catalog::flush();
		return $created;
	}

	/**
	 * Create one product in WooCommerce's format.
	 *
	 * @param array $p     Data.
	 * @param int   $index Index, for staggered dates.
	 * @return int
	 */
	private static function create_product( $p, $index ) {
		$days = 0 === $index % 5 ? 2 + $index : 35 + ( $index * 7 ) % 120;
		$date = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$id   = wp_insert_post(
			array(
				'post_type'     => 'product',
				'post_status'   => 'publish',
				'post_title'    => $p['name'],
				'post_name'     => $p['slug'],
				'post_content'  => wp_kses_post( $p['description'] ),
				'post_excerpt'  => wp_kses_post( $p['short_description'] ),
				'post_date_gmt' => $date,
				'post_date'     => get_date_from_gmt( $date ),
				'comment_status' => 'open',
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		$is_variable = 'variable' === $p['type'];
		wp_set_object_terms( $id, $is_variable ? 'variable' : 'simple', 'product_type' );
		wp_set_object_terms( $id, array( $p['category'] ), 'product_cat' );
		$tags = array();
		foreach ( (array) ( isset( $p['tags'] ) ? $p['tags'] : array() ) as $tag ) {
			$tags[] = ucwords( str_replace( '-', ' ', $tag ) );
		}
		wp_set_object_terms( $id, $tags, 'product_tag' );
		$visibility = ! empty( $p['featured'] ) ? array( 'featured' ) : array();
		wp_set_object_terms( $id, $visibility, 'product_visibility' );

		$meta = array(
			self::TAG              => 1,
			'_nexora_demo_key'     => 'product:' . $p['sku'],
			'_sku'                 => $p['sku'],
			'total_sales'          => isset( $p['total_sales'] ) ? (int) $p['total_sales'] : 0,
			'_nexora_unit'         => isset( $p['unit'] ) ? $p['unit'] : '',
			'_nexora_origin'       => isset( $p['origin'] ) ? $p['origin'] : '',
			'_nexora_measure_unit' => isset( $p['measure_unit'] ) ? $p['measure_unit'] : '',
			'_tax_status'          => 'taxable',
			'_virtual'             => 'no',
			'_downloadable'        => 'no',
			'_backorders'          => 'no',
			'_sold_individually'   => 'no',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$main   = self::image( $p['slug'] . '.jpg', $p['name'] );
		$detail = self::image( $p['slug'] . '-detail.jpg', $p['name'] );
		if ( $main ) {
			set_post_thumbnail( $id, $main );
		}
		if ( $detail ) {
			update_post_meta( $id, '_product_image_gallery', (string) $detail );
		}

		$attributes = array();
		$position   = 0;
		foreach ( (array) ( isset( $p['attributes'] ) ? $p['attributes'] : array() ) as $a ) {
			$attributes[ sanitize_title( $a['name'] ) ] = array(
				'name'         => $a['name'],
				'value'        => implode( ' | ', $a['options'] ),
				'position'     => $position++,
				'is_visible'   => 1,
				'is_variation' => $is_variable && ! empty( $a['variation'] ) ? 1 : 0,
				'is_taxonomy'  => 0,
			);
		}
		update_post_meta( $id, '_product_attributes', $attributes );

		$sale_or_regular = static function ( $row ) {
			return ! empty( $row['sale_price'] ) ? $row['sale_price'] : $row['regular_price'];
		};
		if ( $is_variable ) {
			$suffix = 1;
			$any    = false;
			foreach ( $p['variations'] as $order => $v ) {
				$vid = wp_insert_post(
					array(
						'post_type'   => 'product_variation',
						'post_parent' => $id,
						'post_status' => 'publish',
						'post_title'  => $p['name'] . ' - ' . implode( ', ', $v['attributes'] ),
						'menu_order'  => $order,
					)
				);
				if ( ! $vid || is_wp_error( $vid ) ) {
					continue;
				}
				foreach ( $v['attributes'] as $key => $value ) {
					update_post_meta( $vid, 'attribute_' . sanitize_title( $key ), $value );
				}
				$stock = (int) $v['stock_qty'];
				$any   = $any || $stock > 0;
				update_post_meta( $vid, '_sku', $p['sku'] . '-' . str_pad( (string) $suffix++, 2, '0', STR_PAD_LEFT ) );
				update_post_meta( $vid, '_regular_price', $v['regular_price'] );
				update_post_meta( $vid, '_sale_price', isset( $v['sale_price'] ) ? $v['sale_price'] : '' );
				update_post_meta( $vid, '_price', $sale_or_regular( $v ) );
				add_post_meta( $id, '_price', $sale_or_regular( $v ) );
				update_post_meta( $vid, '_manage_stock', 'yes' );
				update_post_meta( $vid, '_stock', $stock );
				update_post_meta( $vid, '_stock_status', $stock > 0 ? 'instock' : 'outofstock' );
				update_post_meta( $vid, '_nexora_measure', isset( $v['measure'] ) ? (string) $v['measure'] : '' );
				update_post_meta( $vid, self::TAG, 1 );
			}
			update_post_meta( $id, '_manage_stock', 'no' );
			update_post_meta( $id, '_stock_status', $any ? 'instock' : 'outofstock' );
		} else {
			$stock = (int) $p['stock_qty'];
			update_post_meta( $id, '_regular_price', $p['regular_price'] );
			update_post_meta( $id, '_sale_price', isset( $p['sale_price'] ) ? $p['sale_price'] : '' );
			update_post_meta( $id, '_price', $sale_or_regular( $p ) );
			update_post_meta( $id, '_manage_stock', 'yes' );
			update_post_meta( $id, '_stock', $stock );
			update_post_meta( $id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock' );
			if ( ! empty( $p['measure'] ) ) {
				update_post_meta( $id, '_nexora_measure', (string) $p['measure'] );
			}
			if ( $stock <= 0 ) {
				wp_set_object_terms( $id, 'outofstock', 'product_visibility', true );
			}
		}

		// Price history, so the ticker and board have movement to show.
		$history = array();
		if ( ! empty( $p['history']['previous'] ) ) {
			$history[] = array(
				't' => time() - (int) $p['history']['days_ago'] * DAY_IN_SECONDS,
				'p' => (float) $p['history']['previous'],
			);
		}
		update_post_meta( $id, Nexora_Toolkit_Market::HISTORY, $history );
		Nexora_Toolkit_Market::record( $id );

		foreach ( (array) ( isset( $p['reviews'] ) ? $p['reviews'] : array() ) as $review ) {
			$cid = wp_insert_comment(
				array(
					'comment_post_ID'  => $id,
					'comment_author'   => $review['author'],
					'comment_content'  => $review['content'],
					'comment_type'     => 'review',
					'comment_approved' => 1,
				)
			);
			if ( $cid ) {
				add_comment_meta( $cid, 'rating', (int) $review['rating'], true );
				add_comment_meta( $cid, self::TAG, 1, true );
			}
		}
		Nexora_Store_Catalog::update_rating( $id );
		return (int) $id;
	}

	/**
	 * Remove tagged demo items. Orders and customers are not touched.
	 *
	 * @return int
	 */
	public static function clean() {
		$removed = 0;
		foreach ( array( 'product_variation', 'product', 'attachment' ) as $type ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::TAG, 'meta_value' => 1 ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				if ( 'attachment' === $type ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true ) ) {
					++$removed;
				}
			}
		}
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => self::TAG, 'meta_value' => 1 ) ) as $term ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_term( $term->term_id, 'product_cat' );
			++$removed;
		}
		Nexora_Store_Catalog::flush();
		return $removed;
	}
}

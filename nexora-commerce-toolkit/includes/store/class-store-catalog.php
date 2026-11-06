<?php
/**
 * Catalogue of the built-in store: post types and taxonomies in WooCommerce's format, shop queries,
 * storefront templates, reviews and product schema.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue.
 */
class Nexora_Store_Catalog {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'after_setup_theme', array( __CLASS__, 'image_sizes' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'shop_query' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'price_clauses' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 50 );
		add_action( 'wp_head', array( __CLASS__, 'schema' ) );
		add_action( 'comment_post', array( __CLASS__, 'save_review' ), 10, 3 );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'review_status_changed' ) );
		add_filter( 'preprocess_comment', array( __CLASS__, 'mark_review' ) );
		add_filter( 'comments_open', array( __CLASS__, 'reviews_open' ), 10, 2 );
		add_filter( 'comments_clauses', array( __CLASS__, 'hide_woocommerce_notes' ) );
	}

	/**
	 * Register types.
	 */
	public static function register() {
		$caps = array(
			'manage_terms' => 'manage_product_terms',
			'edit_terms'   => 'edit_product_terms',
			'delete_terms' => 'delete_product_terms',
			'assign_terms' => 'assign_product_terms',
		);
		register_taxonomy(
			'product_type',
			array( 'product' ),
			array( 'public' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false, 'hierarchical' => false )
		);
		register_taxonomy(
			'product_visibility',
			array( 'product', 'product_variation' ),
			array( 'public' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false, 'hierarchical' => false )
		);
		register_taxonomy(
			'product_cat',
			array( 'product' ),
			array(
				'hierarchical' => true,
				'labels'       => array(
					'name'          => __( 'Categories', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Category', 'nexora-commerce-toolkit' ),
					'add_new_item'  => __( 'Add category', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Edit category', 'nexora-commerce-toolkit' ),
					'search_items'  => __( 'Search categories', 'nexora-commerce-toolkit' ),
				),
				'show_ui'      => true,
				'show_in_rest' => true,
				'query_var'    => true,
				'capabilities' => $caps,
				'rewrite'      => array( 'slug' => 'product-category', 'with_front' => false, 'hierarchical' => true ),
			)
		);
		register_taxonomy(
			'product_tag',
			array( 'product' ),
			array(
				'hierarchical' => false,
				'labels'       => array(
					'name'          => __( 'Tags', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Tag', 'nexora-commerce-toolkit' ),
				),
				'show_ui'      => true,
				'show_in_rest' => true,
				'query_var'    => true,
				'capabilities' => $caps,
				'rewrite'      => array( 'slug' => 'product-tag', 'with_front' => false ),
			)
		);
		register_post_type(
			'product',
			array(
				'labels'          => array(
					'name'          => __( 'Products', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Product', 'nexora-commerce-toolkit' ),
					'add_new'       => __( 'Add product', 'nexora-commerce-toolkit' ),
					'add_new_item'  => __( 'Add product', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Edit product', 'nexora-commerce-toolkit' ),
					'search_items'  => __( 'Search products', 'nexora-commerce-toolkit' ),
					'not_found'     => __( 'No products found', 'nexora-commerce-toolkit' ),
				),
				'public'          => true,
				'has_archive'     => 'shop',
				'show_in_menu'    => 'nexora-store',
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments' ),
				'rewrite'         => array( 'slug' => 'product', 'with_front' => false ),
				'query_var'       => true,
				'menu_icon'       => 'dashicons-carrot',
			)
		);
		register_post_type(
			'product_variation',
			array(
				'public'          => false,
				'show_ui'         => false,
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'rewrite'         => false,
				'supports'        => array( 'title', 'thumbnail' ),
			)
		);
		// Records WooCommerce leaves behind while it is switched off; kept as they are, for switching back.
		foreach ( array( 'shop_order', 'shop_order_placehold', 'shop_order_refund' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				register_post_type( $type, array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'capability_type' => 'shop_order', 'map_meta_cap' => true ) );
			}
		}
		foreach ( array( 'simple', 'variable' ) as $term ) {
			if ( ! term_exists( $term, 'product_type' ) ) {
				wp_insert_term( $term, 'product_type' );
			}
		}
	}

	/**
	 * Keep WooCommerce's stored order notes out of the Comments screen while it is switched off.
	 *
	 * @param array $clauses Clauses.
	 * @return array
	 */
	public static function hide_woocommerce_notes( $clauses ) {
		global $wpdb;
		$clauses['where'] .= ( $clauses['where'] ? ' AND ' : '' ) . "{$wpdb->comments}.comment_type NOT IN ('order_note','webhook_delivery','action_log')";
		return $clauses;
	}

	/**
	 * Same size names as WooCommerce, so the theme's templates and existing thumbnails keep working.
	 */
	public static function image_sizes() {
		add_theme_support( 'post-thumbnails' );
		add_image_size( 'woocommerce_thumbnail', 600, 600, true );
		add_image_size( 'woocommerce_single', 900, 0, false );
		add_image_size( 'woocommerce_gallery_thumbnail', 100, 100, true );
	}

	/* ---------------------------------------------------------------- query */

	/**
	 * IDs of products on sale right now (cached until products change).
	 *
	 * @return int[]
	 */
	public static function on_sale_ids() {
		$key = 'nexora_store_sale_' . get_option( 'nexora_products_cache_v', '1' );
		$ids = get_transient( $key );
		if ( false === $ids ) {
			global $wpdb;
			$rows = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sale_price' WHERE m.meta_value <> '' AND p.post_type IN ('product','product_variation') AND p.post_status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids  = array();
			foreach ( $rows as $id ) {
				$product = Nexora_Store_Product::get( $id );
				if ( $product && $product->is_on_sale() ) {
					$ids[] = $product->source()->get_id();
				}
			}
			$ids = array_values( array_unique( $ids ) );
			set_transient( $key, $ids, HOUR_IN_SECONDS );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Clear cached product lists after a catalogue change.
	 */
	public static function flush() {
		update_option( 'nexora_products_cache_v', (string) time(), false );
	}

	/**
	 * Filters and sorting for the shop, category and product search pages.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function shop_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$is_search = $query->is_search() && 'product' === $query->get( 'post_type' );
		if ( ! $is_search && ! $query->is_post_type_archive( 'product' ) && ! $query->is_tax( array( 'product_cat', 'product_tag' ) ) ) {
			return;
		}
		$query->set( 'posts_per_page', 12 );
		$tax   = (array) $query->get( 'tax_query' );
		$tax[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'slug',
			'terms'    => array( 'exclude-from-catalog' ),
			'operator' => 'NOT IN',
		);
		$query->set( 'tax_query', $tax );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only filters.
		if ( ! empty( $_GET['on_sale'] ) ) {
			$query->set( 'post__in', array_merge( array( 0 ), self::on_sale_ids() ) );
		}
		$meta = array();
		if ( ! empty( $_GET['in_stock'] ) ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_stock_status', 'value' => 'instock' ),
				array( 'key' => '_stock_status', 'compare' => 'NOT EXISTS' ),
			);
		}
		if ( $meta ) {
			$query->set( 'meta_query', $meta );
		}
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		switch ( $orderby ) {
			case 'popularity':
				$query->set( 'meta_key', 'total_sales' );
				$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' ) );
				break;
			case 'rating':
				$query->set( 'meta_key', '_wc_average_rating' );
				$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' ) );
				break;
			case 'price':
			case 'price-desc':
				$query->set( 'nexora_price_sort', 'price' === $orderby ? 'ASC' : 'DESC' );
				break;
			case 'date':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			default:
				if ( ! $query->is_search() ) {
					$query->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
				}
		}
		// phpcs:enable
	}

	/**
	 * Sort by price. Products with options store several _price rows, so the lowest one is used.
	 *
	 * @param array    $clauses Clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public static function price_clauses( $clauses, $query ) {
		$dir = $query->get( 'nexora_price_sort' );
		if ( $dir ) {
			global $wpdb;
			$clauses['join']   .= " LEFT JOIN (SELECT post_id, MIN(meta_value + 0) AS nx_price FROM {$wpdb->postmeta} WHERE meta_key = '_price' GROUP BY post_id) nx_p ON nx_p.post_id = {$wpdb->posts}.ID";
			$clauses['orderby'] = 'nx_p.nx_price ' . ( 'DESC' === $dir ? 'DESC' : 'ASC' ) . ", {$wpdb->posts}.ID DESC";
		}
		return $clauses;
	}

	/**
	 * Product IDs for a homepage section.
	 *
	 * @param string $type  featured|latest|best_selling|top_rated|on_sale|manual|ids.
	 * @param int    $limit Limit.
	 * @param array  $extra include, exclude, category.
	 * @return int[]
	 */
	public static function section_ids( $type, $limit = 8, $extra = array() ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 24, (int) $limit ) ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);
		switch ( $type ) {
			case 'featured':
				$args['tax_query'][] = array( 'taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => array( 'featured' ) );
				break;
			case 'best_selling':
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' );
				break;
			case 'top_rated':
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' );
				break;
			case 'on_sale':
				$args['post__in'] = array_merge( array( 0 ), self::on_sale_ids() );
				break;
			case 'manual':
			case 'ids':
				$ids = isset( $extra['include'] ) ? array_filter( array_map( 'absint', (array) $extra['include'] ) ) : array();
				if ( ! $ids ) {
					return array();
				}
				$args['post__in'] = $ids;
				$args['orderby']  = 'post__in';
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}
		if ( ! empty( $extra['exclude'] ) ) {
			$args['post__not_in'] = array_map( 'absint', (array) $extra['exclude'] );
		}
		if ( ! empty( $extra['category'] ) ) {
			$args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => (array) $extra['category'] );
		}
		$key = 'nexora_store_ids_' . md5( wp_json_encode( $args ) . get_option( 'nexora_products_cache_v', '1' ) );
		$ids = get_transient( $key );
		if ( false === $ids ) {
			$ids = get_posts( $args );
			set_transient( $key, $ids, HOUR_IN_SECONDS );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Related products: same category, excluding given IDs.
	 *
	 * @param int   $product_id Product.
	 * @param int   $limit      Limit.
	 * @param array $exclude    Excluded IDs.
	 * @return int[]
	 */
	public static function related_ids( $product_id, $limit = 4, $exclude = array() ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'rand',
				'no_found_rows'  => true,
				'post__not_in'   => array_merge( array( (int) $product_id ), array_map( 'absint', (array) $exclude ) ),
				'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => $terms ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			)
		);
	}

	/* ------------------------------------------------------------ templates */

	/**
	 * Path of an overridable template: yourtheme/nexora-store/{name}.php, else the toolkit's.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function locate( $name ) {
		$theme = locate_template( 'nexora-store/' . $name . '.php' );
		return $theme ? $theme : NEXORA_TOOLKIT_DIR . 'templates/store/' . $name . '.php';
	}

	/**
	 * Use the store's templates for the shop and product pages.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( is_singular( 'product' ) ) {
			return self::locate( 'single' );
		}
		if ( is_post_type_archive( 'product' ) || is_tax( array( 'product_cat', 'product_tag' ) ) ) {
			return self::locate( 'archive' );
		}
		return $template;
	}

	/**
	 * Print product cards.
	 *
	 * @param int[] $ids     Product IDs.
	 * @param int   $columns Columns.
	 * @param array $options heading: h2|h3|h4.
	 */
	public static function grid( $ids, $columns = 4, $options = array() ) {
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		$heading = isset( $options['heading'] ) && in_array( $options['heading'], array( 'h2', 'h3', 'h4' ), true ) ? $options['heading'] : 'h3';
		printf( '<ul class="products columns-%d">', (int) $columns );
		foreach ( $ids as $id ) {
			$product = Nexora_Store_Product::get( $id );
			if ( $product && $product->is_visible() ) {
				include self::locate( 'card' );
			}
		}
		echo '</ul>';
	}

	/**
	 * Primary category terms of a product.
	 *
	 * @param int $id Product ID.
	 * @return WP_Term[]
	 */
	public static function categories( $id ) {
		$terms = get_the_terms( $id, 'product_cat' );
		return $terms && ! is_wp_error( $terms ) ? $terms : array();
	}

	/* -------------------------------------------------------------- reviews */

	/**
	 * Product comments are open only when reviews are enabled.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post.
	 * @return bool
	 */
	public static function reviews_open( $open, $post_id ) {
		return 'product' === get_post_type( $post_id ) ? (bool) Nexora_Store::get( 'reviews' ) && $open : $open;
	}

	/**
	 * Comments on products are reviews.
	 *
	 * @param array $data Comment data.
	 * @return array
	 */
	public static function mark_review( $data ) {
		if ( ! empty( $data['comment_post_ID'] ) && 'product' === get_post_type( (int) $data['comment_post_ID'] ) && empty( $data['comment_parent'] ) ) {
			$data['comment_type'] = 'review';
		}
		return $data;
	}

	/**
	 * Save the star rating.
	 *
	 * @param int        $comment_id Comment.
	 * @param int|string $approved   Status.
	 * @param array      $data       Comment data.
	 */
	public static function save_review( $comment_id, $approved, $data ) {
		if ( 'product' !== get_post_type( (int) $data['comment_post_ID'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress's comment form handling.
		$rating = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		if ( $rating >= 1 && $rating <= 5 ) {
			add_comment_meta( $comment_id, 'rating', $rating, true );
		}
		self::update_rating( (int) $data['comment_post_ID'] );
	}

	/**
	 * Recalculate when a review is approved, unapproved or deleted.
	 *
	 * @param int $comment_id Comment.
	 */
	public static function review_status_changed( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && 'product' === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::update_rating( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Store average and count in WooCommerce's meta keys.
	 *
	 * @param int $product_id Product.
	 */
	public static function update_rating( $product_id ) {
		$reviews = get_comments( array( 'post_id' => $product_id, 'status' => 'approve', 'type' => 'review' ) );
		$sum     = 0;
		$count   = 0;
		foreach ( $reviews as $review ) {
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			if ( $rating ) {
				$sum += $rating;
				++$count;
			}
		}
		update_post_meta( $product_id, '_wc_average_rating', $count ? round( $sum / $count, 2 ) : 0 );
		update_post_meta( $product_id, '_wc_review_count', count( $reviews ) );
	}

	/**
	 * Stars as text and markup.
	 *
	 * @param float $rating Rating.
	 * @return string
	 */
	public static function stars( $rating ) {
		/* translators: %s: rating */
		$label = sprintf( __( 'Rated %s out of 5', 'nexora-commerce-toolkit' ), number_format_i18n( (float) $rating, 1 ) );
		$full  = (int) round( (float) $rating );
		return '<span class="nx-store-stars" role="img" aria-label="' . esc_attr( $label ) . '">' . str_repeat( '★', $full ) . '<span class="nx-store-stars__off">' . str_repeat( '★', max( 0, 5 - $full ) ) . '</span></span>';
	}

	/* --------------------------------------------------------------- schema */

	/**
	 * Product structured data, skipped when an SEO plugin handles it.
	 */
	public static function schema() {
		if ( ! is_singular( 'product' ) || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		$product = Nexora_Store_Product::get( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}
		$price = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min' ) : (float) $product->get_price();
		$data  = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_short_description() ),
			'sku'         => $product->get_sku(),
			'url'         => $product->get_permalink(),
			'offers'      => array(
				'@type'         => 'Offer',
				'priceCurrency' => Nexora_Store::get( 'currency' ),
				'price'         => number_format( $price, 2, '.', '' ),
				'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				'url'           => $product->get_permalink(),
			),
		);
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
		if ( $image ) {
			$data['image'] = $image;
		}
		if ( $product->get_rating_count() && ! get_post_meta( $product->get_id(), '_nexora_demo', true ) ) {
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $product->get_average_rating(),
				'reviewCount' => $product->get_review_count(),
			);
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}

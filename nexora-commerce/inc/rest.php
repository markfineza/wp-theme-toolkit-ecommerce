<?php
/**
 * Public, read-only REST endpoints used by main.js:
 *  - GET /nexora/v1/search?q=term   Live search suggestions.
 *  - GET /nexora/v1/cards?ids=1,2   Product card HTML (recently viewed).
 *  - GET /nexora/v1/cards?related=1  Cards related to a product (personalized discovery).
 *
 * Neither endpoint reads or writes user data, so responses can be cached by proxies.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register routes.
 */
function nexora_register_rest_routes() {
	register_rest_route(
		'nexora/v1',
		'/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'nexora_rest_search',
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $value ) {
						return is_string( $value ) && mb_strlen( trim( $value ) ) >= 2 && mb_strlen( $value ) <= 100;
					},
				),
			),
		)
	);

	if ( nexora_has_store() ) {
		register_rest_route(
			'nexora/v1',
			'/cards',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'nexora_rest_cards',
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							return implode( ',', array_slice( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ), 0, 8 ) );
						},
					),
					'related'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'fallback' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}
}
add_action( 'rest_api_init', 'nexora_register_rest_routes' );

/**
 * Live search handler.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function nexora_rest_search( $request ) {
	$term    = trim( (string) $request['q'] );
	$results = array();

	if ( nexora_is_woocommerce_active() ) {
		$ids = WC_Data_Store::load( 'product' )->search_products( $term, '', false, false, 12 );
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$image     = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
			$results[] = array(
				'id'    => $product->get_id(),
				'title' => wp_strip_all_tags( $product->get_name() ),
				'url'   => esc_url_raw( $product->get_permalink() ),
				'price' => wp_kses_post( $product->get_price_html() ),
				'image' => $image ? esc_url_raw( $image ) : '',
			);
			if ( count( $results ) >= 6 ) {
				break;
			}
		}
		$all_url = add_query_arg( array( 's' => rawurlencode( $term ), 'post_type' => 'product' ), home_url( '/' ) );
	} elseif ( nexora_is_native_store() ) {
		$query = new WP_Query(
			array(
				's'              => $term,
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'no_found_rows'  => true,
			)
		);
		foreach ( $query->posts as $post ) {
			$product = Nexora_Store_Product::get( $post->ID );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$image     = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
			$results[] = array(
				'id'    => $product->get_id(),
				'title' => wp_strip_all_tags( $product->get_name() ),
				'url'   => esc_url_raw( $product->get_permalink() ),
				'price' => wp_kses_post( $product->get_price_html() ),
				'image' => $image ? esc_url_raw( $image ) : '',
			);
		}
		$all_url = add_query_arg( array( 's' => rawurlencode( $term ), 'post_type' => 'product' ), home_url( '/' ) );
	} else {
		$query = new WP_Query(
			array(
				's'              => $term,
				'posts_per_page' => 6,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'    => $post->ID,
				'title' => wp_strip_all_tags( get_the_title( $post ) ),
				'url'   => esc_url_raw( get_permalink( $post ) ),
				'price' => '',
				'image' => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
			);
		}
		$all_url = add_query_arg( 's', rawurlencode( $term ), home_url( '/' ) );
	}

	$response = rest_ensure_response(
		array(
			'results' => $results,
			'allUrl'  => esc_url_raw( $all_url ),
		)
	);
	$response->header( 'Cache-Control', 'public, max-age=300' );
	return $response;
}

/**
 * Product cards handler. When none of the requested products are available and fallback=1,
 * returns best sellers so the section never renders empty.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function nexora_rest_cards( $request ) {
	$ids = array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) );
	if ( $request['related'] ) {
		// Related products (same categories/tags), excluding anything already in the visitor's history.
		$ids = nexora_is_native_store()
			? Nexora_Store_Catalog::related_ids( (int) $request['related'], 4, $ids )
			: wc_get_related_products( (int) $request['related'], 4, array_merge( array( (int) $request['related'] ), $ids ) );
	}
	$ids = array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				$product = nexora_get_product( $id );
				return $product && $product->is_visible();
			}
		)
	);
	$is_fallback = false;
	if ( ! $ids && $request['fallback'] ) {
		$ids         = nexora_get_product_ids( 'best_selling', 4 );
		$is_fallback = true;
	}

	ob_start();
	nexora_product_grid( $ids, array( 'columns' => 4, 'name' => 'recently_viewed' ) );
	$html = ob_get_clean();

	$response = rest_ensure_response(
		array(
			'html'     => $html,
			'count'    => count( $ids ),
			'fallback' => $is_fallback,
		)
	);
	$response->header( 'Cache-Control', 'public, max-age=300' );
	return $response;
}

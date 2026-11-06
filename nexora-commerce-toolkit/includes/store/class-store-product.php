<?php
/**
 * Product model of the built-in store. Reads WooCommerce's product format and offers the same method
 * names the theme and market module already call on a product, so they work with either engine.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * A product or a product variation.
 */
class Nexora_Store_Product {

	/**
	 * Post.
	 *
	 * @var WP_Post
	 */
	public $post;

	/**
	 * Parent product of a variation.
	 *
	 * @var Nexora_Store_Product|null
	 */
	private $parent = null;

	/**
	 * Variations cache.
	 *
	 * @var Nexora_Store_Product[]|null
	 */
	private $variations = null;

	/**
	 * Load by ID.
	 *
	 * @param int $id Post ID.
	 * @return self|null
	 */
	public static function get( $id ) {
		static $cache = array();
		$id = (int) $id;
		if ( ! isset( $cache[ $id ] ) ) {
			$post         = $id ? get_post( $id ) : null;
			$cache[ $id ] = $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ? new self( $post ) : false;
		}
		return $cache[ $id ] ? $cache[ $id ] : null;
	}

	/**
	 * Constructor.
	 *
	 * @param WP_Post $post Post.
	 */
	private function __construct( $post ) {
		$this->post = $post;
	}

	/* ------------------------------------------------------------ identity */

	/**
	 * ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Parent ID (0 for a product).
	 *
	 * @return int
	 */
	public function get_parent_id() {
		return 'product_variation' === $this->post->post_type ? (int) $this->post->post_parent : 0;
	}

	/**
	 * Parent product of a variation, or the product itself.
	 *
	 * @return self
	 */
	public function source() {
		if ( ! $this->get_parent_id() ) {
			return $this;
		}
		if ( null === $this->parent ) {
			$this->parent = self::get( $this->get_parent_id() );
		}
		return $this->parent ? $this->parent : $this;
	}

	/**
	 * Name (of the product for variations).
	 *
	 * @return string
	 */
	public function get_name() {
		return get_the_title( $this->source()->post );
	}

	/**
	 * Type: simple|variable|variation|other WooCommerce type.
	 *
	 * @return string
	 */
	public function get_type() {
		if ( $this->get_parent_id() ) {
			return 'variation';
		}
		$terms = get_the_terms( $this->post, 'product_type' );
		return $terms && ! is_wp_error( $terms ) ? $terms[0]->slug : 'simple';
	}

	/**
	 * Type check.
	 *
	 * @param string|string[] $type Type(s).
	 * @return bool
	 */
	public function is_type( $type ) {
		return in_array( $this->get_type(), (array) $type, true );
	}

	/**
	 * Meta value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return get_post_meta( $this->get_id(), $key, true );
	}

	/**
	 * SKU.
	 *
	 * @return string
	 */
	public function get_sku() {
		return (string) $this->get_meta( '_sku' );
	}

	/**
	 * Permalink.
	 *
	 * @return string
	 */
	public function get_permalink() {
		return (string) get_permalink( $this->source()->post );
	}

	/**
	 * Add-to-cart link (simple) or product page (with options).
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		if ( $this->is_type( 'simple' ) && $this->is_purchasable() && $this->is_in_stock() ) {
			return add_query_arg( 'add-to-cart', $this->get_id(), Nexora_Store::shop_url() );
		}
		return $this->get_permalink();
	}

	/**
	 * Created date.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function get_date_created() {
		$date = get_post_datetime( $this->post );
		return $date ? $date : null;
	}

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function get_short_description() {
		return (string) $this->source()->post->post_excerpt;
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return (string) $this->source()->post->post_content;
	}

	/* ---------------------------------------------------------- visibility */

	/**
	 * Visible in the catalogue.
	 *
	 * @return bool
	 */
	public function is_visible() {
		if ( 'publish' !== $this->source()->post->post_status ) {
			return false;
		}
		return ! has_term( 'exclude-from-catalog', 'product_visibility', $this->source()->post );
	}

	/**
	 * Featured.
	 *
	 * @return bool
	 */
	public function is_featured() {
		return has_term( 'featured', 'product_visibility', $this->source()->post );
	}

	/* -------------------------------------------------------------- images */

	/**
	 * Image ID (variation image, else the product's).
	 *
	 * @return int
	 */
	public function get_image_id() {
		$id = (int) get_post_thumbnail_id( $this->post );
		return $id ? $id : (int) get_post_thumbnail_id( $this->source()->post );
	}

	/**
	 * Gallery IDs.
	 *
	 * @return int[]
	 */
	public function get_gallery_image_ids() {
		$raw = (string) get_post_meta( $this->source()->get_id(), '_product_image_gallery', true );
		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * Image markup.
	 *
	 * @param string $size Size.
	 * @param array  $attr Attributes.
	 * @return string
	 */
	public function get_image( $size = 'woocommerce_thumbnail', $attr = array() ) {
		$id = $this->get_image_id();
		if ( $id ) {
			return wp_get_attachment_image( $id, $size, false, $attr );
		}
		return '<span class="nx-cat-placeholder" aria-hidden="true">' . esc_html( mb_substr( $this->get_name(), 0, 1 ) ) . '</span>';
	}

	/* --------------------------------------------------------------- price */

	/**
	 * Regular price.
	 *
	 * @return string
	 */
	public function get_regular_price() {
		return (string) $this->get_meta( '_regular_price' );
	}

	/**
	 * Sale price when a sale is running now, else ''.
	 *
	 * @return string
	 */
	public function get_sale_price() {
		$sale = (string) $this->get_meta( '_sale_price' );
		if ( '' === $sale ) {
			return '';
		}
		$from = (int) $this->get_meta( '_sale_price_dates_from' );
		$to   = (int) $this->get_meta( '_sale_price_dates_to' );
		$now  = time();
		if ( ( $from && $now < $from ) || ( $to && $now > $to ) ) {
			return '';
		}
		return $sale;
	}

	/**
	 * Current selling price of a simple product or variation.
	 *
	 * @return string
	 */
	public function get_price() {
		$sale = $this->get_sale_price();
		return '' !== $sale ? $sale : $this->get_regular_price();
	}

	/**
	 * Variations.
	 *
	 * @param bool $published_only Only published ones.
	 * @return self[]
	 */
	public function get_variations( $published_only = true ) {
		if ( null === $this->variations ) {
			$ids              = get_posts(
				array(
					'post_type'   => 'product_variation',
					'post_parent' => $this->get_id(),
					'post_status' => array( 'publish', 'private' ),
					'numberposts' => 100,
					'orderby'     => 'menu_order ID',
					'order'       => 'ASC',
					'fields'      => 'ids',
				)
			);
			$this->variations = array_values( array_filter( array_map( array( __CLASS__, 'get' ), $ids ) ) );
		}
		if ( ! $published_only ) {
			return $this->variations;
		}
		return array_values(
			array_filter(
				$this->variations,
				static function ( $v ) {
					return 'publish' === $v->post->post_status && '' !== $v->get_price();
				}
			)
		);
	}

	/**
	 * Variation IDs (WooCommerce's name for it).
	 *
	 * @return int[]
	 */
	public function get_children() {
		return array_map(
			static function ( $v ) {
				return $v->get_id();
			},
			$this->get_variations()
		);
	}

	/**
	 * Option label of a variation, e.g. "5 kg".
	 *
	 * @return string
	 */
	public function option_label() {
		$parts = array();
		foreach ( get_post_meta( $this->get_id() ) as $key => $values ) {
			if ( 0 === strpos( $key, 'attribute_' ) && isset( $values[0] ) && '' !== $values[0] ) {
				$taxonomy = substr( $key, 10 );
				$term     = 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $values[0], $taxonomy ) : null;
				$parts[]  = $term ? $term->name : ( 0 === strpos( $taxonomy, 'pa_' ) ? ucwords( str_replace( '-', ' ', $values[0] ) ) : $values[0] );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * WooCommerce-style price table for variations (used by discount badges).
	 *
	 * @return array{price:array,regular_price:array,sale_price:array}
	 */
	public function get_variation_prices() {
		$out = array( 'price' => array(), 'regular_price' => array(), 'sale_price' => array() );
		foreach ( $this->get_variations() as $v ) {
			$out['price'][ $v->get_id() ]         = $v->get_price();
			$out['regular_price'][ $v->get_id() ] = $v->get_regular_price();
			$out['sale_price'][ $v->get_id() ]    = $v->get_sale_price();
		}
		return $out;
	}

	/**
	 * Lowest or highest variation price.
	 *
	 * @param string $which min|max.
	 * @return float
	 */
	public function get_variation_price( $which = 'min' ) {
		$prices = array_map( 'floatval', $this->get_variation_prices()['price'] );
		if ( ! $prices ) {
			return 0.0;
		}
		return 'max' === $which ? max( $prices ) : min( $prices );
	}

	/**
	 * On sale now.
	 *
	 * @return bool
	 */
	public function is_on_sale() {
		if ( $this->is_type( 'variable' ) ) {
			foreach ( $this->get_variations() as $v ) {
				if ( $v->is_on_sale() ) {
					return true;
				}
			}
			return false;
		}
		$sale = $this->get_sale_price();
		return '' !== $sale && (float) $sale < (float) $this->get_regular_price();
	}

	/**
	 * Price markup with the selling unit.
	 *
	 * @return string
	 */
	public function get_price_html() {
		if ( $this->is_type( 'variable' ) ) {
			$prices = $this->get_variation_prices()['price'];
			if ( ! $prices ) {
				return '';
			}
			$min  = min( array_map( 'floatval', $prices ) );
			$max  = max( array_map( 'floatval', $prices ) );
			$html = Nexora_Store::price( $min );
			if ( $max > $min ) {
				$html .= ' – ' . Nexora_Store::price( $max );
			}
		} elseif ( $this->is_on_sale() ) {
			$html = '<del aria-hidden="true">' . Nexora_Store::price( $this->get_regular_price() ) . '</del> <ins>' . Nexora_Store::price( $this->get_sale_price() ) . '</ins>';
		} elseif ( '' === $this->get_price() ) {
			return '';
		} else {
			$html = Nexora_Store::price( $this->get_price() );
		}
		$unit = (string) $this->source()->get_meta( '_nexora_unit' );
		if ( $unit && class_exists( 'Nexora_Toolkit_Market' ) ) {
			/* translators: %s: selling unit, e.g. kg */
			$html .= ' <span class="nexora-unit">' . esc_html( sprintf( __( '/ %s', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Market::unit_label( $unit ) ) ) . '</span>';
		}
		return $html;
	}

	/* --------------------------------------------------------------- stock */

	/**
	 * The product or variation that holds the stock for this item.
	 *
	 * @return self
	 */
	public function stock_owner() {
		return $this->get_parent_id() && 'parent' === $this->get_meta( '_manage_stock' ) ? $this->source() : $this;
	}

	/**
	 * Managing stock.
	 *
	 * @return bool
	 */
	public function managing_stock() {
		return in_array( $this->stock_owner()->get_meta( '_manage_stock' ), array( 'yes', true, 1, '1' ), true );
	}

	/**
	 * Quantity on hand.
	 *
	 * @return int|null
	 */
	public function get_stock_quantity() {
		if ( ! $this->managing_stock() ) {
			return null;
		}
		return (int) floor( (float) $this->stock_owner()->get_meta( '_stock' ) );
	}

	/**
	 * Backorders allowed.
	 *
	 * @return bool
	 */
	public function backorders_allowed() {
		return in_array( $this->stock_owner()->get_meta( '_backorders' ), array( 'yes', 'notify' ), true );
	}

	/**
	 * In stock.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		if ( $this->is_type( 'variable' ) ) {
			foreach ( $this->get_variations() as $v ) {
				if ( $v->is_in_stock() ) {
					return true;
				}
			}
			return false;
		}
		if ( $this->managing_stock() ) {
			return $this->backorders_allowed() || $this->get_stock_quantity() > 0;
		}
		return 'outofstock' !== $this->stock_owner()->get_meta( '_stock_status' );
	}

	/**
	 * Can be bought (maintenance mode can switch this off through the filter).
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		$ok = 'publish' === $this->source()->post->post_status && ( $this->is_type( 'variable' ) ? (bool) $this->get_variations() : '' !== $this->get_price() );
		return (bool) apply_filters( 'nexora_store_purchasable', $ok, $this );
	}

	/**
	 * Largest quantity that can be ordered now, or null for no limit.
	 *
	 * @return int|null
	 */
	public function max_qty() {
		return $this->managing_stock() && ! $this->backorders_allowed() ? max( 0, (int) $this->get_stock_quantity() ) : null;
	}

	/* ------------------------------------------------------------- reviews */

	/**
	 * Average rating.
	 *
	 * @return float
	 */
	public function get_average_rating() {
		return (float) $this->source()->get_meta( '_wc_average_rating' );
	}

	/**
	 * Number of reviews.
	 *
	 * @return int
	 */
	public function get_review_count() {
		return (int) $this->source()->get_meta( '_wc_review_count' );
	}

	/**
	 * Number of ratings.
	 *
	 * @return int
	 */
	public function get_rating_count() {
		return $this->get_review_count();
	}
}

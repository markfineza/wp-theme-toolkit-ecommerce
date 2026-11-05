<?php
/**
 * Visible labels for importer-created sample content (reviews, testimonials, products),
 * so demo material is never mistaken for genuine customer feedback or real offers.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sample content labels.
 */
class Nexora_Toolkit_Demo_Labels {

	const META = '_nexora_demo';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'nexora_is_sample_content', array( __CLASS__, 'is_sample' ), 10, 3 );
		if ( ! Nexora_Toolkit_Settings::get( 'demo_labels' ) ) {
			return;
		}
		add_action( 'woocommerce_review_before_comment_text', array( __CLASS__, 'review_label' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_label' ), 6 );
	}

	/**
	 * Theme filter: is this object importer-created sample content?
	 *
	 * @param bool   $is_sample Current value.
	 * @param string $type      post|comment.
	 * @param int    $id        Object ID.
	 * @return bool
	 */
	public static function is_sample( $is_sample, $type, $id ) {
		if ( ! Nexora_Toolkit_Settings::get( 'demo_labels' ) ) {
			return $is_sample;
		}
		if ( 'comment' === $type ) {
			return (bool) get_comment_meta( $id, self::META, true );
		}
		if ( 'post' === $type ) {
			return (bool) get_post_meta( $id, self::META, true );
		}
		return $is_sample;
	}

	/**
	 * "Sample review" label above review text.
	 *
	 * @param WP_Comment $comment Comment.
	 */
	public static function review_label( $comment ) {
		if ( get_comment_meta( $comment->comment_ID, self::META, true ) ) {
			echo '<p class="nx-sample-label"><span class="nx-tag">' . esc_html__( 'Sample review — demo content', 'nexora-commerce-toolkit' ) . '</span></p>';
		}
	}

	/**
	 * "Demo product" label on product pages.
	 */
	public static function product_label() {
		if ( get_post_meta( get_the_ID(), self::META, true ) ) {
			echo '<p class="nx-sample-label"><span class="nx-tag">' . esc_html__( 'Demo product — sample listing, prices are illustrative', 'nexora-commerce-toolkit' ) . '</span></p>';
		}
	}
}

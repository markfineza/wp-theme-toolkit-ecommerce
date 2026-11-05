<?php
/**
 * Customer email sent when an order is marked "Ready for pickup".
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order ready email.
 */
class Nexora_Toolkit_Email_Order_Ready extends WC_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'nexora_order_ready';
		$this->customer_email = true;
		$this->title          = __( 'Order ready for pickup', 'nexora-commerce-toolkit' );
		$this->description    = __( 'Sent to the customer when an order is marked “Ready for pickup”: for store pickup it says the order can be collected, for courier orders it tells the customer to book their rider.', 'nexora-commerce-toolkit' );
		$this->template_base  = NEXORA_TOOLKIT_DIR . 'templates/';
		$this->template_html  = 'emails/customer-order-ready.php';
		$this->template_plain = 'emails/plain/customer-order-ready.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_order_status_' . Nexora_Toolkit_Fulfilment::STATUS . '_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is ready', 'nexora-commerce-toolkit' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order is packed and ready', 'nexora-commerce-toolkit' );
	}

	/**
	 * Default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Thank you for buying from our market.', 'nexora-commerce-toolkit' );
	}

	/**
	 * Send.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|bool $order    Order.
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();
		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	/**
	 * Template arguments.
	 *
	 * @return array
	 */
	private function args() {
		return array(
			'order'              => $this->object,
			'fulfilment'         => Nexora_Toolkit_Fulfilment::for_order( $this->object ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => false,
			'email'              => $this,
		);
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args(), '', $this->template_base );
	}

	/**
	 * Plain body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, array_merge( $this->args(), array( 'plain_text' => true ) ), '', $this->template_base );
	}
}

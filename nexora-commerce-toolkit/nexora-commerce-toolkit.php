<?php
/**
 * Plugin Name:       Freshtastic Food Hub Toolkit
 * Plugin URI:        https://example.com/nexora-commerce
 * Description:       Companion plugin for the Freshtastic Food Hub theme: optional built-in store that runs without WooCommerce, market data (units, origin, price history), request for quotation, store pickup and customer-booked courier delivery with time slots, modern order screens and an Order Desk staff role, registration consent records, reCAPTCHA and email login codes, demo content importer and cleanup, wishlist, newsletter provider integrations (Mailchimp, Brevo, Klaviyo), consent-aware analytics events (GA4, GTM, Meta Pixel, Google Ads) and testimonials. Features keep working if you switch themes.
 * Version:           2.6.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Freshtastic Food Hub Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexora-commerce-toolkit
 * Domain Path:       /languages
 * WC requires at least: 8.5
 * WC tested up to:   11.2
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXORA_TOOLKIT_VERSION', '2.6.0' );
define( 'NEXORA_TOOLKIT_FILE', __FILE__ );
define( 'NEXORA_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXORA_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

require NEXORA_TOOLKIT_DIR . 'includes/class-dependencies.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-settings.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-demo-labels.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-testimonials.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-newsletter.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-wishlist.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-tracking.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-market.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-rfq.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-maintenance.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-fulfilment.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-order-desk.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-accounts.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-schedule.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-rewards.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-security.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-captcha.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-consent.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-login-otp.php';
require NEXORA_TOOLKIT_DIR . 'includes/class-demo-importer.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-product.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-catalog.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-cart.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-coupons.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-orders.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-checkout.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-account.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-admin.php';
require NEXORA_TOOLKIT_DIR . 'includes/store/class-store-importer.php';

/**
 * Boot modules.
 */
function nexora_toolkit_boot() {
	load_plugin_textdomain( 'nexora-commerce-toolkit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	Nexora_Toolkit_Settings::init();
	Nexora_Toolkit_Demo_Labels::init();
	Nexora_Toolkit_Testimonials::init();
	Nexora_Toolkit_Newsletter::init();

	if ( class_exists( 'WooCommerce' ) ) {
		Nexora_Toolkit_Wishlist::init();
		Nexora_Toolkit_Tracking::init();
		Nexora_Toolkit_Market::init();
		Nexora_Toolkit_Fulfilment::init();
		Nexora_Toolkit_Order_Desk::init();
		Nexora_Toolkit_Accounts::init();
	} elseif ( Nexora_Store::active() ) {
		Nexora_Toolkit_Wishlist::init();
		Nexora_Toolkit_Market::init();
	}
	Nexora_Store::boot();
	if ( class_exists( 'WooCommerce' ) || Nexora_Store::active() ) {
		Nexora_Toolkit_Rewards::init();
	}
	Nexora_Toolkit_RFQ::init();
	Nexora_Toolkit_Maintenance::init();
	Nexora_Toolkit_Security::init();
	Nexora_Toolkit_Consent::init();
	Nexora_Toolkit_Captcha::init();
	Nexora_Toolkit_Login_OTP::init();
	if ( is_admin() ) {
		Nexora_Toolkit_Dependencies::init();
		Nexora_Toolkit_Demo_Importer::init();
	}
}
add_action( 'plugins_loaded', 'nexora_toolkit_boot', 20 );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage and Cart/Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Activation: schedule installation of required plugins (WooCommerce) for the next admin page load,
 * and register the wishlist endpoint before flushing rewrite rules.
 */
function nexora_toolkit_activate() {
	Nexora_Toolkit_Dependencies::schedule();
	Nexora_Toolkit_Order_Desk::ensure_role();
	add_rewrite_endpoint( 'wishlist', EP_PAGES );
	add_rewrite_endpoint( 'quotations', EP_PAGES );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'nexora_toolkit_activate' );

/**
 * Deactivation: tidy rewrite rules. Data (subscribers, wishlists, demo content) is kept;
 * use Tools → Freshtastic Demo Content → Remove demo content to delete demo items.
 */
function nexora_toolkit_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'nexora_toolkit_deactivate' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Install and activate the plugins Freshtastic Food Hub needs (WooCommerce by default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp nexora install-dependencies
	 */
	WP_CLI::add_command(
		'nexora install-dependencies',
		static function () {
			if ( ! Nexora_Toolkit_Dependencies::missing() ) {
				WP_CLI::success( 'All required plugins are installed and active.' );
				return;
			}
			$result = Nexora_Toolkit_Dependencies::install_all();
			foreach ( $result['done'] as $name ) {
				WP_CLI::log( "Installed and activated {$name}." );
			}
			foreach ( $result['failed'] as $name => $error ) {
				WP_CLI::warning( "{$name}: {$error}" );
			}
			$result['failed'] ? WP_CLI::error( 'Some plugins could not be installed.' ) : WP_CLI::success( 'Done.' );
		}
	);
}

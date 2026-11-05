<?php
/**
 * Required plugin installer.
 *
 * When the toolkit is activated, required plugins (WooCommerce by default) are downloaded from
 * WordPress.org, installed and activated on the next admin page load. If the server cannot write
 * plugin files directly (FTP credentials required, DISALLOW_FILE_MODS, multisite), an admin notice
 * offers a one-click install instead.
 *
 * Add plugins with the `nexora_toolkit_required_plugins` filter:
 *
 *     add_filter( 'nexora_toolkit_required_plugins', function ( $plugins ) {
 *         $plugins['mailchimp-for-woocommerce'] = 'Mailchimp for WooCommerce';
 *         return $plugins;
 *     } );
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dependency installer.
 */
class Nexora_Toolkit_Dependencies {

	const FLAG   = 'nexora_toolkit_install_deps';
	const RESULT = 'nexora_toolkit_deps_result';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_nexora_install_deps', array( __CLASS__, 'handle_button' ) );
	}

	/**
	 * Required plugins: WordPress.org slug => name.
	 *
	 * @return array<string,string>
	 */
	public static function required() {
		return (array) apply_filters( 'nexora_toolkit_required_plugins', array( 'woocommerce' => 'WooCommerce' ) );
	}

	/**
	 * Called from the activation hook: install on the next admin request (activation requests
	 * cannot safely run a second plugin installer).
	 */
	public static function schedule() {
		update_option( self::FLAG, 1, false );
	}

	/**
	 * Installed plugin file for a slug (e.g. woocommerce/woocommerce.php), or ''.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $slug . '.php' === $file ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Required plugins that are missing or inactive.
	 *
	 * @return array<string,string>
	 */
	public static function missing() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$missing = array();
		foreach ( self::required() as $slug => $name ) {
			$file = self::plugin_file( $slug );
			if ( ! $file || ! is_plugin_active( $file ) ) {
				$missing[ $slug ] = $name;
			}
		}
		return $missing;
	}

	/**
	 * Whether this site lets PHP install plugins without asking for credentials.
	 *
	 * @return bool
	 */
	private static function can_install_silently() {
		if ( is_multisite() || ! wp_is_file_mod_allowed( 'nexora_install_dependencies' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return 'direct' === get_filesystem_method( array(), WP_PLUGIN_DIR );
	}

	/**
	 * Install and activate every missing required plugin.
	 *
	 * @return array{done:string[],failed:array<string,string>}
	 */
	public static function install_all() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$result = array( 'done' => array(), 'failed' => array() );
		foreach ( self::missing() as $slug => $name ) {
			$file = self::plugin_file( $slug );

			if ( ! $file ) {
				$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
				if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
					$result['failed'][ $name ] = is_wp_error( $api ) ? $api->get_error_message() : __( 'Not found on WordPress.org.', 'nexora-commerce-toolkit' );
					continue;
				}
				$upgrader  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
				$installed = $upgrader->install( $api->download_link );
				if ( true !== $installed ) {
					$result['failed'][ $name ] = is_wp_error( $installed ) ? $installed->get_error_message() : __( 'Download or unpacking failed.', 'nexora-commerce-toolkit' );
					continue;
				}
				wp_clean_plugins_cache();
				$file = self::plugin_file( $slug );
			}

			$activated = $file ? activate_plugin( $file ) : new WP_Error( 'missing', __( 'Plugin file not found after install.', 'nexora-commerce-toolkit' ) );
			if ( is_wp_error( $activated ) ) {
				$result['failed'][ $name ] = $activated->get_error_message();
			} else {
				$result['done'][] = $name;
			}
		}
		return $result;
	}

	/**
	 * Run the scheduled install on the first admin request after activation.
	 */
	public static function maybe_auto_install() {
		if ( ! get_option( self::FLAG ) || wp_doing_ajax() || ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		delete_option( self::FLAG );
		if ( ! self::missing() || ! self::can_install_silently() ) {
			return; // Nothing to do, or the notice below offers a manual install.
		}
		set_transient( self::RESULT, self::install_all(), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * One-click install button handler.
	 */
	public static function handle_button() {
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to install plugins.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_install_deps' );
		set_transient( self::RESULT, self::install_all(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Result notice, or an install prompt while something is still missing.
	 */
	public static function notices() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$result = get_transient( self::RESULT );
		if ( $result ) {
			delete_transient( self::RESULT );
			if ( $result['done'] ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p><strong>Freshtastic Food Hub Toolkit:</strong> %s</p></div>',
					/* translators: %s: plugin names */
					esc_html( sprintf( __( 'Installed and activated %s.', 'nexora-commerce-toolkit' ), implode( ', ', $result['done'] ) ) )
				);
			}
			foreach ( $result['failed'] as $name => $error ) {
				printf(
					'<div class="notice notice-error"><p><strong>Freshtastic Food Hub Toolkit:</strong> %s</p></div>',
					/* translators: 1: plugin name, 2: error */
					esc_html( sprintf( __( 'Could not install %1$s automatically: %2$s', 'nexora-commerce-toolkit' ), $name, $error ) )
				);
			}
		}

		$missing = self::missing();
		if ( ! $missing ) {
			return;
		}
		$can_click = self::can_install_silently() && current_user_can( 'activate_plugins' );
		$action    = $can_click
			? sprintf( '<a class="button button-primary" href="%1$s">%2$s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_install_deps' ), 'nexora_install_deps' ) ), esc_html__( 'Install and activate now', 'nexora-commerce-toolkit' ) )
			: sprintf( '<a class="button button-primary" href="%1$s">%2$s</a>', esc_url( admin_url( 'plugin-install.php?s=' . rawurlencode( (string) key( $missing ) ) . '&tab=search&type=term' ) ), esc_html__( 'Install from the plugin directory', 'nexora-commerce-toolkit' ) );
		printf(
			'<div class="notice notice-warning"><p><strong>Freshtastic Food Hub Toolkit</strong> %1$s <strong>%2$s</strong>.</p><p>%3$s</p></div>',
			esc_html__( 'needs these plugins to be installed and active:', 'nexora-commerce-toolkit' ),
			esc_html( implode( ', ', $missing ) ),
			$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}
}

<?php
/**
 * Promo pop-up: a modal with up to three banner images, set up under Settings → Freshtastic Promo Pop-up.
 *
 * How often a visitor sees it is decided in the browser, so it keeps working behind a page cache. Each
 * set of banners has its own key, so changing them shows the new promo to people who closed the old one.
 * Never shown on cart, checkout or account pages.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Promo pop-up.
 */
class Nexora_Toolkit_Promo_Popup {

	const OPTION  = 'nexora_promo_popup';
	const PAGE    = 'nexora-promo-popup';
	const BANNERS = 3;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 ); // Before the footer scripts at 20, which look for it.
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'   => false,
			'where'     => 'home',
			'audience'  => 'all',
			'frequency' => 'day',
			'delay'     => 3,
			'banners'   => array(),
		);
	}

	/**
	 * Saved settings with every banner slot present.
	 *
	 * @return array
	 */
	public static function settings() {
		$all     = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$banners = array();
		for ( $i = 0; $i < self::BANNERS; $i++ ) {
			$banners[ $i ] = wp_parse_args(
				isset( $all['banners'][ $i ] ) ? (array) $all['banners'][ $i ] : array(),
				array( 'image' => 0, 'alt' => '', 'url' => '', 'start' => '', 'end' => '' )
			);
		}
		$all['banners'] = $banners;
		return $all;
	}

	/**
	 * Is this a preview by an administrator?
	 *
	 * @return bool
	 */
	private static function is_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for administrators.
		return isset( $_GET['nx_popup_preview'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Banners to show now.
	 *
	 * @param array $settings Settings.
	 * @param bool  $preview  Ignore dates.
	 * @return array
	 */
	private static function live_banners( $settings, $preview = false ) {
		$today = current_datetime()->format( 'Y-m-d' );
		$live  = array();
		foreach ( $settings['banners'] as $banner ) {
			if ( ! $banner['image'] || ! wp_get_attachment_image_url( (int) $banner['image'], 'full' ) ) {
				continue;
			}
			if ( ! $preview && ( ( $banner['start'] && $banner['start'] > $today ) || ( $banner['end'] && $banner['end'] < $today ) ) ) {
				continue;
			}
			$live[] = $banner;
		}
		return $live;
	}

	/**
	 * Should this page carry the pop-up? Returns the banners, or an empty list.
	 *
	 * @return array
	 */
	private static function for_this_page() {
		$settings = self::settings();
		if ( self::is_preview() ) {
			return self::live_banners( $settings, true );
		}
		if ( ! $settings['enabled'] || is_customize_preview() || is_404() || Nexora_Toolkit_Login_OTP::is_request() ) {
			return array();
		}
		if ( ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) || Nexora_Store::is_page( 'cart' ) || Nexora_Store::is_page( 'checkout' ) || Nexora_Store::is_page( 'account' ) ) {
			return array();
		}
		if ( 'home' === $settings['where'] && ! is_front_page() ) {
			return array();
		}
		if ( ( 'guests' === $settings['audience'] && is_user_logged_in() ) || ( 'customers' === $settings['audience'] && ! is_user_logged_in() ) ) {
			return array();
		}
		return self::live_banners( $settings );
	}

	/**
	 * Load the files only where needed.
	 */
	public static function assets() {
		if ( self::for_this_page() ) {
			wp_enqueue_style( 'nexora-promo-popup', NEXORA_TOOLKIT_URL . 'assets/css/promo-popup.css', array(), NEXORA_TOOLKIT_VERSION );
			wp_enqueue_script( 'nexora-promo-popup', NEXORA_TOOLKIT_URL . 'assets/js/promo-popup.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
	}

	/**
	 * Print the closed dialog. Images load only when it opens, so it does not slow the page down.
	 */
	public static function render() {
		$banners = self::for_this_page();
		if ( ! $banners ) {
			return;
		}
		$settings = self::settings();
		$key      = substr( md5( (string) wp_json_encode( $banners ) ), 0, 12 );
		$count    = count( $banners );
		?>
		<dialog class="nx-popup" aria-label="<?php esc_attr_e( 'Promotions and deals', 'nexora-commerce-toolkit' ); ?>" data-nx-popup data-key="<?php echo esc_attr( $key ); ?>" data-delay="<?php echo (int) $settings['delay']; ?>" data-frequency="<?php echo esc_attr( $settings['frequency'] ); ?>" <?php echo self::is_preview() ? 'data-preview' : ''; ?>>
			<button type="button" class="nx-popup__close" data-nx-popup-close aria-label="<?php esc_attr_e( 'Close', 'nexora-commerce-toolkit' ); ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
			<div class="nx-popup__slides">
				<?php foreach ( $banners as $i => $banner ) : ?>
					<?php
					$id   = (int) $banner['image'];
					$meta = wp_get_attachment_metadata( $id );
					$alt  = '' !== $banner['alt'] ? $banner['alt'] : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
					$alt  = '' !== $alt ? $alt : __( 'Promotion', 'nexora-commerce-toolkit' );
					$end  = '';
					if ( $banner['end'] ) {
						$end = ( new DateTimeImmutable( $banner['end'] . ' 23:59:59', wp_timezone() ) )->getTimestamp();
					}
					$img = sprintf(
						'<img data-src="%1$s" data-srcset="%2$s" sizes="(min-width: 800px) 720px, 92vw" alt="%3$s" width="%4$d" height="%5$d">',
						esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ),
						esc_attr( (string) wp_get_attachment_image_srcset( $id, 'large' ) ),
						esc_attr( $alt ),
						isset( $meta['width'] ) ? (int) $meta['width'] : 720,
						isset( $meta['height'] ) ? (int) $meta['height'] : 480
					);
					?>
					<div class="nx-popup__slide" data-nx-popup-slide data-end="<?php echo esc_attr( (string) $end ); ?>" <?php echo $i ? 'hidden' : ''; ?>>
						<?php if ( $banner['url'] ) : ?>
							<a href="<?php echo esc_url( $banner['url'] ); ?>"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></a>
						<?php else : ?>
							<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $count > 1 ) : ?>
				<div class="nx-popup__nav" data-nx-popup-nav>
					<button type="button" data-nx-popup-prev aria-label="<?php esc_attr_e( 'Previous promotion', 'nexora-commerce-toolkit' ); ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
					<span data-nx-popup-count aria-live="polite"></span>
					<button type="button" data-nx-popup-next aria-label="<?php esc_attr_e( 'Next promotion', 'nexora-commerce-toolkit' ); ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
				</div>
			<?php endif; ?>
		</dialog>
		<?php
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Promo Pop-up', 'nexora-commerce-toolkit' ), __( 'Freshtastic Promo Pop-up', 'nexora-commerce-toolkit' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'nexora_promo_popup',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Media library on this screen only.
	 *
	 * @param string $hook Admin page.
	 */
	public static function admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_media();
			wp_enqueue_script( 'nexora-promo-popup-admin', NEXORA_TOOLKIT_URL . 'assets/js/promo-popup-admin.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
	}

	/**
	 * A date, or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function clean_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array(
			'enabled'   => ! empty( $input['enabled'] ),
			'where'     => isset( $input['where'] ) && 'all' === $input['where'] ? 'all' : 'home',
			'audience'  => isset( $input['audience'] ) && in_array( $input['audience'], array( 'guests', 'customers' ), true ) ? $input['audience'] : 'all',
			'frequency' => isset( $input['frequency'] ) && in_array( $input['frequency'], array( 'always', 'session', 'week' ), true ) ? $input['frequency'] : 'day',
			'delay'     => isset( $input['delay'] ) ? min( 60, absint( $input['delay'] ) ) : 3,
			'banners'   => array(),
		);
		$rows  = isset( $input['banners'] ) && is_array( $input['banners'] ) ? $input['banners'] : array();
		for ( $i = 0; $i < self::BANNERS; $i++ ) {
			$row                = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
			$out['banners'][ $i ] = array(
				'image' => isset( $row['image'] ) ? absint( $row['image'] ) : 0,
				'alt'   => isset( $row['alt'] ) ? sanitize_text_field( wp_unslash( (string) $row['alt'] ) ) : '',
				'url'   => isset( $row['url'] ) ? esc_url_raw( trim( wp_unslash( (string) $row['url'] ) ) ) : '',
				'start' => self::clean_date( isset( $row['start'] ) ? $row['start'] : '' ),
				'end'   => self::clean_date( isset( $row['end'] ) ? $row['end'] : '' ),
			);
		}
		if ( $out['enabled'] && ! array_filter( wp_list_pluck( $out['banners'], 'image' ) ) ) {
			add_settings_error( self::OPTION, 'nexora_popup_empty', __( 'The pop-up is switched on but has no banner image, so it will not appear yet.', 'nexora-commerce-toolkit' ), 'warning' );
		}
		return $out;
	}

	/**
	 * Settings screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$name = static function ( $key ) {
			return self::OPTION . '[' . $key . ']';
		};
		$live = self::live_banners( $s );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Freshtastic Promo Pop-up', 'nexora-commerce-toolkit' ); ?></h1>
			<p>
				<?php
				if ( $s['enabled'] && $live ) {
					/* translators: %d: number of banners */
					echo '<strong style="color:#0b5a45">' . esc_html( sprintf( _n( 'Showing now: %d banner.', 'Showing now: %d banners.', count( $live ), 'nexora-commerce-toolkit' ), count( $live ) ) ) . '</strong>';
				} elseif ( $s['enabled'] ) {
					echo '<strong style="color:#a02b12">' . esc_html__( 'Switched on, but no banner is in its date range right now.', 'nexora-commerce-toolkit' ) . '</strong>';
				} else {
					echo '<strong>' . esc_html__( 'Switched off.', 'nexora-commerce-toolkit' ) . '</strong>';
				}
				?>
				<a class="button" style="margin-left:8px" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'nx_popup_preview', '1', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Preview saved banners', 'nexora-commerce-toolkit' ); ?></a>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_promo_popup' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Show the pop-up', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'enabled' ) ); ?>" value="1" <?php checked( (bool) $s['enabled'] ); ?>> <?php esc_html_e( 'On', 'nexora-commerce-toolkit' ); ?></label></td></tr>
					<tr><th scope="row"><label for="nx-popup-where"><?php esc_html_e( 'Where', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<select id="nx-popup-where" name="<?php echo esc_attr( $name( 'where' ) ); ?>">
							<option value="home" <?php selected( $s['where'], 'home' ); ?>><?php esc_html_e( 'Homepage only', 'nexora-commerce-toolkit' ); ?></option>
							<option value="all" <?php selected( $s['where'], 'all' ); ?>><?php esc_html_e( 'All pages', 'nexora-commerce-toolkit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Cart, checkout and account pages never show it, so it cannot interrupt a purchase.', 'nexora-commerce-toolkit' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="nx-popup-audience"><?php esc_html_e( 'Who sees it', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<select id="nx-popup-audience" name="<?php echo esc_attr( $name( 'audience' ) ); ?>">
							<option value="all" <?php selected( $s['audience'], 'all' ); ?>><?php esc_html_e( 'Everyone', 'nexora-commerce-toolkit' ); ?></option>
							<option value="guests" <?php selected( $s['audience'], 'guests' ); ?>><?php esc_html_e( 'Visitors who are not signed in', 'nexora-commerce-toolkit' ); ?></option>
							<option value="customers" <?php selected( $s['audience'], 'customers' ); ?>><?php esc_html_e( 'Signed-in customers', 'nexora-commerce-toolkit' ); ?></option>
						</select>
					</td></tr>
					<tr><th scope="row"><label for="nx-popup-frequency"><?php esc_html_e( 'How often', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<select id="nx-popup-frequency" name="<?php echo esc_attr( $name( 'frequency' ) ); ?>">
							<option value="session" <?php selected( $s['frequency'], 'session' ); ?>><?php esc_html_e( 'Once per visit', 'nexora-commerce-toolkit' ); ?></option>
							<option value="day" <?php selected( $s['frequency'], 'day' ); ?>><?php esc_html_e( 'Once a day', 'nexora-commerce-toolkit' ); ?></option>
							<option value="week" <?php selected( $s['frequency'], 'week' ); ?>><?php esc_html_e( 'Once a week', 'nexora-commerce-toolkit' ); ?></option>
							<option value="always" <?php selected( $s['frequency'], 'always' ); ?>><?php esc_html_e( 'Every page load', 'nexora-commerce-toolkit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Changing the banners shows the new promo again, even to people who closed the old one.', 'nexora-commerce-toolkit' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="nx-popup-delay"><?php esc_html_e( 'Wait before showing (seconds)', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" class="small-text" min="0" max="60" id="nx-popup-delay" name="<?php echo esc_attr( $name( 'delay' ) ); ?>" value="<?php echo (int) $s['delay']; ?>"></td></tr>
				</table>

				<?php foreach ( $s['banners'] as $i => $banner ) : ?>
					<?php $field = static function ( $key ) use ( $name, $i ) { return $name( 'banners' ) . '[' . $i . '][' . $key . ']'; }; ?>
					<?php /* translators: %d: banner number */ ?>
					<h2><?php echo esc_html( sprintf( __( 'Banner %d', 'nexora-commerce-toolkit' ), $i + 1 ) ); ?></h2>
					<table class="form-table" role="presentation" data-nx-popup-banner>
						<tr><th scope="row"><?php esc_html_e( 'Image', 'nexora-commerce-toolkit' ); ?></th><td>
							<div data-nx-popup-preview style="margin-bottom:8px"><?php echo $banner['image'] ? wp_get_attachment_image( (int) $banner['image'], 'medium', false, array( 'style' => 'max-width:320px;height:auto' ) ) : ''; ?></div>
							<input type="hidden" name="<?php echo esc_attr( $field( 'image' ) ); ?>" value="<?php echo (int) $banner['image']; ?>" data-nx-popup-image>
							<button type="button" class="button" data-nx-popup-choose><?php esc_html_e( 'Choose image', 'nexora-commerce-toolkit' ); ?></button>
							<button type="button" class="button-link button-link-delete" data-nx-popup-clear style="margin-left:8px"><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></button>
							<p class="description"><?php esc_html_e( 'Landscape works best on computers and phones, for example 1200 × 800 pixels. Keep key text large and away from the edges.', 'nexora-commerce-toolkit' ); ?></p>
						</td></tr>
						<tr><th scope="row"><label for="nx-popup-alt-<?php echo (int) $i; ?>"><?php esc_html_e( 'What the banner says', 'nexora-commerce-toolkit' ); ?></label></th><td>
							<input type="text" class="large-text" id="nx-popup-alt-<?php echo (int) $i; ?>" name="<?php echo esc_attr( $field( 'alt' ) ); ?>" value="<?php echo esc_attr( $banner['alt'] ); ?>">
							<p class="description"><?php esc_html_e( 'For screen readers and if the image fails to load, for example: 20% off all fresh fish this weekend.', 'nexora-commerce-toolkit' ); ?></p>
						</td></tr>
						<tr><th scope="row"><label for="nx-popup-url-<?php echo (int) $i; ?>"><?php esc_html_e( 'Link when clicked', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="url" class="large-text" id="nx-popup-url-<?php echo (int) $i; ?>" name="<?php echo esc_attr( $field( 'url' ) ); ?>" value="<?php echo esc_attr( $banner['url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/shop/' ) ); ?>"></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Dates (optional)', 'nexora-commerce-toolkit' ); ?></th><td>
							<label><?php esc_html_e( 'From', 'nexora-commerce-toolkit' ); ?> <input type="date" name="<?php echo esc_attr( $field( 'start' ) ); ?>" value="<?php echo esc_attr( $banner['start'] ); ?>"></label>
							<label style="margin-left:12px"><?php esc_html_e( 'Until the end of', 'nexora-commerce-toolkit' ); ?> <input type="date" name="<?php echo esc_attr( $field( 'end' ) ); ?>" value="<?php echo esc_attr( $banner['end'] ); ?>"></label>
						</td></tr>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

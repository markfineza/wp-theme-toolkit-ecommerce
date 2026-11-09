<?php
/**
 * Template helpers shared across templates.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return an inline SVG icon. Icons are decorative (aria-hidden) — pair them with visible or screen-reader text.
 *
 * @param string $name Icon name.
 * @param int    $size Pixel size.
 * @return string
 */
function nexora_icon( $name, $size = 20 ) {
	static $paths = null;
	if ( null === $paths ) {
		$paths = array(
			'search'        => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
			'heart'         => '<path d="M12 20s-7-4.4-9-9.2C1.7 7.3 4 4 7.4 4c2 0 3.5 1.1 4.6 2.7C13.1 5.1 14.6 4 16.6 4 20 4 22.3 7.3 21 10.8 19 15.6 12 20 12 20z"/>',
			'bag'           => '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
			'menu'          => '<path d="M4 7h16M4 12h16M4 17h10"/>',
			'close'         => '<path d="M6 6l12 12M18 6 6 18"/>',
			'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
			'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
			'chevron-left'  => '<path d="m15 6-6 6 6 6"/>',
			'arrow-right'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'star'          => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3z"/>',
			'truck'         => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
			'return'        => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
			'shield'        => '<path d="M12 3 5 6v6c0 4.4 3 7.6 7 9 4-1.4 7-4.6 7-9V6l-7-3z"/><path d="m9 12 2 2 4-4"/>',
			'chat'          => '<path d="M4 5h16v11H8l-4 4V5z"/><path d="M8 10h8M8 13h5"/>',
			'sparkle'       => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
			'tag'           => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
			'grid'          => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
			'list'          => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
			'filter'        => '<path d="M4 6h16M7 12h10M10 18h4"/>',
			'plus'          => '<path d="M12 5v14M5 12h14"/>',
			'minus'         => '<path d="M5 12h14"/>',
			'check'         => '<path d="m5 12 5 5 9-10"/>',
			'clock'         => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
			'mail'          => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'phone'         => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
			'pin'           => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
			'eye'           => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'facebook'      => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v7h4v-7h3l1-4h-4V8z"/>',
			'instagram'     => '<rect x="4" y="4" width="16" height="16" rx="5"/><circle cx="12" cy="12" r="3.5"/><circle cx="17" cy="7" r=".8"/>',
			'tiktok'        => '<path d="M14 4v10.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.5 2.5 2.3 4 5 4"/>',
			'youtube'       => '<rect x="3" y="6" width="18" height="12" rx="4"/><path d="m10 9 5 3-5 3V9z"/>',
			'x'             => '<path d="M4 4l16 16M20 4 4 20"/>',
			'scale'         => '<path d="M12 4v16M5 20h14M12 6l-7 2 3 7a3.5 3.5 0 0 1-6 0l3-7M12 6l7 2-3 7a3.5 3.5 0 0 0 6 0l-3-7"/>',
			'snow'          => '<path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9M9.5 4.5 12 7l2.5-2.5M9.5 19.5 12 17l2.5 2.5"/>',
			'leaf'          => '<path d="M5 19c0-8 5-14 15-15-1 10-7 15-15 15z"/><path d="M5 19 13 11"/>',
			'chart'         => '<path d="M4 19h16"/><path d="m5 15 4-5 4 3 6-7"/><path d="M15 6h4v4"/>',
			'clipboard'     => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"/>',
			'store'         => '<path d="M4 9 5.5 4h13L20 9M4 9h16v2a2.7 2.7 0 0 1-5.3 0 2.7 2.7 0 0 1-5.4 0A2.7 2.7 0 0 1 4 11V9zM5 13v7h14v-7"/><path d="M10 20v-4h4v4"/>',
			'pause'         => '<path d="M9 6v12M15 6v12"/>',
			'play'          => '<path d="m8 5 11 7-11 7V5z"/>',
			'bike'          => '<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h5l3-6h4M9 8h3l2 3M15 6h2"/>',
			'lock'          => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
			'card'          => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'logout'        => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
			'download'      => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
		);
	}
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return sprintf(
		'<svg class="nx-icon nx-icon-%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
		esc_attr( $name ),
		(int) $size,
		$paths[ $name ] // Static, trusted markup defined above.
	);
}

/**
 * Whether the announcement bar should render now.
 *
 * @return bool
 */
function nexora_announcement_is_visible() {
	if ( ! nexora_option( 'announcement_enabled' ) || '' === trim( (string) nexora_option( 'announcement_text' ) ) ) {
		return false;
	}
	$ends = nexora_datetime_to_timestamp( nexora_option( 'announcement_countdown' ) );
	return null === $ends || $ends > time();
}

/**
 * Site logo or text wordmark.
 */
function nexora_site_logo() {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a class="site-logo" href="%1$s" rel="home"><img src="%2$s" srcset="%3$s 100w, %2$s 200w, %4$s 333w" sizes="(max-width: 767px) 62px, 84px" width="200" height="192" alt="%5$s"></a>',
		esc_url( home_url( '/' ) ),
		esc_url( nexora_brand_asset( 'logo-192.webp' ) ),
		esc_url( nexora_brand_asset( 'logo-96.webp' ) ),
		esc_url( nexora_brand_asset( 'logo-320.webp' ) ),
		esc_attr( get_bloginfo( 'name' ) )
	);
}

/**
 * Breadcrumbs. Defers to an SEO plugin when it provides them, so markup and schema are not duplicated.
 */
function nexora_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}

	if ( function_exists( 'yoast_breadcrumb' ) && class_exists( 'WPSEO_Options' ) && WPSEO_Options::get( 'breadcrumbs-enable' ) ) {
		yoast_breadcrumb( '<nav class="nx-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'nexora-commerce' ) . '">', '</nav>' );
		return;
	}
	if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
		echo '<nav class="nx-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'nexora-commerce' ) . '">';
		rank_math_the_breadcrumbs();
		echo '</nav>';
		return;
	}
	if ( function_exists( 'woocommerce_breadcrumb' ) ) {
		woocommerce_breadcrumb(
			array(
				'wrap_before' => '<nav class="nx-breadcrumbs woocommerce-breadcrumb" aria-label="' . esc_attr__( 'Breadcrumb', 'nexora-commerce' ) . '"><ol>',
				'wrap_after'  => '</ol></nav>',
				'before'      => '<li>',
				'after'       => '</li>',
				'delimiter'   => '',
			)
		);
		return;
	}

	$items = array( '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'nexora-commerce' ) . '</a>' );
	if ( is_singular( 'post' ) ) {
		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts ) {
			$items[] = '<a href="' . esc_url( get_permalink( $page_for_posts ) ) . '">' . esc_html( get_the_title( $page_for_posts ) ) . '</a>';
		}
		$items[] = '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
	} elseif ( is_singular() ) {
		$items[] = '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
	} elseif ( is_archive() || is_home() || is_search() ) {
		$items[] = '<span aria-current="page">' . esc_html( wp_strip_all_tags( is_search() ? __( 'Search', 'nexora-commerce' ) : ( is_home() ? single_post_title( '', false ) : get_the_archive_title() ) ) ) . '</span>';
	}
	echo '<nav class="nx-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'nexora-commerce' ) . '"><ol><li>' . implode( '</li><li>', $items ) . '</li></ol></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- items escaped above.
}

/**
 * Section heading with optional "view all" link.
 *
 * @param string $title      Heading.
 * @param string $link_url   Link URL.
 * @param string $link_label Link label.
 * @param string $eyebrow    Small label above the heading.
 * @param string $id         Heading id for aria-labelledby.
 */
function nexora_section_header( $title, $link_url = '', $link_label = '', $eyebrow = '', $id = '' ) {
	?>
	<div class="nx-section-head">
		<div>
			<?php if ( $eyebrow ) : ?>
				<p class="nx-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<?php endif; ?>
			<h2 class="nx-section-title"<?php echo $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>><?php echo esc_html( $title ); ?></h2>
		</div>
		<?php if ( $link_url ) : ?>
			<a class="nx-link-arrow" href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_label ? $link_label : __( 'View all', 'nexora-commerce' ) ); ?> <?php echo nexora_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Centred section heading used by the storefront homepage.
 *
 * @param string $title   Title.
 * @param string $eyebrow Small text above.
 * @param string $id      Heading ID.
 * @param string $text    Text below.
 */
function nexora_center_heading( $title, $eyebrow = '', $id = '', $text = '' ) {
	?>
	<div class="nx-center-head">
		<?php if ( $eyebrow ) : ?>
			<p class="nx-center-head__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
		<?php endif; ?>
		<h2 class="nx-center-head__title"<?php echo $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>><?php echo esc_html( $title ); ?></h2>
		<?php if ( $text ) : ?>
			<p class="nx-center-head__text"><?php echo esc_html( $text ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Promotional banners that should show right now.
 *
 * @return array[] eyebrow, title, text, badge, code, label, url, image, ends.
 */
function nexora_promo_slides() {
	$slides = array();
	foreach ( array( 1, 2, 3 ) as $n ) {
		$title = trim( (string) nexora_option( "promo{$n}_title" ) );
		$start = nexora_datetime_to_timestamp( nexora_option( "promo{$n}_start" ) );
		$end   = nexora_datetime_to_timestamp( nexora_option( "promo{$n}_end" ) );
		if ( '' === $title || ( $start && $start > time() ) || ( $end && $end < time() ) ) {
			continue;
		}
		// The default third banner offers quotations, so it steps aside if they are switched off.
		$schema     = nexora_settings_schema();
		$is_default = (string) $schema[ "promo{$n}_title" ][2] === $title;
		if ( 3 === $n && $is_default && ! nexora_rfq_enabled() ) {
			continue;
		}
		$url = (string) nexora_option( "promo{$n}_cta_url" );
		if ( '' === $url && $is_default && nexora_has_store() ) {
			$url = 2 === $n ? nexora_shop_url() : ( 3 === $n ? nexora_rfq_url() : '' );
		}
		$image    = (int) nexora_option( "promo{$n}_image" );
		$slides[] = array(
			'eyebrow' => (string) nexora_option( "promo{$n}_eyebrow" ),
			'title'   => $title,
			'text'    => (string) nexora_option( "promo{$n}_text" ),
			'badge'   => (string) nexora_option( "promo{$n}_badge" ),
			'code'    => (string) nexora_option( "promo{$n}_code" ),
			'label'   => (string) nexora_option( "promo{$n}_cta_label" ),
			'url'     => $url,
			'image'   => $image ? $image : (int) nexora_option( 'hero_image' ),
			'ends'    => $end,
		);
	}
	return $slides;
}

/**
 * Social profile links from the Customizer.
 */
function nexora_social_links() {
	$networks = array(
		'facebook'  => 'Facebook',
		'instagram' => 'Instagram',
		'tiktok'    => 'TikTok',
		'youtube'   => 'YouTube',
		'x'         => 'X',
	);
	$out = '';
	foreach ( $networks as $key => $label ) {
		$url = nexora_option( 'social_' . $key );
		if ( $url ) {
			$out .= sprintf(
				'<li><a href="%1$s" target="_blank" rel="noopener me">%2$s<span class="screen-reader-text">%3$s</span></a></li>',
				esc_url( $url ),
				nexora_icon( $key, 18 ),
				/* translators: %s: social network */
				esc_html( sprintf( __( '%s (opens in a new tab)', 'nexora-commerce' ), $label ) )
			);
		}
	}
	if ( $out ) {
		echo '<ul class="nx-social">' . $out . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}

/**
 * Payment method labels for the footer. "auto" uses only gateways enabled in WooCommerce.
 *
 * @return string[]
 */
function nexora_payment_labels() {
	$mode = nexora_option( 'footer_payments' );
	if ( 'hidden' === $mode ) {
		return array();
	}
	if ( 'manual' === $mode ) {
		return array_filter( array_map( 'trim', explode( ',', (string) nexora_option( 'footer_payments_manual' ) ) ) );
	}
	if ( nexora_is_native_store() ) {
		return array_values( wp_list_pluck( Nexora_Store_Checkout::gateways(), 'title' ) );
	}
	if ( ! nexora_is_woocommerce_active() || ! WC()->payment_gateways() ) {
		return array();
	}
	$labels = array();
	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
		if ( 'yes' === $gateway->enabled ) {
			$labels[] = wp_strip_all_tags( $gateway->get_title() );
		}
	}
	return $labels;
}

/**
 * Copyright line.
 *
 * @return string
 */
function nexora_copyright() {
	$text = nexora_option( 'footer_copyright' );
	if ( '' === trim( (string) $text ) ) {
		/* translators: 1: year, 2: site name */
		$text = sprintf( __( '© %1$s %2$s. All rights reserved.', 'nexora-commerce' ), wp_date( 'Y' ), get_bloginfo( 'name' ) );
	}
	return $text;
}

/**
 * Post meta line.
 */
function nexora_posted_on() {
	printf(
		'<p class="nx-post-meta"><time datetime="%1$s">%2$s</time><span aria-hidden="true"> · </span><span>%3$s</span></p>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() ),
		/* translators: %d: minutes */
		esc_html( sprintf( _n( '%d min read', '%d min read', nexora_reading_time(), 'nexora-commerce' ), nexora_reading_time() ) )
	);
}

/**
 * Estimated reading time in minutes.
 *
 * @return int
 */
function nexora_reading_time() {
	$words = str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', get_the_ID() ) ) );
	return max( 1, (int) ceil( $words / 220 ) );
}

/**
 * Resolve a URL option with a fallback.
 *
 * @param string $key      Option key.
 * @param string $fallback Fallback URL.
 * @return string
 */
function nexora_url_option( $key, $fallback ) {
	$url = nexora_option( $key );
	return $url ? $url : $fallback;
}

/**
 * Shop URL helper that works without WooCommerce.
 *
 * @param array $args Query args.
 * @return string
 */
function nexora_shop_url( $args = array() ) {
	$base = nexora_is_woocommerce_active() ? wc_get_page_permalink( 'shop' ) : ( nexora_is_native_store() ? Nexora_Store::shop_url() : home_url( '/' ) );
	return $args ? add_query_arg( $args, $base ) : $base;
}

/**
 * Responsive attachment image with sensible defaults.
 *
 * @param int    $id    Attachment ID.
 * @param string $size  Size.
 * @param array  $attr  Attributes.
 * @return string
 */
function nexora_image( $id, $size = 'large', $attr = array() ) {
	if ( ! $id ) {
		return '';
	}
	return wp_get_attachment_image( $id, $size, false, $attr );
}

/**
 * Star rating markup. Uses WooCommerce's markup when available, otherwise the same structure
 * so the theme's star styles apply (e.g. testimonials while WooCommerce is inactive).
 *
 * @param float $rating Rating 0–5.
 * @return string
 */
function nexora_rating_html( $rating ) {
	if ( function_exists( 'wc_get_rating_html' ) ) {
		return wc_get_rating_html( $rating );
	}
	$rating = max( 0, min( 5, (float) $rating ) );
	/* translators: %s: rating */
	$label = sprintf( __( 'Rated %s out of 5', 'nexora-commerce' ), number_format_i18n( $rating, 1 ) );
	return sprintf( '<div class="star-rating" role="img" aria-label="%1$s"><span style="width:%2$s%%">%1$s</span></div>', esc_attr( $label ), esc_attr( (string) ( $rating / 5 * 100 ) ) );
}

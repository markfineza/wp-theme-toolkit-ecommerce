<?php
/**
 * Customizer settings. Every theme option is declared once in nexora_settings_schema()
 * and read through nexora_option().
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Homepage section keys and labels, in default order.
 *
 * @return array<string,string>
 */
function nexora_home_sections() {
	return apply_filters(
		'nexora_home_sections',
		array(
			'hero'         => __( 'Hero promotional banner', 'nexora-commerce' ),
			'categories'   => __( 'Shop by category', 'nexora-commerce' ),
			'product_tabs' => __( 'Products with tabs', 'nexora-commerce' ),
			'packages'     => __( 'Packages', 'nexora-commerce' ),
			'collection'   => __( 'Market basket collection', 'nexora-commerce' ),
			'faq'          => __( 'Questions and answers', 'nexora-commerce' ),
			'coupons'      => __( 'Coupons', 'nexora-commerce' ),
			'price_board'  => __( 'Price board', 'nexora-commerce' ),
			'featured'     => __( 'Featured products', 'nexora-commerce' ),
			'trending'     => __( 'Trending now', 'nexora-commerce' ),
			'campaign'     => __( 'Campaign banner', 'nexora-commerce' ),
			'rfq_banner'   => __( 'Request a quotation banner', 'nexora-commerce' ),
			'new_arrivals' => __( 'New arrivals', 'nexora-commerce' ),
			'best_sellers' => __( 'Best sellers', 'nexora-commerce' ),
			'benefits'     => __( 'Brand benefits', 'nexora-commerce' ),
			'discovery'    => __( 'Personalized discovery', 'nexora-commerce' ),
			'social_proof' => __( 'Reviews and testimonials', 'nexora-commerce' ),
			'newsletter'   => __( 'Newsletter', 'nexora-commerce' ),
			'editorial'    => __( 'Editorial / journal', 'nexora-commerce' ),
			'page_content' => __( 'Front page editor content', 'nexora-commerce' ),
			'final_cta'    => __( 'Final conversion banner', 'nexora-commerce' ),
		)
	);
}

/**
 * Declarative schema: id => [ section, type, default, label, (description), (choices) ].
 *
 * @return array
 */
function nexora_settings_schema() {
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$benefits = array(
		1 => array( 'leaf', __( 'Sourced from farms and ports', 'nexora-commerce' ), __( 'Produce from Benguet to Davao and fish from Navotas to Dagupan (demo sourcing).', 'nexora-commerce' ) ),
		2 => array( 'snow', __( 'Cold-chain packing', 'nexora-commerce' ), __( 'Meat, poultry and seafood are packed cold for pickup or your rider.', 'nexora-commerce' ) ),
		3 => array( 'scale', __( 'Honest weights', 'nexora-commerce' ), __( 'Per-kilo items are weighed at packing; short weights are refunded.', 'nexora-commerce' ) ),
		4 => array( 'clipboard', __( 'Bulk quotations', 'nexora-commerce' ), __( 'Carinderias, stores and restaurants get quotes for volume and regular orders.', 'nexora-commerce' ) ),
	);

	$schema = array(
		// Brand.
		'color_primary'       => array( 'nexora_brand', 'color', '#12306E', __( 'Primary accent', 'nexora-commerce' ) ),
		'color_secondary'     => array( 'nexora_brand', 'color', '#F0762B', __( 'Secondary accent', 'nexora-commerce' ) ),
		'color_text'          => array( 'nexora_brand', 'color', '#0F1B3D', __( 'Main text', 'nexora-commerce' ) ),
		'color_bg'            => array( 'nexora_brand', 'color', '#F5F7FB', __( 'Page background', 'nexora-commerce' ) ),
		'color_dark'          => array( 'nexora_brand', 'color', '#0A1A40', __( 'Dark sections', 'nexora-commerce' ) ),
		'color_sale'          => array( 'nexora_brand', 'color', '#DC2626', __( 'Sale accent', 'nexora-commerce' ) ),
		'font_heading'        => array( 'nexora_brand', 'select', 'inter', __( 'Heading font', 'nexora-commerce' ), '', array( 'manrope' => 'Manrope', 'inter' => 'Inter', 'system' => __( 'System UI', 'nexora-commerce' ) ) ),
		'font_body'           => array( 'nexora_brand', 'select', 'inter', __( 'Body font', 'nexora-commerce' ), '', array( 'inter' => 'Inter', 'manrope' => 'Manrope', 'system' => __( 'System UI', 'nexora-commerce' ) ) ),
		'radius'              => array( 'nexora_brand', 'number', 14, __( 'Corner radius (px)', 'nexora-commerce' ) ),

		// Announcement bar.
		'announcement_enabled'     => array( 'nexora_announcement', 'checkbox', true, __( 'Show announcement bar', 'nexora-commerce' ) ),
		'announcement_text'        => array( 'nexora_announcement', 'text', __( 'Order online, then pick up at the market or book your own rider · Choose your time at checkout', 'nexora-commerce' ), __( 'Message', 'nexora-commerce' ) ),
		'announcement_link_label'  => array( 'nexora_announcement', 'text', __( "Today's deals", 'nexora-commerce' ), __( 'Link label', 'nexora-commerce' ) ),
		'announcement_link_url'    => array( 'nexora_announcement', 'url', '', __( 'Link URL', 'nexora-commerce' ), __( 'Leave empty to link to the shop’s on-sale filter.', 'nexora-commerce' ) ),
		'announcement_countdown'   => array( 'nexora_announcement', 'datetime', '', __( 'Countdown ends (site time)', 'nexora-commerce' ), __( 'Optional. Use only for a real promotion deadline. The countdown and bar hide automatically once it passes.', 'nexora-commerce' ) ),
		'announcement_dismissible' => array( 'nexora_announcement', 'checkbox', true, __( 'Visitors can dismiss the bar', 'nexora-commerce' ) ),

		// Header.
		'header_sticky'       => array( 'nexora_header', 'checkbox', true, __( 'Sticky header', 'nexora-commerce' ) ),
		'header_search'       => array( 'nexora_header', 'checkbox', true, __( 'Show product search', 'nexora-commerce' ) ),
		'header_mega_menu'    => array( 'nexora_header', 'checkbox', true, __( 'Show category mega menu', 'nexora-commerce' ) ),
		'header_rfq'          => array( 'nexora_header', 'checkbox', true, __( 'Show “Request a Quote” button', 'nexora-commerce' ) ),
		'ticker_enabled'      => array( 'nexora_header', 'checkbox', true, __( 'Show price ticker under the header', 'nexora-commerce' ), __( 'Lists products whose price changed most recently (real price history recorded by the Freshtastic Food Hub Toolkit).', 'nexora-commerce' ) ),
		'ticker_limit'        => array( 'nexora_header', 'number', 14, __( 'Ticker: number of items', 'nexora-commerce' ) ),
		'market_open'         => array( 'nexora_header', 'text', '04:00', __( 'Market opens (24h, site time)', 'nexora-commerce' ), __( 'Shown as a live “Market open / closed” status. Leave empty to hide.', 'nexora-commerce' ) ),
		'market_close'        => array( 'nexora_header', 'text', '20:00', __( 'Market closes (24h, site time)', 'nexora-commerce' ) ),

		// Homepage: layout.
		'home_layout'         => array( 'nexora_home_layout', 'sections', 'hero,categories,product_tabs,packages,price_board,collection,social_proof,faq,coupons,rfq_banner,editorial,benefits,-featured,-trending,-campaign,-new_arrivals,-best_sellers,-discovery,-newsletter,-page_content,-final_cta', __( 'Sections and order', 'nexora-commerce' ), __( 'Tick to show a section; use the arrows to reorder.', 'nexora-commerce' ) ),

		// Homepage: hero.
		'hero_image'          => array( 'nexora_home_hero', 'image', 0, __( 'Fallback banner image', 'nexora-commerce' ), __( 'Used by any banner without its own image. With no image at all, the banner shows only its background.', 'nexora-commerce' ) ),

		// Homepage: product sections.
		'price_board_title'   => array( 'nexora_home_products', 'text', __( "Today's price board", 'nexora-commerce' ), __( 'Price board: title', 'nexora-commerce' ) ),
		'price_board_limit'   => array( 'nexora_home_products', 'number', 14, __( 'Price board: number of items', 'nexora-commerce' ) ),
		'categories_title'    => array( 'nexora_home_products', 'text', __( 'Shop by category', 'nexora-commerce' ), __( 'Categories: title', 'nexora-commerce' ) ),
		'home_categories'     => array( 'nexora_home_products', 'text', '', __( 'Categories: slugs to show', 'nexora-commerce' ), __( 'Comma-separated product category slugs. Empty shows top-level categories.', 'nexora-commerce' ) ),
		'featured_title'      => array( 'nexora_home_products', 'text', __( "Today's picks", 'nexora-commerce' ), __( 'Featured: title', 'nexora-commerce' ) ),
		'featured_limit'      => array( 'nexora_home_products', 'number', 8, __( 'Featured: number of products', 'nexora-commerce' ) ),
		'trending_title'      => array( 'nexora_home_products', 'text', __( 'Trending now', 'nexora-commerce' ), __( 'Trending: title', 'nexora-commerce' ) ),
		'trending_source'     => array( 'nexora_home_products', 'select', 'top_rated', __( 'Trending: source', 'nexora-commerce' ), '', array( 'top_rated' => __( 'Top rated', 'nexora-commerce' ), 'best_selling' => __( 'Best selling', 'nexora-commerce' ), 'on_sale' => __( 'On sale', 'nexora-commerce' ), 'manual' => __( 'Manual product IDs', 'nexora-commerce' ) ) ),
		'trending_ids'        => array( 'nexora_home_products', 'text', '', __( 'Trending: manual product IDs', 'nexora-commerce' ), __( 'Comma-separated IDs, used when the source is Manual.', 'nexora-commerce' ) ),
		'trending_limit'      => array( 'nexora_home_products', 'number', 4, __( 'Trending: number of products', 'nexora-commerce' ) ),
		'new_title'           => array( 'nexora_home_products', 'text', __( 'Fresh arrivals', 'nexora-commerce' ), __( 'New arrivals: title', 'nexora-commerce' ) ),
		'new_limit'           => array( 'nexora_home_products', 'number', 8, __( 'New arrivals: number of products', 'nexora-commerce' ) ),
		'best_title'          => array( 'nexora_home_products', 'text', __( 'Most traded', 'nexora-commerce' ), __( 'Best sellers: title', 'nexora-commerce' ) ),
		'best_limit'          => array( 'nexora_home_products', 'number', 4, __( 'Best sellers: number of products', 'nexora-commerce' ) ),
		'discovery_title'     => array( 'nexora_home_products', 'text', __( 'Picked for your basket', 'nexora-commerce' ), __( 'Discovery: title', 'nexora-commerce' ) ),

		// Homepage: campaign banner.
		'campaign_eyebrow'    => array( 'nexora_home_campaign', 'text', __( 'Dry market', 'nexora-commerce' ), __( 'Eyebrow', 'nexora-commerce' ) ),
		'campaign_title'      => array( 'nexora_home_campaign', 'text', __( 'Stock up by the sack.', 'nexora-commerce' ), __( 'Headline', 'nexora-commerce' ) ),
		'campaign_text'       => array( 'nexora_home_campaign', 'textarea', __( 'Rice, sugar and cooking oil in bulk sizes with the lowest price per kilo — for households, carinderias and sari-sari stores.', 'nexora-commerce' ), __( 'Copy', 'nexora-commerce' ) ),
		'campaign_cta_label'  => array( 'nexora_home_campaign', 'text', __( 'Shop the dry market', 'nexora-commerce' ), __( 'Button label', 'nexora-commerce' ) ),
		'campaign_cta_url'    => array( 'nexora_home_campaign', 'url', '', __( 'Button URL', 'nexora-commerce' ), __( 'Defaults to the Dry Market category when it exists.', 'nexora-commerce' ) ),
		'campaign_image'      => array( 'nexora_home_campaign', 'image', 0, __( 'Background image', 'nexora-commerce' ) ),
		'campaign_start'      => array( 'nexora_home_campaign', 'datetime', '', __( 'Show from (optional)', 'nexora-commerce' ) ),
		'campaign_end'        => array( 'nexora_home_campaign', 'datetime', '', __( 'Hide after (optional)', 'nexora-commerce' ) ),

		// Homepage: content blocks.
		'rfq_title'           => array( 'nexora_home_content', 'text', __( 'Can’t find it on the board? Request a quotation.', 'nexora-commerce' ), __( 'Quotation banner: title', 'nexora-commerce' ) ),
		'rfq_text'            => array( 'nexora_home_content', 'textarea', __( 'Out-of-season items, bulk volumes or regular deliveries for your business — tell us what you need and we will source it and send a quote.', 'nexora-commerce' ), __( 'Quotation banner: copy', 'nexora-commerce' ) ),
		'social_title'        => array( 'nexora_home_content', 'text', __( 'Trusted by kitchens and stores', 'nexora-commerce' ), __( 'Reviews: title', 'nexora-commerce' ) ),
		'newsletter_title'    => array( 'nexora_home_content', 'text', __( 'Get weekly price alerts', 'nexora-commerce' ), __( 'Newsletter: title', 'nexora-commerce' ) ),
		'newsletter_text'     => array( 'nexora_home_content', 'textarea', __( 'Price drops, restocks and seasonal produce in one short email a week — unsubscribe anytime.', 'nexora-commerce' ), __( 'Newsletter: copy', 'nexora-commerce' ) ),
		'editorial_title'     => array( 'nexora_home_content', 'text', __( 'Market journal', 'nexora-commerce' ), __( 'Editorial: title', 'nexora-commerce' ) ),
		'editorial_count'     => array( 'nexora_home_content', 'number', 3, __( 'Editorial: number of posts', 'nexora-commerce' ) ),
		'final_cta_title'     => array( 'nexora_home_content', 'text', __( 'Your weekly market run, sorted.', 'nexora-commerce' ), __( 'Final banner: headline', 'nexora-commerce' ) ),
		'final_cta_text'      => array( 'nexora_home_content', 'textarea', __( 'Browse the full market or catch this week’s price drops before they are gone.', 'nexora-commerce' ), __( 'Final banner: copy', 'nexora-commerce' ) ),
		'final_cta_label'     => array( 'nexora_home_content', 'text', __( 'Browse the market', 'nexora-commerce' ), __( 'Final banner: button label', 'nexora-commerce' ) ),
		'final_cta_url'       => array( 'nexora_home_content', 'url', '', __( 'Final banner: button URL', 'nexora-commerce' ) ),
		'final_cta_image'     => array( 'nexora_home_content', 'image', 0, __( 'Final banner: image', 'nexora-commerce' ) ),

		// Shop and products.
		'free_shipping_threshold' => array( 'nexora_shop', 'number', 1500, __( 'Free-shipping threshold (store currency)', 'nexora-commerce' ), __( 'Display only — set the same minimum on your WooCommerce “Free shipping” method. Use 0 to hide the progress bar.', 'nexora-commerce' ) ),
		'card_style'          => array( 'nexora_shop', 'select', 'elevated', __( 'Product card style', 'nexora-commerce' ), '', array( 'elevated' => __( 'Elevated', 'nexora-commerce' ), 'minimal' => __( 'Minimal', 'nexora-commerce' ) ) ),
		'card_hover_image'    => array( 'nexora_shop', 'checkbox', true, __( 'Show first gallery image on hover', 'nexora-commerce' ) ),
		'card_rating'         => array( 'nexora_shop', 'checkbox', true, __( 'Show ratings on cards', 'nexora-commerce' ) ),
		'card_quick_add'      => array( 'nexora_shop', 'checkbox', true, __( 'Quick add-to-cart on cards', 'nexora-commerce' ) ),
		'badge_new_days'      => array( 'nexora_shop', 'number', 30, __( '“New” badge: product age in days', 'nexora-commerce' ), __( 'Use 0 to disable the New badge.', 'nexora-commerce' ) ),
		'badge_discount'      => array( 'nexora_shop', 'checkbox', true, __( 'Show discount percentage on sale badges', 'nexora-commerce' ) ),
		'low_stock_threshold' => array( 'nexora_shop', 'number', 5, __( 'Low-stock message threshold', 'nexora-commerce' ), __( 'Shows “Only N left” when managed stock is at or below this number. Use 0 to disable.', 'nexora-commerce' ) ),
		'sticky_atc'          => array( 'nexora_shop', 'checkbox', true, __( 'Sticky add-to-cart bar on product pages', 'nexora-commerce' ) ),
		'buy_now'             => array( 'nexora_shop', 'checkbox', true, __( 'Show “Buy now” button', 'nexora-commerce' ) ),
		'product_trust'       => array( 'nexora_shop', 'textarea', __( "Weighed at packing — short weights are refunded|scale\nMeat and seafood packed cold|snow\nPick up at the store or book your own rider|store", 'nexora-commerce' ), __( 'Product page trust lines', 'nexora-commerce' ), __( 'One per line as “Text|icon”. Icons: truck, return, shield, chat, sparkle, tag, scale, snow, leaf, chart, clipboard, store.', 'nexora-commerce' ) ),

		// Store info and social.
		'store_phone'         => array( 'nexora_store', 'text', '+63 900 000 0000 (demo)', __( 'Phone', 'nexora-commerce' ) ),
		'store_email'         => array( 'nexora_store', 'text', 'orders@freshtastic.example', __( 'Email', 'nexora-commerce' ) ),
		'store_address'       => array( 'nexora_store', 'textarea', __( 'Demo address — Stall 12, Sample Public Market, Quezon City', 'nexora-commerce' ), __( 'Address', 'nexora-commerce' ) ),
		'store_hours'         => array( 'nexora_store', 'text', __( 'Daily, 4 AM–8 PM (PHT)', 'nexora-commerce' ), __( 'Support hours', 'nexora-commerce' ) ),
		'social_facebook'     => array( 'nexora_store', 'url', '', 'Facebook URL' ),
		'social_instagram'    => array( 'nexora_store', 'url', '', 'Instagram URL' ),
		'social_tiktok'       => array( 'nexora_store', 'url', '', 'TikTok URL' ),
		'social_youtube'      => array( 'nexora_store', 'url', '', 'YouTube URL' ),
		'social_x'            => array( 'nexora_store', 'url', '', 'X (Twitter) URL' ),

		// Footer.
		'footer_about'        => array( 'nexora_footer', 'textarea', __( 'Freshtastic Food Hub by JEJOBO brings fresh meat, fish, vegetables, frozen goods and more to your table. Freshness you can always trust. This is a demo storefront; replace this text with your story.', 'nexora-commerce' ), __( 'Brand introduction', 'nexora-commerce' ) ),
		'footer_payments'     => array( 'nexora_footer', 'select', 'auto', __( 'Payment methods display', 'nexora-commerce' ), __( 'Automatic lists only the WooCommerce gateways that are enabled, so customers never see a method you do not accept.', 'nexora-commerce' ), array( 'auto' => __( 'Automatic (enabled gateways)', 'nexora-commerce' ), 'manual' => __( 'Manual list', 'nexora-commerce' ), 'hidden' => __( 'Hidden', 'nexora-commerce' ) ) ),
		'footer_payments_manual' => array( 'nexora_footer', 'text', '', __( 'Manual payment list', 'nexora-commerce' ), __( 'Comma-separated, e.g. “GCash, Maya, Visa, Mastercard”. Only list methods you actually accept.', 'nexora-commerce' ) ),
		'footer_copyright'    => array( 'nexora_footer', 'text', '', __( 'Copyright text', 'nexora-commerce' ), __( 'Defaults to “© {year} Site name. All rights reserved.”', 'nexora-commerce' ) ),

		// Performance.
		'webp_subsizes'       => array( 'nexora_performance', 'checkbox', true, __( 'Generate WebP image sizes for new JPEG uploads (when the server supports WebP)', 'nexora-commerce' ) ),
		'load_local_fonts'    => array( 'nexora_performance', 'checkbox', true, __( 'Load bundled Inter/Manrope font files (disable to use system fonts only)', 'nexora-commerce' ) ),
	);

	foreach ( $benefits as $i => $b ) {
		$schema[ "benefit_{$i}_icon" ]  = array( 'nexora_home_content', 'select', $b[0], sprintf( /* translators: %d: benefit number */ __( 'Benefit %d: icon', 'nexora-commerce' ), $i ), '', nexora_icon_choices() );
		$schema[ "benefit_{$i}_title" ] = array( 'nexora_home_content', 'text', $b[1], sprintf( /* translators: %d: benefit number */ __( 'Benefit %d: title', 'nexora-commerce' ), $i ) );
		$schema[ "benefit_{$i}_text" ]  = array( 'nexora_home_content', 'textarea', $b[2], sprintf( /* translators: %d: benefit number */ __( 'Benefit %d: text', 'nexora-commerce' ), $i ) );
	}

	$schema = apply_filters( 'nexora_settings_schema', $schema );
	// Promotional banners. A banner shows when it has a headline and its dates allow it.
	$promo_defaults = array(
		1 => array( __( 'This week at the market', 'nexora-commerce' ), __( 'Fresh deals on meat, fish and vegetables', 'nexora-commerce' ), __( 'Order online, then pick up at the market or book your own rider. See everything marked down today.', 'nexora-commerce' ), __( 'On sale now', 'nexora-commerce' ), __( 'Shop today’s deals', 'nexora-commerce' ) ),
		2 => array( __( 'Dry goods and staples', 'nexora-commerce' ), __( 'Stock up on rice and pantry staples', 'nexora-commerce' ), __( 'Everything for the week in one order, ready for pickup or your own rider.', 'nexora-commerce' ), '', __( 'Shop the market', 'nexora-commerce' ) ),
		3 => array( __( 'For carinderias, stores and restaurants', 'nexora-commerce' ), __( 'Buying in bulk? Request a quote', 'nexora-commerce' ), __( 'Tell us what you need and we will get back to you with a price.', 'nexora-commerce' ), '', __( 'Request a quote', 'nexora-commerce' ) ),
	);
	foreach ( array( 1, 2, 3 ) as $n ) {
		/* translators: %d: banner number */
		$label = sprintf( __( 'Banner %d', 'nexora-commerce' ), $n ) . ': ';
		$default = isset( $promo_defaults[ $n ] ) ? $promo_defaults[ $n ] : array( '', '', '', '', '' );
		$schema[ "promo{$n}_eyebrow" ]   = array( 'nexora_home_hero', 'text', $default[0], $label . __( 'small text above the headline', 'nexora-commerce' ) );
		$schema[ "promo{$n}_title" ]     = array( 'nexora_home_hero', 'text', $default[1], $label . __( 'headline', 'nexora-commerce' ), __( 'Leave empty to hide this banner.', 'nexora-commerce' ) );
		$schema[ "promo{$n}_text" ]      = array( 'nexora_home_hero', 'textarea', $default[2], $label . __( 'text', 'nexora-commerce' ) );
		$schema[ "promo{$n}_badge" ]     = array( 'nexora_home_hero', 'text', $default[3], $label . __( 'badge', 'nexora-commerce' ), __( 'For example “Up to 20% off”. Only promise what your prices or coupons really give.', 'nexora-commerce' ) );
		$schema[ "promo{$n}_code" ]      = array( 'nexora_home_hero', 'text', '', $label . __( 'coupon code', 'nexora-commerce' ), __( 'Optional. Shown with a copy button. The coupon itself is created under Coupons.', 'nexora-commerce' ) );
		$schema[ "promo{$n}_cta_label" ] = array( 'nexora_home_hero', 'text', $default[4], $label . __( 'button label', 'nexora-commerce' ) );
		$schema[ "promo{$n}_cta_url" ]   = array( 'nexora_home_hero', 'url', '', $label . __( 'button link', 'nexora-commerce' ), __( 'Empty = products on sale.', 'nexora-commerce' ) );
		$schema[ "promo{$n}_image" ]     = array( 'nexora_home_hero', 'image', 0, $label . __( 'image', 'nexora-commerce' ) );
		$schema[ "promo{$n}_start" ]     = array( 'nexora_home_hero', 'datetime', '', $label . __( 'show from (optional)', 'nexora-commerce' ) );
		$schema[ "promo{$n}_end" ]       = array( 'nexora_home_hero', 'datetime', '', $label . __( 'hide after (optional)', 'nexora-commerce' ), __( 'Also shows a countdown on the banner.', 'nexora-commerce' ) );
	}

	$schema['tabs_title']        = array( 'nexora_home_tabs', 'text', __( 'Fresh from the market', 'nexora-commerce' ), __( 'Heading', 'nexora-commerce' ) );
	$schema['tabs_limit']        = array( 'nexora_home_tabs', 'number', 8, __( 'Products per tab', 'nexora-commerce' ) );
	$schema['tabs_card_title']   = array( 'nexora_home_tabs', 'text', __( 'Buying in bulk?', 'nexora-commerce' ), __( 'Side card: headline', 'nexora-commerce' ) );
	$schema['tabs_card_big']     = array( 'nexora_home_tabs', 'text', __( 'Get a quote', 'nexora-commerce' ), __( 'Side card: large text', 'nexora-commerce' ) );
	$schema['tabs_card_text']    = array( 'nexora_home_tabs', 'textarea', __( 'For carinderias, stores and restaurants, or anything not on the list.', 'nexora-commerce' ), __( 'Side card: text', 'nexora-commerce' ) );
	$schema['tabs_card_label']   = array( 'nexora_home_tabs', 'text', __( 'Request a quote', 'nexora-commerce' ), __( 'Side card: button label', 'nexora-commerce' ) );
	$schema['tabs_card_url']     = array( 'nexora_home_tabs', 'url', '', __( 'Side card: button link', 'nexora-commerce' ), __( 'Empty = the Request a Quote page.', 'nexora-commerce' ) );

	$schema['packages_eyebrow'] = array( 'nexora_home_packages', 'text', __( 'Bundled and ready', 'nexora-commerce' ), __( 'Small text above the heading', 'nexora-commerce' ) );
	$schema['packages_title']   = array( 'nexora_home_packages', 'text', __( 'Choose your package', 'nexora-commerce' ), __( 'Heading', 'nexora-commerce' ) );
	$schema['packages_text']    = array( 'nexora_home_packages', 'text', '', __( 'Text under the heading', 'nexora-commerce' ) );
	$schema['packages_limit']   = array( 'nexora_home_packages', 'number', 6, __( 'Packages to show', 'nexora-commerce' ) );

	$schema['basket_eyebrow']    = array( 'nexora_home_basket', 'text', __( 'Hand-picked for your table', 'nexora-commerce' ), __( 'Small text above the heading', 'nexora-commerce' ) );
	$schema['basket_title']      = array( 'nexora_home_basket', 'text', __( 'Build your market basket', 'nexora-commerce' ), __( 'Heading', 'nexora-commerce' ) );
	$schema['basket_card_small'] = array( 'nexora_home_basket', 'text', __( 'Weekly staples', 'nexora-commerce' ), __( 'Large card: small text', 'nexora-commerce' ) );
	$schema['basket_card_title'] = array( 'nexora_home_basket', 'text', __( 'Shop the full market', 'nexora-commerce' ), __( 'Large card: title', 'nexora-commerce' ) );
	$schema['basket_card_url']   = array( 'nexora_home_basket', 'url', '', __( 'Large card: link', 'nexora-commerce' ), __( 'Empty = the shop page.', 'nexora-commerce' ) );
	$schema['basket_image']      = array( 'nexora_home_basket', 'image', 0, __( 'Large card: image', 'nexora-commerce' ), __( 'Empty = the campaign banner image.', 'nexora-commerce' ) );

	$schema['faq_eyebrow'] = array( 'nexora_home_faq', 'text', __( 'Questions', 'nexora-commerce' ), __( 'Small text above the heading', 'nexora-commerce' ) );
	$schema['faq_title']   = array( 'nexora_home_faq', 'text', __( 'How buying from us works', 'nexora-commerce' ), __( 'Heading', 'nexora-commerce' ) );
	$schema['faq_text']    = array( 'nexora_home_faq', 'textarea', __( 'Everything you need to know before your first order. Still unsure? Send us a message.', 'nexora-commerce' ), __( 'Text', 'nexora-commerce' ) );
	$faq = array(
		1 => array( __( 'How do I get my order?', 'nexora-commerce' ), __( 'Choose store pickup, or book your own courier such as Lalamove or Grab. You pick the time at checkout, and we email you when your order is packed.', 'nexora-commerce' ) ),
		2 => array( __( 'How can I pay?', 'nexora-commerce' ), __( 'The payment options available for your order are shown at checkout.', 'nexora-commerce' ) ),
		3 => array( __( 'Can I buy in bulk or ask for something not listed?', 'nexora-commerce' ), __( 'Yes. Send a quotation request with what you need and we will reply with a price.', 'nexora-commerce' ) ),
		4 => array( __( 'Do I need an account?', 'nexora-commerce' ), __( 'An account lets you track orders, save your address and use member coupons.', 'nexora-commerce' ) ),
	);
	foreach ( array( 1, 2, 3, 4, 5, 6 ) as $n ) {
		/* translators: %d: question number */
		$schema[ "faq{$n}_q" ] = array( 'nexora_home_faq', 'text', isset( $faq[ $n ] ) ? $faq[ $n ][0] : '', sprintf( __( 'Question %d', 'nexora-commerce' ), $n ) );
		/* translators: %d: answer number */
		$schema[ "faq{$n}_a" ] = array( 'nexora_home_faq', 'textarea', isset( $faq[ $n ] ) ? $faq[ $n ][1] : '', sprintf( __( 'Answer %d', 'nexora-commerce' ), $n ) );
	}

	$schema['coupons_title'] = array( 'nexora_home_coupons', 'text', __( 'Save more with coupons', 'nexora-commerce' ), __( 'Heading', 'nexora-commerce' ), __( 'This section only appears when a coupon has “Show this code on the homepage” ticked.', 'nexora-commerce' ) );
	$schema['coupons_text']  = array( 'nexora_home_coupons', 'text', __( 'Copy a code and enter it at checkout.', 'nexora-commerce' ), __( 'Text', 'nexora-commerce' ) );

	return $schema;
}

/**
 * One-time move to the 2.5 homepage. A layout saved earlier would otherwise keep the old order, so the new
 * design would not show. Section contents and all other settings are kept.
 */
function nexora_migrate_home_layout() {
	$version = (int) get_option( 'nexora_home_layout_v' );
	if ( $version < 25 ) {
		remove_theme_mod( 'nexora_home_layout' );
	} elseif ( $version < 27 ) {
		// Keep the owner's order and slot the new section in after the product tabs.
		$layout = (string) get_theme_mod( 'nexora_home_layout', '' );
		if ( '' !== $layout && false === strpos( $layout, 'packages' ) ) {
			$tokens = explode( ',', $layout );
			$after  = array_search( 'product_tabs', $tokens, true );
			array_splice( $tokens, false === $after ? 1 : $after + 1, 0, 'packages' );
			set_theme_mod( 'nexora_home_layout', implode( ',', $tokens ) );
		}
	}
	if ( $version < 27 ) {
		update_option( 'nexora_home_layout_v', 27 );
	}
}
add_action( 'after_setup_theme', 'nexora_migrate_home_layout', 20 );

/**
 * Icons selectable for benefits/trust lines.
 *
 * @return array<string,string>
 */
function nexora_icon_choices() {
	return array(
		'shield'  => __( 'Shield', 'nexora-commerce' ),
		'sparkle' => __( 'Sparkle', 'nexora-commerce' ),
		'chat'    => __( 'Chat', 'nexora-commerce' ),
		'truck'   => __( 'Truck', 'nexora-commerce' ),
		'return'  => __( 'Return arrow', 'nexora-commerce' ),
		'tag'     => __( 'Tag', 'nexora-commerce' ),
		'heart'   => __( 'Heart', 'nexora-commerce' ),
		'star'    => __( 'Star', 'nexora-commerce' ),
		'scale'   => __( 'Scale', 'nexora-commerce' ),
		'snow'    => __( 'Snowflake', 'nexora-commerce' ),
		'leaf'    => __( 'Leaf', 'nexora-commerce' ),
		'chart'   => __( 'Chart', 'nexora-commerce' ),
		'clipboard' => __( 'Clipboard', 'nexora-commerce' ),
		'store'   => __( 'Market stall', 'nexora-commerce' ),
	);
}

/**
 * Read a theme option with its schema default.
 *
 * @param string $key Option key.
 * @return mixed
 */
function nexora_option( $key ) {
	$schema  = nexora_settings_schema();
	$default = isset( $schema[ $key ] ) ? $schema[ $key ][2] : null;
	return apply_filters( "nexora_option_{$key}", get_theme_mod( 'nexora_' . $key, $default ) );
}

/**
 * Sanitize a section-order string: comma list of known keys, disabled ones prefixed with "-".
 *
 * @param string $value Raw value.
 * @return string
 */
function nexora_sanitize_sections( $value ) {
	$known = array_keys( nexora_home_sections() );
	$out   = array();
	foreach ( explode( ',', (string) $value ) as $item ) {
		$item = trim( $item );
		$key  = ltrim( $item, '-' );
		if ( in_array( $key, $known, true ) && ! in_array( $key, array_map( 'nexora_section_key', $out ), true ) ) {
			$out[] = $item;
		}
	}
	// Append sections added later (e.g. by a child theme) as enabled.
	foreach ( $known as $key ) {
		if ( ! in_array( $key, array_map( 'nexora_section_key', $out ), true ) ) {
			$out[] = $key;
		}
	}
	return implode( ',', $out );
}

/**
 * Strip the disabled marker from a section token.
 *
 * @param string $token Token.
 * @return string
 */
function nexora_section_key( $token ) {
	return ltrim( $token, '-' );
}

/**
 * Enabled homepage sections, in order.
 *
 * @return string[]
 */
function nexora_enabled_home_sections() {
	$tokens = explode( ',', nexora_sanitize_sections( nexora_option( 'home_layout' ) ) );
	return array_values( array_filter( $tokens, static function ( $t ) {
		return '' !== $t && '-' !== $t[0];
	} ) );
}

/**
 * Sanitize a datetime-local value (Y-m-d\TH:i).
 *
 * @param string $value Raw value.
 * @return string
 */
function nexora_sanitize_datetime( $value ) {
	$value = trim( (string) $value );
	return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
}

/**
 * Convert a site-timezone datetime-local value to a UTC timestamp.
 *
 * @param string $value Datetime-local string.
 * @return int|null
 */
function nexora_datetime_to_timestamp( $value ) {
	if ( '' === (string) $value ) {
		return null;
	}
	$date = date_create_immutable_from_format( 'Y-m-d\TH:i', $value, wp_timezone() );
	return $date ? $date->getTimestamp() : null;
}

/**
 * Sanitizers keyed by control type.
 *
 * @param string $type Type.
 * @return callable
 */
function nexora_sanitizer_for( $type ) {
	switch ( $type ) {
		case 'color':
			return 'sanitize_hex_color';
		case 'checkbox':
			return static function ( $v ) {
				return (bool) $v;
			};
		case 'number':
			return static function ( $v ) {
				return (int) $v;
			};
		case 'image':
			return 'absint';
		case 'url':
			return 'esc_url_raw';
		case 'textarea':
			return 'sanitize_textarea_field';
		case 'sections':
			return 'nexora_sanitize_sections';
		case 'datetime':
			return 'nexora_sanitize_datetime';
		default:
			return 'sanitize_text_field';
	}
}

/**
 * Register panels, sections, settings and controls.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function nexora_customize_register( $wp_customize ) {
	require_once NEXORA_DIR . '/inc/class-nexora-sections-control.php';

	$wp_customize->add_panel( 'nexora_panel', array( 'title' => __( 'Freshtastic Theme', 'nexora-commerce' ), 'priority' => 25 ) );
	$wp_customize->add_panel( 'nexora_home_panel', array( 'title' => __( 'Freshtastic Homepage', 'nexora-commerce' ), 'priority' => 26 ) );

	$sections = array(
		'nexora_brand'         => array( 'nexora_panel', __( 'Brand colors and typography', 'nexora-commerce' ) ),
		'nexora_announcement'  => array( 'nexora_panel', __( 'Announcement bar', 'nexora-commerce' ) ),
		'nexora_header'        => array( 'nexora_panel', __( 'Header', 'nexora-commerce' ) ),
		'nexora_shop'          => array( 'nexora_panel', __( 'Shop and product pages', 'nexora-commerce' ) ),
		'nexora_store'         => array( 'nexora_panel', __( 'Store info and social links', 'nexora-commerce' ) ),
		'nexora_footer'        => array( 'nexora_panel', __( 'Footer', 'nexora-commerce' ) ),
		'nexora_performance'   => array( 'nexora_panel', __( 'Performance', 'nexora-commerce' ) ),
		'nexora_home_layout'   => array( 'nexora_home_panel', __( 'Sections and order', 'nexora-commerce' ) ),
		'nexora_home_hero'     => array( 'nexora_home_panel', __( 'Hero promotional banner', 'nexora-commerce' ) ),
		'nexora_home_tabs'     => array( 'nexora_home_panel', __( 'Products with tabs', 'nexora-commerce' ) ),
		'nexora_home_packages' => array( 'nexora_home_panel', __( 'Packages', 'nexora-commerce' ) ),
		'nexora_home_basket'   => array( 'nexora_home_panel', __( 'Market basket collection', 'nexora-commerce' ) ),
		'nexora_home_faq'      => array( 'nexora_home_panel', __( 'Questions and answers', 'nexora-commerce' ) ),
		'nexora_home_coupons'  => array( 'nexora_home_panel', __( 'Coupons', 'nexora-commerce' ) ),
		'nexora_home_products' => array( 'nexora_home_panel', __( 'Product sections', 'nexora-commerce' ) ),
		'nexora_home_campaign' => array( 'nexora_home_panel', __( 'Campaign banner', 'nexora-commerce' ) ),
		'nexora_home_content'  => array( 'nexora_home_panel', __( 'Benefits, newsletter and banners', 'nexora-commerce' ) ),
	);
	foreach ( $sections as $id => $def ) {
		$wp_customize->add_section( $id, array( 'title' => $def[1], 'panel' => $def[0] ) );
	}

	foreach ( nexora_settings_schema() as $key => $def ) {
		list( $section, $type, $default, $label ) = $def;
		$description = isset( $def[4] ) ? $def[4] : '';
		$choices     = isset( $def[5] ) ? $def[5] : array();
		$setting_id  = 'nexora_' . $key;

		$wp_customize->add_setting(
			$setting_id,
			array(
				'default'           => $default,
				'sanitize_callback' => nexora_sanitizer_for( $type ),
				'transport'         => 'refresh',
			)
		);

		$args = array(
			'label'       => $label,
			'description' => $description,
			'section'     => $section,
		);

		if ( 'color' === $type ) {
			$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $setting_id, $args ) );
		} elseif ( 'image' === $type ) {
			$wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, $setting_id, array_merge( $args, array( 'mime_type' => 'image' ) ) ) );
		} elseif ( 'sections' === $type ) {
			$wp_customize->add_control( new Nexora_Sections_Control( $wp_customize, $setting_id, array_merge( $args, array( 'choices' => nexora_home_sections() ) ) ) );
		} else {
			$control_type = 'datetime' === $type ? 'datetime-local' : $type;
			$wp_customize->add_control( $setting_id, array_merge( $args, array( 'type' => $control_type, 'choices' => $choices ) ) );
		}
	}
}
add_action( 'customize_register', 'nexora_customize_register' );

/**
 * Output CSS custom properties from brand options.
 *
 * @return string
 */
function nexora_brand_css() {
	$fonts = array(
		'inter'   => '"Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
		'manrope' => '"Manrope", "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
		'system'  => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
	);
	$heading = nexora_option( 'font_heading' );
	$body    = nexora_option( 'font_body' );
	if ( ! nexora_option( 'load_local_fonts' ) ) {
		$heading = 'system';
		$body    = 'system';
	}

	$vars = array(
		'--nx-primary'      => nexora_option( 'color_primary' ),
		'--nx-secondary'    => nexora_option( 'color_secondary' ),
		'--nx-text'         => nexora_option( 'color_text' ),
		'--nx-bg'           => nexora_option( 'color_bg' ),
		'--nx-dark'         => nexora_option( 'color_dark' ),
		'--nx-sale'         => nexora_option( 'color_sale' ),
		'--nx-radius'       => max( 0, (int) nexora_option( 'radius' ) ) . 'px',
		'--nx-font-heading' => isset( $fonts[ $heading ] ) ? $fonts[ $heading ] : $fonts['manrope'],
		'--nx-font-body'    => isset( $fonts[ $body ] ) ? $fonts[ $body ] : $fonts['inter'],
	);

	$css = ':root{';
	foreach ( $vars as $prop => $val ) {
		if ( '' !== (string) $val ) {
			$css .= $prop . ':' . $val . ';';
		}
	}
	return $css . '}';
}

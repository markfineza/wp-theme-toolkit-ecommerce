/**
 * Freshtastic Toolkit — event dispatcher for GTM (dataLayer), gtag (GA4/Ads) and Meta Pixel.
 *
 * Consent: Google tags honour Consent Mode (defaults set in <head>). Meta Pixel starts revoked
 * and is granted when the consent tool calls:
 *   window.nexoraConsent.update({ analytics: true, marketing: true })
 * or, with the WP Consent API option, when the consent plugin reports marketing consent.
 */
( function () {
	'use strict';

	var cfg = window.nexoraTracking || { channels: {}, events: [] };
	var ch = cfg.channels || {};
	var PIXEL = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase', search: 'Search', generate_lead: 'Lead' };

	function pixelParams( name, p ) {
		if ( 'search' === name ) { return { search_string: p.search_term }; }
		var out = { currency: p.currency || cfg.currency, value: p.value || 0 };
		if ( p.items ) {
			out.content_ids = p.items.map( function ( i ) { return i.item_id; } );
			out.content_type = 'product';
			out.contents = p.items.map( function ( i ) { return { id: i.item_id, quantity: i.quantity }; } );
			out.num_items = p.items.reduce( function ( n, i ) { return n + ( i.quantity || 1 ); }, 0 );
		}
		return out;
	}

	function send( name, params ) {
		params = params || {};
		if ( ch.gtm ) {
			window.dataLayer = window.dataLayer || [];
			if ( params.items ) { window.dataLayer.push( { ecommerce: null } ); }
			window.dataLayer.push( { event: name, ecommerce: params.items ? params : undefined, nexora: params.items ? undefined : params } );
		}
		if ( ch.gtag && window.gtag ) {
			window.gtag( 'event', name, params );
			if ( 'purchase' === name && ch.ads ) {
				window.gtag( 'event', 'conversion', { send_to: ch.ads, value: params.value, currency: params.currency, transaction_id: params.transaction_id } );
			}
		}
		if ( ch.pixel && window.fbq && PIXEL[ name ] ) {
			var opts = 'purchase' === name && params.transaction_id ? { eventID: 'order_' + params.transaction_id } : undefined;
			window.fbq( 'track', PIXEL[ name ], pixelParams( name, params ), opts );
		}
	}

	// Public consent bridge for consent banners.
	window.nexoraConsent = {
		update: function ( c ) {
			c = c || {};
			if ( window.gtag ) {
				window.gtag( 'consent', 'update', {
					analytics_storage: c.analytics ? 'granted' : 'denied',
					ad_storage: c.marketing ? 'granted' : 'denied',
					ad_user_data: c.marketing ? 'granted' : 'denied',
					ad_personalization: c.marketing ? 'granted' : 'denied'
				} );
			}
			if ( window.fbq ) { window.fbq( 'consent', c.marketing ? 'grant' : 'revoke' ); }
		}
	};

	if ( 'wp_consent_api' === cfg.consent ) {
		var sync = function () {
			if ( typeof window.wp_has_consent !== 'function' ) { return; }
			window.nexoraConsent.update( { analytics: window.wp_has_consent( 'statistics' ), marketing: window.wp_has_consent( 'marketing' ) } );
		};
		document.addEventListener( 'wp_listen_for_consent_change', sync );
		sync();
	} else if ( 'none' === cfg.consent && window.fbq ) {
		window.fbq( 'consent', 'grant' );
	}

	// Page events rendered by the server.
	( cfg.events || [] ).forEach( function ( e ) { send( e.name, e.params ); } );

	// Client-side events.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $button ) {
			var btn = $button && $button[ 0 ];
			if ( ! btn ) { return; }
			var item;
			try { item = JSON.parse( btn.getAttribute( 'data-nexora-item' ) || 'null' ); } catch ( err ) { item = null; }
			if ( ! item ) { return; }
			item.quantity = parseInt( btn.getAttribute( 'data-quantity' ), 10 ) || 1;
			send( 'add_to_cart', { currency: cfg.currency, value: item.price * item.quantity, items: [ item ] } );
		} );
		window.jQuery( document.body ).on( 'removed_from_cart', function ( e, fragments, hash, $button ) {
			var btn = $button && $button[ 0 ];
			if ( ! btn ) { return; }
			send( 'remove_from_cart', { currency: cfg.currency, items: [ { item_id: btn.getAttribute( 'data-product_sku' ) || btn.getAttribute( 'data-product_id' ), quantity: 1 } ] } );
		} );
	}
	document.addEventListener( 'nexora:rfq_submitted', function () {
		send( 'generate_lead', { lead_source: 'quotation_request' } );
	} );
	document.addEventListener( 'nexora:newsletter_signup', function ( e ) {
		send( 'generate_lead', { lead_source: ( e.detail && e.detail.source ) || 'newsletter' } );
	} );
}() );

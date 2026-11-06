/* Freshtastic Toolkit: built-in store (cart buttons, mini cart, checkout totals and coupons). */
( function () {
	'use strict';

	var cfg = window.nexoraStore || {};
	var i18n = cfg.i18n || {};

	function api( path, body ) {
		return fetch( cfg.rest + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: body ? { 'Content-Type': 'application/json' } : {},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( data ) { return { ok: r.ok, data: data }; } );
		} );
	}
	function cookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}
	function paintCount( count ) {
		document.querySelectorAll( '.nx-cart-count' ).forEach( function ( el ) {
			el.textContent = count;
			el.setAttribute( 'data-count', count );
			el.classList.toggle( 'is-empty', ! count );
		} );
	}
	function paintDrawer( html ) {
		document.querySelectorAll( '.nx-mini-cart-content' ).forEach( function ( el ) { el.innerHTML = html; } );
	}
	function toast( message, isError ) {
		var region = document.querySelector( '[data-nexora-toasts]' );
		if ( ! region ) { return; }
		var el = document.createElement( 'div' );
		el.className = 'nx-toast' + ( isError ? ' is-error' : '' );
		var p = document.createElement( 'p' );
		p.className = 'nx-toast__msg';
		p.textContent = message;
		el.appendChild( p );
		if ( ! isError ) {
			var a = document.createElement( 'a' );
			a.href = cfg.cartUrl;
			a.textContent = i18n.viewCart || 'View cart';
			el.appendChild( a );
		}
		region.appendChild( el );
		requestAnimationFrame( function () { el.classList.add( 'is-visible' ); } );
		setTimeout( function () { el.remove(); }, 5000 );
	}
	function add( productId, variationId, qty, name, button ) {
		if ( button ) { button.disabled = true; button.classList.add( 'is-loading' ); }
		return api( 'cart/add', { product_id: productId, variation_id: variationId || 0, quantity: qty || 1 } ).then( function ( res ) {
			if ( res.ok && res.data.ok ) {
				paintCount( res.data.count );
				paintDrawer( res.data.html );
				toast( ( i18n.added || 'Added to cart' ) + ( name ? ': ' + name : '' ) );
			} else {
				toast( res.data.message || i18n.error, true );
			}
		} ).catch( function () {
			toast( i18n.error, true );
		} ).finally( function () {
			if ( button ) { button.disabled = false; button.classList.remove( 'is-loading' ); }
		} );
	}

	// Header count works on cached pages: the count comes from a cookie.
	var known = cookie( 'nx_cart_n' );
	if ( known ) { paintCount( parseInt( known, 10 ) || 0 ); }

	// Mini cart is loaded when the drawer is first opened.
	document.addEventListener( 'click', function ( e ) {
		var open = e.target.closest( '[data-nexora-open="nx-cart-drawer"]' );
		if ( open ) {
			api( 'cart' ).then( function ( res ) { paintCount( res.data.count ); paintDrawer( res.data.html ); } );
		}
		var remove = e.target.closest( '[data-nx-remove]' );
		if ( remove ) {
			e.preventDefault();
			api( 'cart/remove', { key: remove.getAttribute( 'data-nx-remove' ) } ).then( function ( res ) { paintCount( res.data.count ); paintDrawer( res.data.html ); } );
		}
		var button = e.target.closest( 'a.add_to_cart_button[data-product_id]' );
		if ( button ) {
			e.preventDefault();
			add( parseInt( button.getAttribute( 'data-product_id' ), 10 ), 0, 1, button.getAttribute( 'data-product_name' ) );
		}
		var thumb = e.target.closest( '[data-nx-thumb]' );
		if ( thumb ) {
			var main = document.querySelector( '[data-nx-main-image]' );
			if ( main ) { main.removeAttribute( 'srcset' ); main.src = thumb.getAttribute( 'data-nx-thumb' ); }
		}
	} );

	// Product page: stay on the page after "Add to cart". "Buy now" submits normally and goes to checkout.
	document.querySelectorAll( '[data-nx-buy]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( e.submitter && 'buy_now' === e.submitter.name ) { return; }
			e.preventDefault();
			var variation = form.querySelector( '[name="variation_id"]' );
			if ( variation && ! variation.value ) {
				toast( i18n.choose, true );
				variation.focus();
				return;
			}
			add(
				parseInt( form.querySelector( '[name="add-to-cart"]' ).value, 10 ),
				variation ? parseInt( variation.value, 10 ) : 0,
				parseInt( form.querySelector( '[name="quantity"]' ).value, 10 ) || 1,
				form.getAttribute( 'data-product_name' ),
				e.submitter
			);
		} );
		var select = form.querySelector( '[name="variation_id"]' );
		var price = document.querySelector( '[data-nx-price]' );
		if ( select && price ) {
			var original = price.innerHTML;
			select.addEventListener( 'change', function () {
				var opt = select.options[ select.selectedIndex ];
				var text = opt && opt.getAttribute( 'data-price' );
				if ( text ) { price.textContent = text; } else { price.innerHTML = original; }
			} );
		}
	} );

	// Cart page: changing a quantity updates the cart.
	document.querySelectorAll( '[data-nx-cart-form]' ).forEach( function ( form ) {
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( 'input.qty' ) ) { form.submit(); }
		} );
	} );

	// Checkout.
	var co = document.querySelector( '[data-nx-checkout]' );
	if ( co ) {
		var summary = co.querySelector( '[data-nx-summary]' );
		var totalSlot = co.querySelector( '[data-nexora-summary-total]' );
		var shipping = function () {
			var r = co.querySelector( 'input[name="shipping"]:checked' );
			return r ? r.value : '';
		};
		var email = function () {
			var f = co.querySelector( '#email' );
			return f ? f.value : '';
		};
		var paint = function ( data ) {
			if ( summary && data.summary ) { summary.innerHTML = data.summary; }
			if ( totalSlot && data.total ) { totalSlot.textContent = data.total; }
		};
		// Hide payment methods that do not work with the chosen delivery option.
		var syncPayments = function () {
			var type = shipping();
			var firstOk = null;
			var selectedOk = false;
			co.querySelectorAll( '.wc_payment_method' ).forEach( function ( li ) {
				var types = ( li.getAttribute( 'data-pay-types' ) || '' ).split( ' ' ).filter( Boolean );
				var ok = ! types.length || types.indexOf( type ) > -1;
				li.hidden = ! ok;
				var input = li.querySelector( 'input' );
				if ( ok && ! firstOk ) { firstOk = input; }
				if ( ok && input.checked ) { selectedOk = true; }
			} );
			if ( ! selectedOk && firstOk ) { firstOk.checked = true; }
		};
		co.addEventListener( 'change', function ( e ) {
			if ( 'shipping' === e.target.name ) {
				syncPayments();
				api( 'totals', { shipping: shipping(), email: email() } ).then( function ( res ) { paint( res.data ); } );
			}
		} );
		syncPayments();

		var msg = co.querySelector( '[data-nx-coupon-msg]' );
		var couponCall = function ( code, remove ) {
			api( 'coupon', { code: code, remove: !! remove, shipping: shipping(), email: email() } ).then( function ( res ) {
				paint( res.data );
				if ( msg ) {
					msg.textContent = res.data.message || '';
					msg.classList.toggle( 'is-error', ! res.data.ok );
				}
			} );
		};
		co.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-nx-apply-coupon]' ) ) {
				e.preventDefault();
				var input = co.querySelector( '#nx_coupon_code' );
				if ( input && input.value.trim() ) { couponCall( input.value.trim() ); input.value = ''; }
			}
			var rm = e.target.closest( '[data-nx-remove-coupon]' );
			if ( rm ) {
				e.preventDefault();
				couponCall( rm.getAttribute( 'data-nx-remove-coupon' ), true );
			}
		} );
		var couponInput = co.querySelector( '#nx_coupon_code' );
		if ( couponInput ) {
			couponInput.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					if ( couponInput.value.trim() ) { couponCall( couponInput.value.trim() ); couponInput.value = ''; }
				}
			} );
		}
		var errors = document.querySelector( '[data-nx-errors]' );
		if ( errors ) { errors.focus(); }
	}
}() );

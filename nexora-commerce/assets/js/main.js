/**
 * Freshtastic Food Hub — front-end behaviour.
 *
 * Vanilla JS, no build step. Every feature enhances server-rendered markup that already
 * works without JavaScript. WooCommerce's own scripts (jQuery) remain in charge of cart
 * actions and variations; this file only listens to their events.
 *
 * Custom events dispatched on document (for analytics integrations):
 *   nexora:search            { term }
 *   nexora:newsletter_signup { source }
 *   nexora:content-updated   { root }   after AJAX shop updates / injected cards
 */
( function () {
	'use strict';

	var data = window.nexoraData || {};
	var i18n = data.i18n || {};
	var doc = document;
	var root = doc.documentElement;
	root.classList.add( 'js' );

	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* ---------------------------------------------------------------- utils */
	function $( sel, ctx ) { return ( ctx || doc ).querySelector( sel ); }
	function $$( sel, ctx ) { return Array.prototype.slice.call( ( ctx || doc ).querySelectorAll( sel ) ); }
	function on( el, type, fn, opts ) { if ( el ) { el.addEventListener( type, fn, opts ); } }
	function emit( name, detail ) { doc.dispatchEvent( new CustomEvent( name, { detail: detail || {} } ) ); }
	function debounce( fn, wait ) {
		var t;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}
	function store( key, value ) {
		try {
			if ( value === undefined ) { return JSON.parse( window.localStorage.getItem( key ) ); }
			window.localStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) { return null; }
		return null;
	}
	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}
	function formatMoney( amount ) {
		var c = data.currency || { symbol: '', position: 'left', decimals: 2, decimal: '.', thousand: ',' };
		var fixed = Number( amount ).toFixed( c.decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousand );
		var num = parts.join( c.decimal );
		switch ( c.position ) {
			case 'right': return num + c.symbol;
			case 'left_space': return c.symbol + ' ' + num;
			case 'right_space': return num + ' ' + c.symbol;
			default: return c.symbol + num;
		}
	}

	/* --------------------------------------------------------------- header */
	function initHeader() {
		var header = $( '[data-nexora-header]' );
		if ( ! header ) { return; }
		var ticking = false;
		function update() {
			header.classList.toggle( 'is-scrolled', window.scrollY > 8 );
			ticking = false;
		}
		on( window, 'scroll', function () {
			if ( ! ticking ) { window.requestAnimationFrame( update ); ticking = true; }
		}, { passive: true } );
		update();
	}

	function initAnnouncement() {
		var bar = $( '[data-nexora-announcement]' );
		if ( ! bar ) { return; }
		var key = 'nexora_announcement_' + bar.getAttribute( 'data-nexora-announcement' );
		if ( store( key ) ) { bar.remove(); return; }
		on( $( '[data-nexora-dismiss]', bar ), 'click', function () {
			store( key, 1 );
			bar.remove();
		} );

		var countdown = $( '[data-nexora-countdown]', bar );
		if ( countdown ) {
			var ends = parseInt( countdown.getAttribute( 'data-nexora-countdown' ), 10 ) * 1000;
			var time = $( 'time', countdown );
			var tick = function () {
				var left = Math.max( 0, ends - Date.now() );
				if ( ! left ) { bar.remove(); return; }
				var d = Math.floor( left / 864e5 ), h = Math.floor( left / 36e5 ) % 24, m = Math.floor( left / 6e4 ) % 60, s = Math.floor( left / 1e3 ) % 60;
				var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };
				time.textContent = ( d ? d + ( i18n.countdownDays || 'd' ) + ' ' : '' ) + pad( h ) + ':' + pad( m ) + ':' + pad( s );
				time.setAttribute( 'aria-hidden', 'true' );
			};
			tick();
			setInterval( tick, 1000 );
		}
	}

	/* -------------------------------------------------------------- drawers */
	var openDrawer = null;
	var lastTrigger = null;

	function focusables( el ) {
		return $$( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])', el )
			.filter( function ( n ) { return n.offsetParent !== null; } );
	}

	function showDrawer( drawer, trigger ) {
		if ( openDrawer && openDrawer !== drawer ) { hideDrawer( openDrawer, true ); }
		lastTrigger = trigger || doc.activeElement;
		drawer.hidden = false;
		if ( drawer.hasAttribute( 'data-nexora-filters-drawer' ) ) {
			drawer.setAttribute( 'role', 'dialog' );
			drawer.setAttribute( 'aria-modal', 'true' );
			drawer.setAttribute( 'aria-labelledby', 'nx-filters-title' );
		}
		window.requestAnimationFrame( function () { drawer.classList.add( 'is-open' ); } );
		doc.body.classList.add( 'nx-locked' );
		$$( '[aria-controls="' + drawer.id + '"]' ).forEach( function ( b ) { b.setAttribute( 'aria-expanded', 'true' ); } );
		openDrawer = drawer;
		var first = $( '.nx-drawer__title, .nx-filters__title', drawer );
		var target = focusables( drawer )[ 0 ];
		setTimeout( function () { ( target || first || drawer ).focus(); }, reducedMotion ? 0 : 60 );
	}

	function hideDrawer( drawer, silent ) {
		drawer.classList.remove( 'is-open' );
		doc.body.classList.remove( 'nx-locked' );
		$$( '[aria-controls="' + drawer.id + '"]' ).forEach( function ( b ) { b.setAttribute( 'aria-expanded', 'false' ); } );
		if ( drawer.hasAttribute( 'data-nexora-filters-drawer' ) ) {
			drawer.removeAttribute( 'role' );
			drawer.removeAttribute( 'aria-modal' );
		} else {
			setTimeout( function () { if ( ! drawer.classList.contains( 'is-open' ) ) { drawer.hidden = true; } }, reducedMotion ? 0 : 350 );
		}
		openDrawer = null;
		if ( ! silent && lastTrigger && lastTrigger.focus ) { lastTrigger.focus(); }
	}

	function initDrawers() {
		on( doc, 'click', function ( e ) {
			var opener = e.target.closest( '[data-nexora-open]' );
			if ( opener ) {
				var drawer = doc.getElementById( opener.getAttribute( 'data-nexora-open' ) );
				if ( drawer ) {
					e.preventDefault();
					showDrawer( drawer, opener );
				}
				return;
			}
			if ( openDrawer && e.target.closest( '[data-nexora-close]' ) ) {
				e.preventDefault();
				hideDrawer( openDrawer );
			}
		} );

		on( doc, 'keydown', function ( e ) {
			if ( ! openDrawer ) { return; }
			if ( 'Escape' === e.key ) { hideDrawer( openDrawer ); return; }
			if ( 'Tab' === e.key ) {
				var items = focusables( openDrawer );
				if ( ! items.length ) { return; }
				var first = items[ 0 ], last = items[ items.length - 1 ];
				if ( e.shiftKey && doc.activeElement === first ) { e.preventDefault(); last.focus(); }
				else if ( ! e.shiftKey && doc.activeElement === last ) { e.preventDefault(); first.focus(); }
			}
		} );

		// Close the filters drawer if the viewport grows past the drawer breakpoint.
		var mq = window.matchMedia( '(min-width: 1024px)' );
		var onChange = function () { if ( mq.matches && openDrawer && openDrawer.hasAttribute( 'data-nexora-filters-drawer' ) ) { hideDrawer( openDrawer, true ); } };
		if ( mq.addEventListener ) { mq.addEventListener( 'change', onChange ); }

		$$( '.nx-drawer__title, .nx-filters__title' ).forEach( function ( t ) { t.setAttribute( 'tabindex', '-1' ); } );
	}

	/* ------------------------------------------------------------ mega menu */
	function initMegaMenu() {
		var toggle = $( '.nx-mega-toggle' );
		var panel = $( '#nx-mega' );
		if ( ! toggle || ! panel ) { return; }
		var item = toggle.closest( '.nx-has-mega' );
		var hoverTimer;
		function set( open ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			panel.hidden = ! open;
		}
		on( toggle, 'click', function () { set( panel.hidden ); } );
		on( doc, 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! panel.hidden ) { set( false ); toggle.focus(); }
		} );
		on( doc, 'click', function ( e ) { if ( ! panel.hidden && ! item.contains( e.target ) ) { set( false ); } } );
		on( item, 'focusout', function ( e ) { if ( e.relatedTarget && ! item.contains( e.relatedTarget ) ) { set( false ); } } );
		if ( window.matchMedia( '(hover: hover)' ).matches ) {
			on( item, 'mouseenter', function () { clearTimeout( hoverTimer ); hoverTimer = setTimeout( function () { set( true ); }, 120 ); } );
			on( item, 'mouseleave', function () { clearTimeout( hoverTimer ); hoverTimer = setTimeout( function () { set( false ); }, 200 ); } );
		}
	}

	/* --------------------------------------------------------- live search */
	function initSearch() {
		var wrap = $( '[data-nexora-search]' );
		if ( ! wrap ) { return; }
		var toggle = $( '.nx-search__toggle', wrap );
		var input = $( 'input[type="search"]', wrap );
		var list = $( '#nx-search-results', wrap );
		var status = $( '[data-nexora-search-status]', wrap );
		var form = $( 'form', wrap );
		var controller = null;
		var active = -1;
		var cache = {};

		on( toggle, 'click', function () {
			var open = ! wrap.classList.contains( 'is-open' );
			wrap.classList.toggle( 'is-open', open );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) { input.focus(); }
		} );

		function options() { return $$( '[role="option"]', list ); }
		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}
		function highlight( index ) {
			var opts = options();
			if ( ! opts.length ) { return; }
			active = ( index + opts.length ) % opts.length;
			opts.forEach( function ( o, i ) { o.setAttribute( 'aria-selected', i === active ? 'true' : 'false' ); } );
			input.setAttribute( 'aria-activedescendant', opts[ active ].id );
			opts[ active ].scrollIntoView( { block: 'nearest' } );
		}
		function render( term, res ) {
			var html = '';
			if ( ! res.results.length ) {
				html = '<p class="nx-search__empty">' + escapeHtml( i18n.noResults || '' ) + '</p>';
			} else {
				res.results.forEach( function ( r, i ) {
					html += '<a class="nx-search__item" role="option" aria-selected="false" id="nx-sr-' + i + '" href="' + escapeHtml( r.url ) + '">' +
						( r.image ? '<img src="' + escapeHtml( r.image ) + '" alt="" width="48" height="48" loading="lazy">' : '' ) +
						'<span class="nx-search__item-title">' + escapeHtml( r.title ) + '</span>' +
						( r.price ? '<span class="price">' + r.price + '</span>' : '' ) + '</a>';
				} );
				html += '<a class="nx-search__item nx-search__all" role="option" aria-selected="false" id="nx-sr-all" href="' + escapeHtml( res.allUrl ) + '">' + escapeHtml( i18n.viewAll || 'View all' ) + '</a>';
			}
			list.innerHTML = html;
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			active = -1;
			if ( status ) { status.textContent = ( i18n.resultsCount || '%d' ).replace( '%d', res.results.length ); }
		}
		var lookup = debounce( function () {
			var term = input.value.trim();
			if ( term.length < 2 ) { close(); return; }
			if ( cache[ term ] ) { render( term, cache[ term ] ); return; }
			if ( controller ) { controller.abort(); }
			controller = window.AbortController ? new AbortController() : null;
			if ( status ) { status.textContent = i18n.searching || ''; }
			fetch( data.restUrl + 'search?q=' + encodeURIComponent( term ), { signal: controller ? controller.signal : undefined, credentials: 'omit' } )
				.then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } )
				.then( function ( res ) { cache[ term ] = res; if ( input.value.trim() === term ) { render( term, res ); } } )
				.catch( function () {} );
		}, 220 );

		on( input, 'input', lookup );
		on( input, 'keydown', function ( e ) {
			if ( list.hidden ) { return; }
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); highlight( active + 1 ); }
			else if ( 'ArrowUp' === e.key ) { e.preventDefault(); highlight( active - 1 ); }
			else if ( 'Enter' === e.key && active > -1 ) { e.preventDefault(); options()[ active ].click(); }
			else if ( 'Escape' === e.key ) { close(); }
		} );
		on( doc, 'click', function ( e ) { if ( ! wrap.contains( e.target ) ) { close(); if ( toggle && wrap.classList.contains( 'is-open' ) ) { wrap.classList.remove( 'is-open' ); toggle.setAttribute( 'aria-expanded', 'false' ); } } } );
		on( form, 'submit', function () { emit( 'nexora:search', { term: input.value.trim() } ); } );
	}

	/* ---------------------------------------------------------------- toast */
	function toast( html, isError ) {
		var region = $( '[data-nexora-toasts]' );
		if ( ! region ) { return; }
		var el = doc.createElement( 'div' );
		el.className = 'nx-toast' + ( isError ? ' nx-toast--error' : '' );
		el.innerHTML = html;
		region.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-leaving' );
			setTimeout( function () { el.remove(); }, 300 );
		}, 4500 );
	}

	/* ----------------------------------------------------------------- cart */
	function initCart() {
		if ( ! data.woocommerce || ! window.jQuery ) { return; }
		var $body = window.jQuery( doc.body );

		$body.on( 'added_to_cart', function ( e, fragments, hash, $button ) {
			var btn = $button && $button[ 0 ];
			var name = btn ? btn.getAttribute( 'data-product_name' ) : '';
			var card = btn ? btn.closest( '.nx-card' ) : null;
			var img = card ? $( '.nx-card__img', card ) : null;
			toast(
				( img ? '<img src="' + escapeHtml( img.currentSrc || img.src ) + '" alt="">' : '' ) +
				'<p class="nx-toast__msg"><strong>' + escapeHtml( i18n.added || 'Added to cart' ) + '</strong>' + escapeHtml( name || '' ) + '</p>' +
				'<a href="' + escapeHtml( data.cartUrl || '#' ) + '" data-nexora-open="nx-cart-drawer">' + escapeHtml( ( window.wc_add_to_cart_params && window.wc_add_to_cart_params.i18n_view_cart ) || 'View cart' ) + '</a>'
			);
			var count = $( '.nx-cart-count' );
			if ( count ) {
				count.classList.add( 'is-bumped' );
				setTimeout( function () { count.classList.remove( 'is-bumped' ); }, 300 );
			}
		} );

		$body.on( 'ajax_request_not_sent.adding_to_cart', function () { toast( '<p class="nx-toast__msg">' + escapeHtml( i18n.addError || '' ) + '</p>', true ); } );
		$body.on( 'updated_wc_div updated_cart_totals wc_fragments_refreshed', function () { enhanceQuantity( doc ); } );
	}

	/* ------------------------------------------------------- quantity steps */
	function enhanceQuantity( ctx ) {
		$$( '.quantity', ctx ).forEach( function ( q ) {
			var input = $( 'input.qty', q );
			if ( ! input || 'hidden' === input.type || q.querySelector( '.nx-qty-btn' ) ) { return; }
			var label = function ( txt ) { return '<span class="screen-reader-text">' + escapeHtml( txt ) + '</span>'; };
			var minus = doc.createElement( 'button' );
			var plus = doc.createElement( 'button' );
			minus.type = plus.type = 'button';
			minus.className = plus.className = 'nx-qty-btn';
			minus.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14"/></svg>' + label( 'Decrease quantity' );
			plus.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>' + label( 'Increase quantity' );
			q.insertBefore( minus, input );
			q.appendChild( plus );
			var step = function ( dir ) {
				var stepVal = parseFloat( input.step ) || 1;
				var min = input.min !== '' ? parseFloat( input.min ) : 0;
				var max = input.max !== '' ? parseFloat( input.max ) : Infinity;
				var val = ( parseFloat( input.value ) || 0 ) + dir * stepVal;
				input.value = Math.min( max, Math.max( min, val ) );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			};
			on( minus, 'click', function () { step( -1 ); } );
			on( plus, 'click', function () { step( 1 ); } );
		} );
	}

	/* ------------------------------------------------------- shop (AJAX) */
	function initShop() {
		var results = $( '[data-nexora-results]' );
		if ( ! results ) { return; }
		var live = doc.createElement( 'p' );
		live.className = 'screen-reader-text';
		live.setAttribute( 'role', 'status' );
		results.parentNode.insertBefore( live, results );

		// Grid / list view.
		function applyView( view ) {
			results.classList.toggle( 'is-list', 'list' === view );
			$$( '[data-nexora-view]', results ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b.getAttribute( 'data-nexora-view' ) === view ? 'true' : 'false' );
			} );
		}
		applyView( store( 'nexora_view' ) || 'grid' );
		on( doc, 'click', function ( e ) {
			var b = e.target.closest( '[data-nexora-view]' );
			if ( b ) { store( 'nexora_view', b.getAttribute( 'data-nexora-view' ) ); applyView( b.getAttribute( 'data-nexora-view' ) ); }
		} );

		// Build a clean URL from the filter form: x[]=a&x[]=b → x=a,b; drop empties.
		function urlFromForm( form ) {
			var params = new URLSearchParams();
			var multi = {};
			new FormData( form ).forEach( function ( value, key ) {
				value = String( value ).trim();
				if ( '' === value ) { return; }
				if ( /\[\]$/.test( key ) ) {
					key = key.slice( 0, -2 );
					( multi[ key ] = multi[ key ] || [] ).push( value );
				} else {
					params.set( key, value );
				}
			} );
			Object.keys( multi ).forEach( function ( k ) { params.set( k, multi[ k ].join( ',' ) ); } );
			Array.from( params.keys() ).forEach( function ( k ) {
				if ( 0 === k.indexOf( 'query_type_' ) && ! params.has( 'filter_' + k.slice( 11 ) ) ) { params.delete( k ); }
			} );
			var ordering = $( '.woocommerce-ordering select.orderby' );
			if ( ordering && ! params.has( 'orderby' ) && ordering.value && 'menu_order' !== ordering.value ) { params.set( 'orderby', ordering.value ); }
			var qs = params.toString();
			return form.action.split( '?' )[ 0 ] + ( qs ? '?' + qs : '' );
		}

		var pending = null;
		function load( url, push ) {
			if ( pending ) { pending.abort(); }
			pending = window.AbortController ? new AbortController() : null;
			results.classList.add( 'is-loading' );
			results.setAttribute( 'aria-busy', 'true' );
			fetch( url, { credentials: 'same-origin', signal: pending ? pending.signal : undefined, headers: { 'X-Requested-With': 'nexora-shop' } } )
				.then( function ( r ) { if ( ! r.ok ) { throw new Error( r.status ); } return r.text(); } )
				.then( function ( html ) {
					var next = new DOMParser().parseFromString( html, 'text/html' );
					var nextResults = $( '[data-nexora-results]', next );
					if ( ! nextResults ) { throw new Error( 'structure' ); }
					results.innerHTML = nextResults.innerHTML;
					[ '.nx-shop-header', '[data-nexora-filters]', '.nx-breadcrumbs' ].forEach( function ( sel ) {
						var cur = $( sel ), nxt = $( sel, next );
						if ( cur && nxt ) { cur.replaceWith( nxt ); }
					} );
					doc.title = next.title;
					if ( push ) { history.pushState( { nexoraShop: true }, '', url ); }
					applyView( store( 'nexora_view' ) || 'grid' );
					enhanceQuantity( results );
					var count = $( '.woocommerce-result-count', results );
					live.textContent = count ? count.textContent.trim() : '';
					emit( 'nexora:content-updated', { root: results } );
					if ( push && results.getBoundingClientRect().top < 0 ) {
						results.scrollIntoView( { behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' } );
					}
				} )
				.catch( function ( err ) {
					if ( err && 'AbortError' === err.name ) { return; }
					window.location.href = url;
				} )
				.finally( function () {
					results.classList.remove( 'is-loading' );
					results.removeAttribute( 'aria-busy' );
				} );
		}

		var autoApply = debounce( function ( form ) { load( urlFromForm( form ), true ); }, 350 );

		on( doc, 'change', function ( e ) {
			var form = e.target.closest( '[data-nexora-filters]' );
			if ( form ) { autoApply( form ); }
		} );
		on( doc, 'submit', function ( e ) {
			var form = e.target.closest( '[data-nexora-filters]' );
			if ( ! form ) { return; }
			e.preventDefault();
			load( urlFromForm( form ), true );
			if ( openDrawer && openDrawer.hasAttribute( 'data-nexora-filters-drawer' ) ) { hideDrawer( openDrawer ); }
		} );
		// Sorting: intercept before WooCommerce's handler submits the form.
		on( doc, 'change', function ( e ) {
			if ( ! e.target.matches( '.woocommerce-ordering select.orderby' ) || ! results.contains( e.target ) ) { return; }
			e.stopPropagation();
			var form = e.target.form;
			var params = new URLSearchParams( window.location.search );
			params.set( 'orderby', e.target.value );
			params.delete( 'paged' );
			Array.prototype.forEach.call( form.querySelectorAll( 'input[type="hidden"]' ), function ( h ) { if ( 'paged' !== h.name ) { params.set( h.name, h.value ); } } );
			load( window.location.pathname.replace( /\/page\/\d+\/?$/, '/' ) + '?' + params.toString(), true );
		}, true );
		on( doc, 'click', function ( e ) {
			var a = e.target.closest( '[data-nexora-ajax], .woocommerce-pagination a, .nx-filter-cats a, .nx-subcats a' );
			if ( ! a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 ) { return; }
			if ( a.closest( '[data-nexora-results]' ) || a.closest( '.nx-filters' ) || a.closest( '.nx-shop-header' ) ) {
				e.preventDefault();
				load( a.href, true );
			}
		} );
		on( window, 'popstate', function ( e ) {
			if ( e.state && e.state.nexoraShop ) { load( window.location.href, false ); }
		} );
		history.replaceState( { nexoraShop: true }, '' );
	}

	/* ------------------------------------------- recently viewed & discovery */
	var HISTORY_KEY = 'nexora_recently_viewed';
	function history_() { return ( store( HISTORY_KEY ) || [] ).filter( function ( id ) { return id > 0; } ); }

	function fetchCards( query ) {
		return fetch( data.restUrl + 'cards?' + query, { credentials: 'omit' } ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } );
	}

	function initRecentlyViewed() {
		var marker = $( '[data-nexora-viewed]' );
		if ( marker ) {
			var id = parseInt( marker.getAttribute( 'data-nexora-viewed' ), 10 );
			var list = history_().filter( function ( x ) { return x !== id; } );
			list.unshift( id );
			store( HISTORY_KEY, list.slice( 0, 12 ) );
		}
		if ( ! data.woocommerce ) { return; }

		$$( '[data-nexora-recent]' ).forEach( function ( section ) {
			var exclude = parseInt( section.getAttribute( 'data-exclude' ), 10 ) || 0;
			var ids = history_().filter( function ( x ) { return x !== exclude; } ).slice( 0, 4 );
			if ( ! ids.length ) { return; }
			fetchCards( 'ids=' + ids.join( ',' ) ).then( function ( res ) {
				if ( ! res.count ) { return; }
				$( '[data-nexora-recent-grid]', section ).innerHTML = res.html;
				section.hidden = false;
				emit( 'nexora:content-updated', { root: section } );
			} ).catch( function () {} );
		} );

		var discovery = $( '[data-nexora-discovery]' );
		var hist = history_();
		if ( discovery && hist.length ) {
			var grid = $( '[data-nexora-discovery-grid]', discovery );
			fetchCards( 'related=' + hist[ 0 ] + '&ids=' + hist.slice( 0, 8 ).join( ',' ) ).then( function ( res ) {
				if ( ! res.count ) { return; }
				grid.innerHTML = res.html;
				var note = $( '[data-nexora-discovery-note]', discovery );
				if ( note ) { note.hidden = true; }
				emit( 'nexora:content-updated', { root: grid } );
			} ).catch( function () {} );
		}
	}

	/* ------------------------------------------------------ sticky add-to-cart */
	function initStickyAtc() {
		var bar = $( '[data-nexora-sticky-atc]' );
		var target = $( '.summary form.cart, .summary .single_add_to_cart_button, .summary .stock.out-of-stock' );
		if ( ! bar || ! target || ! window.IntersectionObserver ) { return; }
		bar.hidden = false;
		var footer = $( '.site-footer' );
		var pastForm = false, atFooter = false;
		var sync = function () {
			var show = pastForm && ! atFooter;
			bar.classList.toggle( 'is-visible', show );
			bar.toggleAttribute( 'inert', ! show );
			doc.body.classList.toggle( 'has-sticky-atc', show );
		};
		new IntersectionObserver( function ( entries ) {
			pastForm = ! entries[ 0 ].isIntersecting && entries[ 0 ].boundingClientRect.top < 0;
			sync();
		} ).observe( target );
		if ( footer ) {
			new IntersectionObserver( function ( entries ) { atFooter = entries[ 0 ].isIntersecting; sync(); } ).observe( footer );
		}
		on( $( '[data-nexora-scroll-to-form]', bar ), 'click', function ( e ) {
			var form = $( '.summary form.cart' );
			if ( ! form ) { return; }
			e.preventDefault();
			form.scrollIntoView( { behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' } );
			var first = $( 'select, input:not([type="hidden"]), button', form );
			if ( first ) { setTimeout( function () { first.focus( { preventScroll: true } ); }, reducedMotion ? 0 : 400 ); }
		} );
	}

	/* ------------------------------------------ free shipping (Cart block) */
	function initBlockFreeShipping() {
		var bar = $( '.nx-freeship[data-context="block"]' );
		var threshold = parseFloat( data.freeShipping || 0 );
		if ( ! bar || ! threshold || ! window.wp || ! window.wp.data ) { return; }
		var select = window.wp.data.select;
		var render = function () {
			var cartStore = select( 'wc/store/cart' );
			if ( ! cartStore || ! cartStore.getCartTotals ) { return; }
			var totals = cartStore.getCartTotals();
			var cart = cartStore.getCartData();
			if ( ! totals || ! cart ) { return; }
			var unit = Math.pow( 10, totals.currency_minor_unit || 0 );
			var inclTax = !! ( window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting && window.wc.wcSettings.getSetting( 'displayCartPricesIncludingTax', false ) );
			var subtotal = ( parseInt( totals.total_items, 10 ) - parseInt( totals.total_discount, 10 ) + ( inclTax ? parseInt( totals.total_items_tax, 10 ) - parseInt( totals.total_discount_tax, 10 ) : 0 ) ) / unit;
			if ( ! cart.itemsCount || ! cart.needsShipping ) { bar.hidden = true; return; }
			var left = Math.max( 0, threshold - subtotal );
			var pct = Math.min( 100, Math.floor( subtotal / threshold * 100 ) );
			bar.hidden = false;
			bar.classList.toggle( 'is-complete', ! left );
			bar.innerHTML = '<p class="nx-freeship__msg">' + ( left ? escapeHtml( ( i18n.freeShipLeft || '%s' ) ).replace( '%s', '<strong>' + escapeHtml( formatMoney( left ) ) + '</strong>' ) : escapeHtml( i18n.freeShipping || '' ) ) + '</p>' +
				'<div class="nx-freeship__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><span style="width:' + pct + '%"></span></div>';
		};
		window.wp.data.subscribe( debounce( render, 150 ) );
	}

	/* ------------------------------------------------------------ newsletter */
	function initNewsletter() {
		on( doc, 'submit', function ( e ) {
			var form = e.target.closest( '[data-nexora-newsletter]' );
			if ( ! form ) { return; }
			var msg = $( '.nx-newsletter-form__msg', form );
			var email = $( 'input[type="email"]', form );
			var consent = $( 'input[name="consent"]', form );
			var say = function ( text, ok ) { msg.textContent = text; msg.className = 'nx-newsletter-form__msg ' + ( ok ? 'is-success' : 'is-error' ); };
			e.preventDefault();
			if ( ! email.checkValidity() ) { say( email.validationMessage, false ); email.setAttribute( 'aria-invalid', 'true' ); email.focus(); return; }
			email.removeAttribute( 'aria-invalid' );
			if ( consent && ! consent.checked ) { say( consent.validationMessage || 'Please tick the consent box.', false ); consent.focus(); return; }
			if ( ! data.newsletterUrl ) { form.submit(); return; }
			var button = $( 'button[type="submit"]', form );
			button.disabled = true;
			fetch( data.newsletterUrl, { method: 'POST', body: new FormData( form ), credentials: 'same-origin' } )
				.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
				.then( function ( res ) {
					say( res.body && res.body.message ? res.body.message : '', res.ok );
					if ( res.ok ) {
						form.reset();
						emit( 'nexora:newsletter_signup', { source: ( form.querySelector( '[name="source"]' ) || {} ).value } );
					}
				} )
				.catch( function () { form.submit(); } )
				.finally( function () { button.disabled = false; } );
		} );
	}

	/* ---------------------------------------------- market: ticker, status, board */
	function initMarket() {
		// Ticker: CSS marquee with a pause control; static and scrollable when motion is reduced.
		var ticker = $( '[data-nexora-ticker]' );
		if ( ticker ) {
			var toggle = $( '[data-nexora-ticker-toggle]', ticker );
			if ( reducedMotion ) {
				ticker.classList.add( 'is-static' );
				if ( toggle ) { toggle.hidden = true; }
			}
			on( toggle, 'click', function () {
				var paused = ticker.classList.toggle( 'is-paused' );
				toggle.setAttribute( 'aria-pressed', paused ? 'true' : 'false' );
			} );
		}

		// Market open/closed status, computed in the store's time zone (cache-safe).
		$$( '[data-nexora-market-status]' ).forEach( function ( el ) {
			var open = ( el.getAttribute( 'data-open' ) || '' ).split( ':' );
			var close = ( el.getAttribute( 'data-close' ) || '' ).split( ':' );
			if ( open.length < 2 || close.length < 2 ) { return; }
			var label = $( '[data-nexora-market-label]', el );
			var update = function () {
				var now = new Date();
				var mins = ( now.getUTCHours() * 60 + now.getUTCMinutes() + parseInt( el.getAttribute( 'data-offset' ), 10 ) + 1440 ) % 1440;
				var o = parseInt( open[ 0 ], 10 ) * 60 + parseInt( open[ 1 ], 10 );
				var c = parseInt( close[ 0 ], 10 ) * 60 + parseInt( close[ 1 ], 10 );
				var isOpen = o < c ? ( mins >= o && mins < c ) : ( mins >= o || mins < c );
				el.classList.toggle( 'is-open', isOpen );
				el.classList.toggle( 'is-closed', ! isOpen );
				var t = function ( p ) { var h = parseInt( p[ 0 ], 10 ), m = p[ 1 ]; return ( ( h % 12 ) || 12 ) + ':' + m + ( h < 12 ? ' AM' : ' PM' ); };
				label.textContent = isOpen ? ( i18n.marketOpen || 'Market open' ) + ' · ' + ( i18n.closes || 'closes' ) + ' ' + t( close ) : ( i18n.marketClosed || 'Market closed' ) + ' · ' + ( i18n.opens || 'opens' ) + ' ' + t( open );
			};
			update();
			setInterval( update, 60000 );
		} );

		// Price board category tabs.
		$$( '[data-nexora-board-tabs]' ).forEach( function ( tabs ) {
			var board = tabs.closest( '.nx-pboard' );
			var rows = $$( '[data-nexora-board-rows] tr', board );
			var empty = $( '[data-nexora-board-empty]', board );
			on( tabs, 'click', function ( e ) {
				var btn = e.target.closest( 'button[data-cat]' );
				if ( ! btn ) { return; }
				var cat = btn.getAttribute( 'data-cat' );
				$$( 'button', tabs ).forEach( function ( b ) { b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' ); } );
				var shown = 0;
				rows.forEach( function ( row ) {
					var match = ! cat || ( ' ' + row.getAttribute( 'data-cats' ) + ' ' ).indexOf( ' ' + cat + ' ' ) > -1;
					row.hidden = ! match;
					shown += match ? 1 : 0;
				} );
				if ( empty ) { empty.hidden = shown > 0; }
			} );
		} );
	}

	/* --------------------------------------------- checkout steps and summary */
	function initCheckout() {
		var form = $( 'form.nx-co' );
		if ( ! form ) { return; }
		var cards = $$( '[data-nexora-step]', form );
		var links = $$( '[data-nexora-step-link]', form );

		// The step being filled in: the card holding focus, otherwise the one in view.
		var setActive = function ( key ) {
			var seen = false;
			links.forEach( function ( a ) {
				var mine = a.getAttribute( 'data-nexora-step-link' ) === key;
				a.classList.toggle( 'is-active', mine );
				a.classList.toggle( 'is-done', ! mine && ! seen );
				if ( mine ) { a.setAttribute( 'aria-current', 'step' ); seen = true; } else { a.removeAttribute( 'aria-current' ); }
			} );
			cards.forEach( function ( c ) { c.classList.toggle( 'is-active', c.getAttribute( 'data-nexora-step' ) === key ); } );
		};
		on( form, 'focusin', function ( e ) {
			var card = e.target.closest( '[data-nexora-step]' );
			if ( card ) { setActive( card.getAttribute( 'data-nexora-step' ) ); }
		} );
		if ( 'IntersectionObserver' in window ) {
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting && ! form.contains( doc.activeElement ) ) {
						setActive( entry.target.getAttribute( 'data-nexora-step' ) );
					}
				} );
			}, { rootMargin: '-40% 0px -55% 0px' } );
			cards.forEach( function ( c ) { io.observe( c ); } );
		}
		if ( cards[ 0 ] ) { setActive( cards[ 0 ].getAttribute( 'data-nexora-step' ) ); }

		// Summary: collapsed on small screens, always open on large ones.
		var summary = $( '[data-nexora-summary]' );
		var mq = window.matchMedia( '(max-width: 1023px)' );
		var syncSummary = function () { if ( summary ) { summary.open = ! mq.matches; } };
		syncSummary();
		if ( mq.addEventListener ) { mq.addEventListener( 'change', syncSummary ); }

		// Keep the collapsed summary total in sync after WooCommerce refreshes the order review.
		if ( window.jQuery ) {
			window.jQuery( doc.body ).on( 'updated_checkout', function () {
				var total = $( '.nx-co-review .order-total td' );
				var slot = $( '[data-nexora-summary-total]' );
				if ( total && slot ) { slot.innerHTML = total.innerHTML; }
			} );
		}
	}

	/* WooCommerce's country/state dropdowns (selectWoo) render a text box without a name; reuse the field label. */
	function labelEnhancedSelects() {
		$$( '.select2-selection__rendered[role="textbox"]' ).forEach( function ( box ) {
			var row = box.closest( '.form-row' );
			var label = row && $( 'label', row );
			if ( label && ! box.getAttribute( 'aria-label' ) ) {
				box.setAttribute( 'aria-label', label.textContent.replace( /\s*\*\s*$/, '' ).trim() );
			}
		} );
	}

	/* ------------------------------------------------ sign in / register tabs */
	function initAuth() {
		var auth = $( '[data-nexora-auth]' );
		var tabs = auth && $( '[data-nexora-auth-tabs]', auth );
		if ( ! tabs ) { return; }
		var buttons = $$( '[data-nexora-tab]', tabs );
		var select = function ( name, focus ) {
			buttons.forEach( function ( b ) {
				var mine = b.getAttribute( 'data-nexora-tab' ) === name;
				b.setAttribute( 'aria-selected', mine ? 'true' : 'false' );
				b.tabIndex = mine ? 0 : -1;
				if ( mine && focus ) { b.focus(); }
			} );
			$$( '[data-nexora-panel]', auth ).forEach( function ( p ) {
				if ( p.getAttribute( 'data-nexora-panel' ) === name ) { p.removeAttribute( 'data-inactive' ); } else { p.setAttribute( 'data-inactive', '' ); }
			} );
		};
		tabs.hidden = false;
		auth.classList.add( 'is-tabbed' );
		var current = $( '[aria-selected="true"]', tabs );
		select( current ? current.getAttribute( 'data-nexora-tab' ) : 'login', false );
		on( tabs, 'click', function ( e ) {
			var b = e.target.closest( '[data-nexora-tab]' );
			if ( b ) { select( b.getAttribute( 'data-nexora-tab' ), false ); }
		} );
		$$( '[data-nexora-tab-link]', auth ).forEach( function ( link ) {
			on( link, 'click', function ( e ) {
				e.preventDefault();
				select( link.getAttribute( 'data-nexora-tab-link' ), true );
			} );
		} );
		on( tabs, 'keydown', function ( e ) {
			if ( 'ArrowLeft' !== e.key && 'ArrowRight' !== e.key ) { return; }
			var i = buttons.indexOf( doc.activeElement );
			if ( i < 0 ) { return; }
			e.preventDefault();
			var next = buttons[ ( i + ( 'ArrowRight' === e.key ? 1 : buttons.length - 1 ) ) % buttons.length ];
			select( next.getAttribute( 'data-nexora-tab' ), true );
		} );
	}

	/* ------------------------------------------------------------------ boot */
	function boot() {
		initMarket();
		initHeader();
		initAnnouncement();
		initDrawers();
		initMegaMenu();
		initSearch();
		initCart();
		enhanceQuantity( doc );
		initShop();
		initRecentlyViewed();
		initStickyAtc();
		initBlockFreeShipping();
		initNewsletter();
		initCheckout();
		initAuth();
		window.addEventListener( 'load', labelEnhancedSelects );
		if ( window.jQuery ) { window.jQuery( doc.body ).on( 'country_to_state_changed updated_checkout', function () { setTimeout( labelEnhancedSelects, 0 ); } ); }
		if ( window.jQuery ) {
			// Variation forms can re-render quantity fields.
			window.jQuery( doc.body ).on( 'show_variation', function () { enhanceQuantity( doc ); } );
		}
	}

	if ( 'loading' === doc.readyState ) { doc.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
}() );

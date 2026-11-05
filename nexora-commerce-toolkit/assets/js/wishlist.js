/* Freshtastic Toolkit — wishlist (guest: localStorage, customer: synced to the account). */
( function () {
	'use strict';

	var cfg = window.nexoraWishlist || {};
	var KEY = 'nexora_wishlist';
	var ids = [];

	function readLocal() {
		try { return ( JSON.parse( localStorage.getItem( KEY ) ) || [] ).filter( function ( n ) { return n > 0; } ); } catch ( e ) { return []; }
	}
	function writeLocal( list ) {
		try { localStorage.setItem( KEY, JSON.stringify( list ) ); } catch ( e ) {}
	}
	function api( method, body ) {
		return fetch( cfg.rest + 'wishlist', {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } );
	}

	function paint() {
		document.querySelectorAll( '[data-nexora-wishlist]' ).forEach( function ( btn ) {
			var on = ids.indexOf( parseInt( btn.getAttribute( 'data-nexora-wishlist' ), 10 ) ) > -1;
			btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		document.querySelectorAll( '[data-nexora-wishlist-count]' ).forEach( function ( el ) {
			el.textContent = ids.length;
			el.classList.toggle( 'is-empty', ! ids.length );
		} );
	}

	function renderPage() {
		var page = document.querySelector( '[data-nexora-wishlist-page]' );
		if ( ! page ) { return; }
		if ( page.getAttribute( 'data-server' ) && ! page.hasAttribute( 'data-dirty' ) ) { return; }
		fetch( cfg.rest + 'wishlist/render?ids=' + ids.join( ',' ), { credentials: 'omit' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				page.innerHTML = res.html;
				paint();
				document.dispatchEvent( new CustomEvent( 'nexora:content-updated', { detail: { root: page } } ) );
			} );
	}

	function save() {
		if ( cfg.loggedIn ) {
			api( 'POST', { ids: ids, mode: 'replace' } ).catch( function () {} );
		} else {
			writeLocal( ids );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-nexora-wishlist]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		var id = parseInt( btn.getAttribute( 'data-nexora-wishlist' ), 10 );
		var i = ids.indexOf( id );
		if ( i > -1 ) { ids.splice( i, 1 ); } else { ids.unshift( id ); }
		paint();
		save();
		document.dispatchEvent( new CustomEvent( 'nexora:wishlist', { detail: { id: id, added: i === -1 } } ) );
		var page = document.querySelector( '[data-nexora-wishlist-page]' );
		if ( page && page.contains( btn ) ) { page.setAttribute( 'data-dirty', '1' ); renderPage(); }
	} );

	document.addEventListener( 'nexora:content-updated', paint );

	function boot() {
		if ( cfg.loggedIn ) {
			var guest = readLocal();
			var load = guest.length ? api( 'POST', { ids: guest, mode: 'merge' } ).then( function ( r ) { writeLocal( [] ); return r; } ) : api( 'GET' );
			load.then( function ( res ) {
				ids = res.ids || [];
				paint();
				if ( guest.length ) { var page = document.querySelector( '[data-nexora-wishlist-page]' ); if ( page ) { page.setAttribute( 'data-dirty', '1' ); renderPage(); } }
			} ).catch( function () {} );
		} else {
			ids = readLocal();
			paint();
			renderPage();
		}
	}

	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', boot ); } else { boot(); }
}() );

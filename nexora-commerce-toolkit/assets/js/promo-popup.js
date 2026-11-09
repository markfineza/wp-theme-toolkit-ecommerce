/* Freshtastic Toolkit: promo pop-up. */
( function () {
	'use strict';
	var dialog = document.querySelector( '[data-nx-popup]' );
	if ( ! dialog || 'function' !== typeof dialog.showModal ) { return; }

	var preview = dialog.hasAttribute( 'data-preview' );
	var frequency = dialog.getAttribute( 'data-frequency' );
	var key = 'nx_popup_' + dialog.getAttribute( 'data-key' );
	var periods = { day: 86400000, week: 604800000 };

	// Browsers can refuse storage; the pop-up then simply shows on each visit.
	var read = function ( store ) { try { return window[ store ].getItem( key ); } catch ( e ) { return null; } };
	var write = function ( store, value ) { try { window[ store ].setItem( key, value ); } catch ( e ) {} };
	var seen = function () {
		if ( 'always' === frequency ) { return false; }
		if ( 'session' === frequency ) { return !! read( 'sessionStorage' ); }
		var last = parseInt( read( 'localStorage' ), 10 );
		return last > 0 && Date.now() - last < periods[ frequency ];
	};
	var remember = function () {
		if ( 'session' === frequency ) { write( 'sessionStorage', '1' ); }
		if ( periods[ frequency ] ) { write( 'localStorage', String( Date.now() ) ); }
	};

	// A cached page can outlive a banner's end date.
	var now = Date.now() / 1000;
	var slides = Array.prototype.filter.call( dialog.querySelectorAll( '[data-nx-popup-slide]' ), function ( slide ) {
		var end = parseInt( slide.getAttribute( 'data-end' ), 10 );
		if ( ! preview && end && end < now ) { slide.remove(); return false; }
		return true;
	} );
	if ( ! slides.length || ( ! preview && seen() ) ) { return; }

	var load = function ( slide ) {
		var img = slide.querySelector( 'img' );
		if ( img.getAttribute( 'data-srcset' ) ) { img.srcset = img.getAttribute( 'data-srcset' ); }
		if ( img.getAttribute( 'data-src' ) ) { img.src = img.getAttribute( 'data-src' ); }
		img.removeAttribute( 'data-src' );
		img.removeAttribute( 'data-srcset' );
		return img;
	};

	var current = 0;
	var count = dialog.querySelector( '[data-nx-popup-count]' );
	var show = function ( index ) {
		current = ( index + slides.length ) % slides.length;
		slides.forEach( function ( slide, i ) { slide.hidden = i !== current; } );
		load( slides[ current ] );
		if ( count ) { count.textContent = ( current + 1 ) + ' / ' + slides.length; }
	};
	var nav = dialog.querySelector( '[data-nx-popup-nav]' );
	if ( nav && slides.length < 2 ) { nav.remove(); }
	if ( nav && slides.length > 1 ) {
		dialog.querySelector( '[data-nx-popup-prev]' ).addEventListener( 'click', function () { show( current - 1 ); } );
		dialog.querySelector( '[data-nx-popup-next]' ).addEventListener( 'click', function () { show( current + 1 ); } );
	}

	dialog.querySelector( '[data-nx-popup-close]' ).addEventListener( 'click', function () { dialog.close(); } );
	dialog.addEventListener( 'click', function ( e ) { if ( e.target === dialog ) { dialog.close(); } } );
	dialog.addEventListener( 'close', function () { document.documentElement.classList.remove( 'nx-popup-open' ); } );

	var open = function () {
		// Do not stack on top of a menu, drawer or other dialog the visitor is using.
		if ( document.querySelector( 'dialog[open]' ) ) { return; }
		dialog.showModal();
		document.documentElement.classList.add( 'nx-popup-open' );
		if ( ! preview ) { remember(); }
	};
	var start = function () {
		show( 0 );
		var img = slides[ 0 ].querySelector( 'img' );
		// Open only once the picture is ready, so nobody sees an empty box. A broken image never opens.
		if ( img.complete && img.naturalWidth ) { open(); return; }
		img.addEventListener( 'load', open, { once: true } );
	};
	window.setTimeout( start, preview ? 0 : ( parseInt( dialog.getAttribute( 'data-delay' ), 10 ) || 0 ) * 1000 );
}() );

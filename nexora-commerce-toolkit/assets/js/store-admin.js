/* Freshtastic Toolkit: product editor (option rows, gallery) and category image picker. */
( function () {
	'use strict';

	var box = document.querySelector( '[data-nx-product]' );
	if ( box ) {
		var sync = function () {
			var checked = box.querySelector( 'input[name="nx_type"]:checked' );
			box.setAttribute( 'data-type', checked ? checked.value : 'simple' );
		};
		box.addEventListener( 'change', function ( e ) {
			if ( 'nx_type' === e.target.name ) { sync(); }
		} );
		sync();
		var rows = box.querySelector( '[data-nx-rows]' );
		var tpl = box.querySelector( '[data-nx-row-template]' );
		var counter = rows ? rows.children.length : 0;
		box.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-nx-add-row]' ) && tpl ) {
				var html = tpl.innerHTML.replace( /__i__/g, 'n' + ( counter++ ) );
				var t = document.createElement( 'tbody' );
				t.innerHTML = html;
				rows.appendChild( t.firstElementChild );
				rows.lastElementChild.querySelector( 'input[type="text"]' ).focus();
			}
			var rm = e.target.closest( '[data-nx-remove-row]' );
			if ( rm ) { rm.closest( 'tr' ).remove(); }
		} );
		if ( rows && ! rows.children.length ) {
			var add = box.querySelector( '[data-nx-add-row]' );
			if ( add && tpl ) { add.click(); }
		}
	}

	var pick = document.querySelector( '[data-nx-gallery-pick]' );
	if ( pick && window.wp && wp.media ) {
		pick.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var frame = wp.media( { multiple: 'add', library: { type: 'image' } } );
			frame.on( 'select', function () {
				var ids = [];
				var preview = document.querySelector( '[data-nx-gallery]' );
				preview.innerHTML = '';
				frame.state().get( 'selection' ).each( function ( a ) {
					ids.push( a.id );
					var img = document.createElement( 'img' );
					img.src = ( a.get( 'sizes' ) && a.get( 'sizes' ).thumbnail ? a.get( 'sizes' ).thumbnail.url : a.get( 'url' ) );
					img.alt = '';
					preview.appendChild( img );
				} );
				document.querySelector( '[data-nx-gallery-input]' ).value = ids.join( ',' );
			} );
			frame.open();
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-nx-cat-pick]' );
		if ( ! btn || ! window.wp || ! wp.media ) { return; }
		e.preventDefault();
		var wrap = btn.closest( '[data-nx-cat-image]' );
		var frame = wp.media( { multiple: false, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first();
			wrap.querySelector( '[data-nx-cat-input]' ).value = a.id;
			var preview = wrap.querySelector( '[data-nx-cat-preview]' );
			preview.innerHTML = '';
			var img = document.createElement( 'img' );
			img.src = ( a.get( 'sizes' ) && a.get( 'sizes' ).thumbnail ? a.get( 'sizes' ).thumbnail.url : a.get( 'url' ) );
			img.alt = '';
			img.width = 60;
			preview.appendChild( img );
		} );
		frame.open();
	} );
}() );

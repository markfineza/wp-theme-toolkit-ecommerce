/* Freshtastic Toolkit: choose promo pop-up images from the media library. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-nx-popup-banner]' ).forEach( function ( box ) {
		var input = box.querySelector( '[data-nx-popup-image]' );
		var preview = box.querySelector( '[data-nx-popup-preview]' );
		var frame;
		box.querySelector( '[data-nx-popup-choose]' ).addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( { library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var file = frame.state().get( 'selection' ).first().toJSON();
					var size = file.sizes && file.sizes.medium ? file.sizes.medium : file;
					var img = document.createElement( 'img' );
					img.src = size.url;
					img.alt = '';
					img.style.cssText = 'max-width:320px;height:auto';
					preview.replaceChildren( img );
					input.value = file.id;
				} );
			}
			frame.open();
		} );
		box.querySelector( '[data-nx-popup-clear]' ).addEventListener( 'click', function () {
			preview.replaceChildren();
			input.value = '0';
		} );
	} );
}() );

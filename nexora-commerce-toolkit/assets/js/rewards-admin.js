/* Freshtastic Toolkit: show only the reward fields that apply. */
( function () {
	'use strict';

	// Free-item coupon box: the checkbox shows its fields.
	document.querySelectorAll( '[data-nx-toggle]' ).forEach( function ( box ) {
		var target = document.getElementById( box.getAttribute( 'data-nx-toggle' ) );
		if ( ! target ) { return; }
		var sync = function () { target.hidden = ! box.checked; };
		box.addEventListener( 'change', sync );
		sync();
	} );

	// First customers screen: fields follow the reward type.
	var type = document.querySelector( '[data-nx-reward-type]' );
	if ( type ) {
		var rows = document.querySelectorAll( '[data-nx-reward-for]' );
		var show = function () {
			rows.forEach( function ( row ) {
				row.hidden = row.getAttribute( 'data-nx-reward-for' ).split( ' ' ).indexOf( type.value ) === -1;
			} );
		};
		type.addEventListener( 'change', show );
		show();
	}

	document.querySelectorAll( '[data-nx-confirm]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( btn.getAttribute( 'data-nx-confirm' ) ) ) { e.preventDefault(); }
		} );
	} );
}() );

/* Freshtastic Toolkit: rows of the package contents box. */
( function () {
	'use strict';
	var toggle = document.querySelector( '[data-nx-package-toggle]' );
	var fields = document.querySelector( '[data-nx-package-fields]' );
	if ( ! toggle || ! fields ) { return; }
	var rows = fields.querySelector( '[data-nx-package-rows]' );
	var template = fields.querySelector( '[data-nx-package-template]' );

	var sync = function () { fields.hidden = ! toggle.checked; };
	toggle.addEventListener( 'change', sync );
	sync();

	fields.querySelector( '[data-nx-package-add]' ).addEventListener( 'click', function () {
		rows.appendChild( template.content.cloneNode( true ) );
		rows.lastElementChild.querySelector( 'select' ).focus();
	} );
	rows.addEventListener( 'click', function ( e ) {
		if ( ! e.target.matches( '[data-nx-package-remove]' ) ) { return; }
		var row = e.target.closest( '[data-nx-package-row]' );
		if ( rows.children.length > 1 ) {
			row.remove();
		} else {
			row.querySelector( 'select' ).value = '0';
			row.querySelector( 'input' ).value = '1';
		}
	} );
}() );

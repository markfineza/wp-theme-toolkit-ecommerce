/**
 * Freshtastic Order Desk: place the KPI strip under the page title, and "/" focuses order search.
 */
( function () {
	'use strict';

	var kpis = document.querySelector( '[data-nexora-kpis]' );
	var end = document.querySelector( '.wrap .wp-header-end' );
	if ( kpis && end ) {
		end.parentNode.insertBefore( kpis, end.nextSibling );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( '/' !== e.key || e.ctrlKey || e.metaKey || e.altKey ) {
			return;
		}
		var t = e.target;
		if ( t && ( /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) || t.isContentEditable ) ) {
			return;
		}
		var search = document.querySelector( '.search-box input[type="search"]' );
		if ( search ) {
			e.preventDefault();
			search.focus();
		}
	} );
}() );

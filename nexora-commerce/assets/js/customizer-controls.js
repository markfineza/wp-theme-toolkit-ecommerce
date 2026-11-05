/* Freshtastic: homepage sections control (toggle + reorder). */
( function () {
	function serialize( list ) {
		var input = list.parentNode.querySelector( '.nexora-sections-value' );
		var tokens = Array.prototype.map.call( list.children, function ( li ) {
			return ( li.querySelector( 'input' ).checked ? '' : '-' ) + li.dataset.key;
		} );
		input.value = tokens.join( ',' );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.nexora-sections button' );
		if ( ! btn ) {
			return;
		}
		var li = btn.closest( 'li' );
		var list = li.parentNode;
		if ( btn.classList.contains( 'nexora-up' ) && li.previousElementSibling ) {
			list.insertBefore( li, li.previousElementSibling );
		} else if ( btn.classList.contains( 'nexora-down' ) && li.nextElementSibling ) {
			list.insertBefore( li.nextElementSibling, li );
		}
		btn.focus();
		serialize( list );
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '.nexora-sections input[type="checkbox"]' ) ) {
			serialize( e.target.closest( '.nexora-sections' ) );
		}
	} );
} )();

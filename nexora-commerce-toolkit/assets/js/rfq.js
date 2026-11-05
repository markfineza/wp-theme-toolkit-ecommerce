/* Freshtastic Toolkit — quotation request form (falls back to a normal POST without JS). */
( function () {
	'use strict';
	var cfg = window.nexoraRfq || {};

	function clearErrors( form ) {
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) { el.removeAttribute( 'aria-invalid' ); el.removeAttribute( 'aria-describedby' ); } );
		form.querySelectorAll( '.nexora-rfq__error' ).forEach( function ( el ) { el.remove(); } );
	}

	function showError( form, name, message ) {
		var field = form.querySelector( '[name="' + name + '"]' );
		if ( ! field ) { return null; }
		var id = field.id + '-error';
		var p = document.createElement( 'span' );
		p.className = 'nexora-rfq__error';
		p.id = id;
		p.textContent = message;
		field.setAttribute( 'aria-invalid', 'true' );
		field.setAttribute( 'aria-describedby', id );
		( field.closest( '.nexora-rfq__field' ) || field.closest( 'label' ) || field.parentNode ).appendChild( p );
		return field;
	}

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '.nexora-rfq__form' );
		if ( ! form || ! cfg.endpoint || ! window.fetch ) { return; }
		e.preventDefault();
		var msg = form.querySelector( '.nexora-rfq__msg' );
		var btn = form.querySelector( '.nexora-rfq__submit' );
		clearErrors( form );
		msg.className = 'nexora-rfq__msg';
		msg.textContent = cfg.i18n.sending;
		btn.disabled = true;

		fetch( cfg.endpoint, { method: 'POST', body: new FormData( form ), credentials: 'same-origin' } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				var body = res.body || {};
				if ( res.ok && body.ok ) {
					var done = document.createElement( 'div' );
					done.className = 'nexora-rfq__done';
					done.setAttribute( 'role', 'status' );
					done.setAttribute( 'tabindex', '-1' );
					done.textContent = body.message;
					form.replaceWith( done );
					done.focus();
					document.dispatchEvent( new CustomEvent( 'nexora:rfq_submitted', { detail: { ref: body.ref } } ) );
					return;
				}
				msg.className = 'nexora-rfq__msg is-error';
				msg.textContent = body.message || cfg.i18n.error;
				var first = null;
				Object.keys( body.errors || {} ).forEach( function ( name ) {
					var f = showError( form, name, body.errors[ name ] );
					first = first || f;
				} );
				if ( first ) { first.focus(); }
			} )
			.catch( function () { msg.className = 'nexora-rfq__msg is-error'; msg.textContent = cfg.i18n.error; } )
			.finally( function () { btn.disabled = false; } );
	} );
}() );

/* Freshtastic Toolkit — demo importer progress UI (falls back to a normal form post without JS). */
( function () {
	'use strict';

	var cfg = window.nexoraImporter;
	if ( ! cfg ) { return; }
	var panel = document.querySelector( '[data-nexora-progress]' );
	var bar = panel.querySelector( '.nexora-progress' );
	var fill = bar.querySelector( 'span' );
	var current = panel.querySelector( '[data-nexora-current]' );
	var log = panel.querySelector( '.nexora-log' );

	function post( body ) {
		var fd = new FormData();
		fd.append( 'action', 'nexora_demo_step' );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( body ).forEach( function ( k ) {
			if ( 'choices' === k ) {
				Object.keys( body.choices ).forEach( function ( c ) { fd.append( 'choices[' + c + ']', body.choices[ c ] ); } );
			} else {
				fd.append( k, body[ k ] );
			}
		} );
		return fetch( cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } );
	}
	function say( text, isError ) {
		var li = document.createElement( 'li' );
		li.textContent = text;
		if ( isError ) { li.className = 'is-error'; }
		log.appendChild( li );
		log.scrollTop = log.scrollHeight;
	}
	function setProgress( pct ) {
		fill.style.width = pct + '%';
		bar.setAttribute( 'aria-valuenow', Math.round( pct ) );
	}

	function run( form, mode ) {
		var choices = {};
		form.querySelectorAll( 'input[name^="choices["]:checked' ).forEach( function ( i ) {
			choices[ i.name.slice( 8, -1 ) ] = 1;
		} );
		form.querySelectorAll( 'button, input[type="submit"]' ).forEach( function ( b ) { b.disabled = true; } );
		panel.hidden = false;
		log.innerHTML = '';
		setProgress( 2 );

		post( { mode: mode, choices: choices } ).then( function ( plan ) {
			var steps = Object.keys( plan.data.steps );
			var index = 0;
			function next( offset ) {
				if ( index >= steps.length ) {
					setProgress( 100 );
					current.textContent = cfg.i18n.done;
					setTimeout( function () { window.location.reload(); }, 1500 );
					return;
				}
				var step = steps[ index ];
				current.textContent = plan.data.steps[ step ];
				post( { mode: mode, step: step, offset: offset || 0, choices: choices } ).then( function ( res ) {
					var d = res.data || {};
					( d.messages || [] ).forEach( function ( m ) { say( m, ! res.success ); } );
					if ( ! res.success ) { current.textContent = cfg.i18n.failed; return; }
					var within = d.progress ? d.progress[ 0 ] / d.progress[ 1 ] : ( d.done ? 1 : 0.5 );
					setProgress( Math.min( 99, ( ( index + within ) / steps.length ) * 100 ) );
					if ( d.done ) { index++; next( 0 ); } else { next( d.offset || 0 ); }
				} ).catch( function () { say( cfg.i18n.failed, true ); current.textContent = cfg.i18n.failed; } );
			}
			next( 0 );
		} );
	}

	var importForm = document.querySelector( '[data-nexora-import]' );
	var cleanupForm = document.querySelector( '[data-nexora-cleanup]' );
	importForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( window.confirm( cfg.i18n.confirmImport ) ) { run( importForm, 'import' ); }
	} );
	cleanupForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( window.confirm( cfg.i18n.confirmCleanup ) ) { run( cleanupForm, 'cleanup' ); }
	} );
}() );

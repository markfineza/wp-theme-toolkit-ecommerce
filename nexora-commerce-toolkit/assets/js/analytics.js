/* Freshtastic Analytics: date filter, print and the sales chart. Values are set with textContent. */
( function () {
	var NS = 'http://www.w3.org/2000/svg';
	var range = document.querySelector( '[data-nx-range]' );
	var custom = document.querySelector( '[data-nx-custom]' );
	if ( range && custom ) {
		var sync = function () { custom.hidden = 'custom' !== range.value; };
		range.addEventListener( 'change', sync );
		sync();
	}
	document.querySelectorAll( '[data-nx-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () { window.print(); } );
	} );

	function el( name, attrs, parent ) {
		var node = document.createElementNS( NS, name );
		Object.keys( attrs ).forEach( function ( key ) { node.setAttribute( key, attrs[ key ] ); } );
		if ( parent ) { parent.appendChild( node ); }
		return node;
	}
	function niceTop( value ) {
		if ( value <= 0 ) { return 100; }
		var power = Math.pow( 10, Math.floor( Math.log10( value ) ) );
		var scaled = value / power;
		var step = [ 1, 2, 2.5, 5, 10 ].find( function ( s ) { return scaled <= s; } );
		return step * power;
	}
	function axis( value, symbol, compact ) {
		if ( ! compact || 0 === value ) { return symbol + value.toLocaleString(); }
		return value >= 1000000 ? symbol + ( value / 1000000 ) + 'M' : symbol + ( value / 1000 ) + 'K';
	}

	function draw( host, data, tip ) {
		var width = Math.max( host.clientWidth, 260 );
		var height = 280;
		var m = { top: 12, right: 14, bottom: 28, left: 66 };
		var w = width - m.left - m.right;
		var h = height - m.top - m.bottom;
		var n = data.current.length;
		var top = niceTop( Math.max.apply( null, data.current.concat( data.previous, [ 0 ] ) ) );
		var x = function ( i ) { return m.left + ( n > 1 ? i / ( n - 1 ) * w : w / 2 ); };
		var y = function ( v ) { return m.top + h - v / top * h; };
		host.textContent = '';
		var svg = el( 'svg', { viewBox: '0 0 ' + width + ' ' + height, width: '100%', height: height, 'aria-hidden': 'true', focusable: 'false' }, host );
		var compact = top >= 10000;
		[ 0, 0.25, 0.5, 0.75, 1 ].forEach( function ( part ) {
			var value = top * part;
			el( 'line', { x1: m.left, x2: width - m.right, y1: y( value ), y2: y( value ), class: 'nx-an-grid-line' }, svg );
			var text = el( 'text', { x: m.left - 8, y: y( value ) + 4, 'text-anchor': 'end', class: 'nx-an-axis' }, svg );
			text.textContent = axis( value, data.symbol, compact );
		} );
		var every = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( w / 72 ) ) ) );
		data.labels.forEach( function ( label, i ) {
			if ( i % every ) { return; }
			var anchor = 1 === n ? 'middle' : ( 0 === i ? 'start' : ( i === n - 1 ? 'end' : 'middle' ) );
			var text = el( 'text', { x: x( i ), y: height - 8, 'text-anchor': anchor, class: 'nx-an-axis' }, svg );
			text.textContent = label;
		} );
		var path = function ( values ) {
			return values.map( function ( v, i ) { return ( i ? 'L' : 'M' ) + x( i ) + ' ' + y( v ); } ).join( '' );
		};
		if ( n > 1 ) {
			el( 'path', { d: path( data.current ) + 'L' + x( n - 1 ) + ' ' + y( 0 ) + 'L' + x( 0 ) + ' ' + y( 0 ) + 'Z', class: 'nx-an-area' }, svg );
		}
		if ( data.previous.length > 1 ) {
			el( 'path', { d: path( data.previous ), class: 'nx-an-line is-previous' }, svg );
		}
		if ( n > 1 ) {
			el( 'path', { d: path( data.current ), class: 'nx-an-line' }, svg );
		}
		if ( n ) {
			el( 'circle', { cx: x( n - 1 ), cy: y( data.current[ n - 1 ] ), r: 4, class: 'nx-an-dot' }, svg );
		}
		var cross = el( 'line', { y1: m.top, y2: m.top + h, class: 'nx-an-cross' }, svg );
		var mark = el( 'circle', { r: 4, class: 'nx-an-dot' }, svg );
		cross.style.display = mark.style.display = 'none';

		function row( parent, key, value, name ) {
			var line = document.createElement( 'div' );
			line.className = 'nx-an-tip__row';
			var swatch = document.createElement( 'span' );
			swatch.className = 'nx-an-key' + ( key ? ' is-previous' : '' );
			var strong = document.createElement( 'strong' );
			strong.textContent = value;
			var small = document.createElement( 'span' );
			small.textContent = name;
			line.append( swatch, strong, small );
			parent.appendChild( line );
		}
		function show( i ) {
			cross.setAttribute( 'x1', x( i ) );
			cross.setAttribute( 'x2', x( i ) );
			mark.setAttribute( 'cx', x( i ) );
			mark.setAttribute( 'cy', y( data.current[ i ] ) );
			cross.style.display = mark.style.display = '';
			tip.textContent = '';
			var title = document.createElement( 'div' );
			title.className = 'nx-an-tip__title';
			title.textContent = data.labels[ i ];
			tip.appendChild( title );
			row( tip, false, data.currentText[ i ], data.currentName );
			if ( undefined !== data.previous[ i ] ) {
				row( tip, true, data.previousText[ i ], data.previousName + ( data.prevLabels[ i ] ? ' (' + data.prevLabels[ i ] + ')' : '' ) );
			}
			tip.hidden = false;
			var scale = host.clientWidth / width;
			var left = x( i ) * scale + 12;
			if ( left + tip.offsetWidth > host.clientWidth ) { left = x( i ) * scale - tip.offsetWidth - 12; }
			tip.style.left = Math.max( 0, left ) + 'px';
			tip.style.top = '8px';
			host.appendChild( tip );
			host.dataset.at = i;
		}
		function hide() {
			cross.style.display = mark.style.display = 'none';
			tip.hidden = true;
			delete host.dataset.at;
		}
		host.nxShow = show;
		host.nxHide = hide;
		host.nxPoint = function ( clientX ) {
			var box = svg.getBoundingClientRect();
			var at = ( clientX - box.left ) * ( width / box.width );
			return Math.min( n - 1, Math.max( 0, n > 1 ? Math.round( ( at - m.left ) / w * ( n - 1 ) ) : 0 ) );
		};
		hide();
	}

	document.querySelectorAll( '[data-nx-chart]' ).forEach( function ( host ) {
		var data = JSON.parse( host.getAttribute( 'data-nx-chart' ) );
		var tip = document.createElement( 'div' );
		tip.className = 'nx-an-tip';
		tip.hidden = true;
		var render = function () { draw( host, data, tip ); };
		render();
		host.addEventListener( 'pointermove', function ( event ) { host.nxShow( host.nxPoint( event.clientX ) ); } );
		host.addEventListener( 'pointerleave', function () { host.nxHide(); } );
		host.addEventListener( 'blur', function () { host.nxHide(); } );
		host.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) { return; }
			event.preventDefault();
			var last = data.current.length - 1;
			var at = undefined === host.dataset.at ? last : Number( host.dataset.at ) + ( 'ArrowLeft' === event.key ? -1 : 1 );
			host.nxShow( Math.min( last, Math.max( 0, at ) ) );
		} );
		if ( window.ResizeObserver ) {
			var seen = host.clientWidth;
			new ResizeObserver( function () {
				if ( host.clientWidth !== seen ) { seen = host.clientWidth; render(); }
			} ).observe( host );
		}
	} );
}() );

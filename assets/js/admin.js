/**
 * Estasoft Handover Passport: admin script. Loaded on Passport screens only.
 * Plain JavaScript, no framework and no outside request.
 */
( function () {
	'use strict';

	// Disable a submit button after the first press so a form is not sent twice.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.closest || ! form.closest( '.estasoft-hp' ) ) {
			return;
		}
		var button = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		if ( button ) {
			window.setTimeout( function () {
				button.disabled = true;
				button.setAttribute( 'aria-busy', 'true' );
			}, 0 );
		}
	} );

	// A register list narrows as you type in its search box or choose a value in one of its filters. Nothing leaves the browser.
	// A filter is a select with data-estasoft-filter="name". A row lists its values for that filter in data-f-name, each between bars, for example "|admin|billing|".
	function narrowList( box ) {
		var scope = box.closest( '[data-estasoft-wrap]' ) || box.closest( '.card' );
		var tables = scope ? scope.querySelectorAll( '[data-estasoft-list]' ) : [];
		if ( ! tables.length ) {
			return;
		}
		var find = scope.querySelector( '[data-estasoft-find]' );
		var filters = scope.querySelectorAll( '[data-estasoft-filter]' );
		var count = scope.querySelector( '[data-estasoft-count]' );
		var word = find ? find.value.trim().toLowerCase() : '';
		var shown = 0;
		Array.prototype.forEach.call( tables, function ( table ) {
			var here = 0;
			Array.prototype.forEach.call( table.querySelectorAll( 'tbody tr' ), function ( row ) {
				var match = '' === word || row.textContent.toLowerCase().indexOf( word ) !== -1;
				Array.prototype.forEach.call( filters, function ( filter ) {
					if ( match && '' !== filter.value ) {
						match = ( row.getAttribute( 'data-f-' + filter.getAttribute( 'data-estasoft-filter' ) ) || '' ).indexOf( '|' + filter.value + '|' ) !== -1;
					}
				} );
				row.hidden = ! match;
				if ( match ) {
					here++;
				}
			} );
			shown += here;
			// A card whose rows are all filtered out is hidden with them, when the page has several cards.
			if ( tables.length > 1 && table.closest( '.card' ) ) {
				table.closest( '.card' ).hidden = 0 === here;
			}
		} );
		if ( count && count.hasAttribute( 'data-total' ) ) {
			count.textContent = ( count.getAttribute( 'data-pattern' ) || '%1$d of %2$d items' )
				.replace( '%1$d', String( shown ) )
				.replace( '%2$d', count.getAttribute( 'data-total' ) );
		}
	}
	// Pressing Enter in a search box only narrows the list. It does not send the filter form around it.
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && event.target && event.target.matches && event.target.matches( '[data-estasoft-find]' ) ) {
			event.preventDefault();
		}
	} );
	[ 'input', 'change' ].forEach( function ( type ) {
		document.addEventListener( type, function ( event ) {
			var target = event.target;
			if ( target && target.matches && ( target.matches( '[data-estasoft-find]' ) || target.matches( '[data-estasoft-filter]' ) ) ) {
				narrowList( target );
			}
		} );
	} );

	// A table that scrolls sideways on a narrow screen can be reached and scrolled from the keyboard.
	function markScrollableTables() {
		Array.prototype.forEach.call( document.querySelectorAll( '.estasoft-hp .estasoft-hp-table' ), function ( table ) {
			if ( table.scrollWidth > table.clientWidth + 1 ) {
				table.setAttribute( 'tabindex', '0' );
			} else {
				table.removeAttribute( 'tabindex' );
			}
		} );
	}
	window.addEventListener( 'resize', markScrollableTables );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', markScrollableTables );
	} else {
		markScrollableTables();
	}

	// Print buttons: the page prints without the admin menu around it.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( target && target.closest && target.closest( '[data-estasoft-print]' ) ) {
			event.preventDefault();
			window.print();
		}
	} );

	// Logo picker on the Setup screen: opens the Media Library and keeps the ID of the chosen image.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var box = target && target.closest ? target.closest( '[data-estasoft-logo]' ) : null;
		if ( ! box ) {
			return;
		}
		var input = box.querySelector( '[data-estasoft-logo-id]' );
		var preview = box.querySelector( '[data-estasoft-logo-preview]' );
		var removeButton = box.querySelector( '[data-estasoft-logo-remove]' );
		if ( target.closest( '[data-estasoft-logo-remove]' ) ) {
			input.value = '0';
			preview.textContent = '';
			removeButton.hidden = true;
			return;
		}
		var choose = target.closest( '[data-estasoft-logo-choose]' );
		if ( ! choose || ! window.wp || ! window.wp.media ) {
			return;
		}
		event.preventDefault();
		var frame = window.wp.media( {
			title: choose.getAttribute( 'data-title' ) || '',
			library: { type: 'image' },
			multiple: false,
			button: { text: choose.getAttribute( 'data-button' ) || '' }
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var image = document.createElement( 'img' );
			image.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
			image.alt = attachment.alt || attachment.title || '';
			input.value = String( attachment.id );
			preview.textContent = '';
			preview.appendChild( image );
			removeButton.hidden = false;
		} );
		frame.open();
	} );
}() );

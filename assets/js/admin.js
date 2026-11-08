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

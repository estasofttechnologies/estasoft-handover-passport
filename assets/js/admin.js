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
}() );

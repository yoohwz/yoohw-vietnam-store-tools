( function() {
	'use strict';

	function copyWithFallback( text ) {
		var textarea;
		var result;

		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.top = '-9999px';
		textarea.style.left = '-9999px';
		document.body.appendChild( textarea );
		textarea.select();

		try {
			result = document.execCommand( 'copy' );
		} finally {
			document.body.removeChild( textarea );
		}

		return result ? Promise.resolve() : Promise.reject( new Error( 'copy_failed' ) );
	}

	function showCopiedState( button ) {
		var originalLabel = button.getAttribute( 'data-vck-copy-label' ) || button.getAttribute( 'aria-label' ) || '';
		var originalTitle = button.getAttribute( 'data-vck-copy-title' );
		var copiedLabel = button.getAttribute( 'data-vck-copied-label' ) || originalLabel;

		if ( originalTitle === null ) {
			originalTitle = button.getAttribute( 'title' ) || '';
			button.setAttribute( 'data-vck-copy-title', originalTitle );
		}

		button.classList.add( 'is-copied' );
		button.setAttribute( 'aria-label', copiedLabel );
		button.removeAttribute( 'title' );

		window.clearTimeout( button.yoohwVietnamStoreToolsCopyTimer );
		button.yoohwVietnamStoreToolsCopyTimer = window.setTimeout( function() {
			button.classList.remove( 'is-copied' );
			button.setAttribute( 'aria-label', originalLabel );
			if ( originalTitle ) {
				button.setAttribute( 'title', originalTitle );
			} else {
				button.removeAttribute( 'title' );
			}
		}, 1600 );
	}

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest( '.vck-vietqr-copy' );
		var value;

		if ( ! button ) {
			return;
		}

		value = button.getAttribute( 'data-vck-copy' ) || '';

		if ( ! value ) {
			return;
		}

		event.preventDefault();

		copyWithFallback( value ).then( function() {
			showCopiedState( button );
		} ).catch( function() {} );
	} );
}() );

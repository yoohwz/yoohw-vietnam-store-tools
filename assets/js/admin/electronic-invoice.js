( function () {
	'use strict';

	var settings = window.yoohwVietnamStoreToolsElectronicInvoice || {};

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-vck-einvoice-save]' );

		if ( ! button ) {
			return;
		}

		var panel = button.closest( '[data-vck-einvoice-panel]' );

		if ( ! panel ) {
			return;
		}

		var fields = panel.querySelectorAll( 'input, select, textarea' );

		for ( var index = 0; index < fields.length; index++ ) {
			if ( fields[ index ].willValidate && ! fields[ index ].checkValidity() ) {
				fields[ index ].reportValidity();
				return;
			}
		}

		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = settings.adminPostUrl || '';
		form.enctype = 'multipart/form-data';
		form.hidden = true;

		function appendValue( name, value ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = value == null ? '' : String( value );
			form.appendChild( input );
		}

		appendValue( 'action', settings.action || '' );
		appendValue( 'order_id', button.getAttribute( 'data-order-id' ) || '' );
		appendValue( 'yoohw_vietnam_store_tools_einvoice_nonce', button.getAttribute( 'data-nonce' ) || '' );

		fields.forEach( function ( field ) {
			if ( field.disabled || ! field.name ) {
				return;
			}

			if ( ( field.type === 'checkbox' || field.type === 'radio' ) && ! field.checked ) {
				return;
			}

			if ( field.type === 'file' ) {
				if ( field.files && field.files.length ) {
					form.appendChild( field );
				}
				return;
			}

			appendValue( field.name, field.value || '' );
		} );

		button.disabled = true;
		button.textContent = settings.saving || button.textContent;
		document.body.appendChild( form );
		form.submit();
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-vck-einvoice-send]' );

		if ( ! button || button.disabled ) {
			return;
		}

		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = settings.adminPostUrl || '';
		form.hidden = true;

		function appendValue( name, value ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = value == null ? '' : String( value );
			form.appendChild( input );
		}

		appendValue( 'action', settings.sendAction || '' );
		appendValue( 'order_id', button.getAttribute( 'data-order-id' ) || '' );
		appendValue( 'yoohw_vietnam_store_tools_einvoice_nonce', button.getAttribute( 'data-nonce' ) || '' );

		button.disabled = true;
		button.textContent = settings.sending || button.textContent;
		document.body.appendChild( form );
		form.submit();
	} );
}() );

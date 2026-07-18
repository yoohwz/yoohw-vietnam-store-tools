( function () {
	'use strict';

	var config = window.yoohwVietnamStoreToolsShipmentTracking || {};

	function appendField( form, name, value ) {
		var input = document.createElement( 'input' );

		input.type = 'hidden';
		input.name = name;
		input.value = value || '';
		form.appendChild( input );
	}

	function submitAction( action, orderId, nonce, values ) {
		var form = document.createElement( 'form' );

		form.method = 'post';
		form.action = config.adminPostUrl || '';
		form.hidden = true;
		appendField( form, 'action', action );
		appendField( form, 'order_id', orderId );
		appendField( form, 'nonce', nonce );

		Object.keys( values || {} ).forEach( function ( key ) {
			appendField( form, key, values[ key ] );
		} );

		document.body.appendChild( form );
		form.submit();
	}

	document.addEventListener( 'click', function ( event ) {
		var addButton = event.target.closest( '[data-vck-add-tracking-event]' );
		var deleteButton = event.target.closest( '[data-vck-delete-tracking-event]' );

		if ( addButton ) {
			event.preventDefault();

			var container = addButton.closest( '[data-vck-tracking-timeline-form]' );
			var values = {};

			if ( container ) {
				container.querySelectorAll( '[data-vck-tracking-field]' ).forEach( function ( field ) {
					values[ field.getAttribute( 'data-vck-tracking-field' ) ] = field.value || '';
				} );
			}

			submitAction(
				'yoohw_vietnam_store_tools_add_tracking_event',
				addButton.getAttribute( 'data-order-id' ),
				addButton.getAttribute( 'data-nonce' ),
				values
			);
			return;
		}

		if ( deleteButton ) {
			event.preventDefault();

			if ( config.confirmDelete && ! window.confirm( config.confirmDelete ) ) {
				return;
			}

			var timeline = deleteButton.closest( '.vck-admin-tracking-timeline' );
			var addTimelineButton = timeline ? timeline.querySelector( '[data-vck-add-tracking-event]' ) : null;

			if ( ! addTimelineButton ) {
				return;
			}

			submitAction(
				'yoohw_vietnam_store_tools_delete_tracking_event',
				addTimelineButton.getAttribute( 'data-order-id' ),
				addTimelineButton.getAttribute( 'data-nonce' ),
				{ event_id: deleteButton.getAttribute( 'data-vck-delete-tracking-event' ) }
			);
		}
	} );
}() );

( function ( blocks, element, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'yoohw-vietnam-store-tools/order-tracking', {
		edit: function () {
			return el(
				'div',
				{ className: 'vck-order-tracking-block-preview' },
				el( 'strong', {}, __( 'Vietnam order tracking', 'yoohw-vietnam-store-tools' ) ),
				el( 'p', {}, __( 'Customers enter an order number and their billing email or phone number to view shipment tracking.', 'yoohw-vietnam-store-tools' ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.i18n ) );

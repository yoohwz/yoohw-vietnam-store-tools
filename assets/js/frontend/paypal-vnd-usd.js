( function( $, wp ) {
	'use strict';

	var config = window.yoohwVietnamStoreToolsPayPalUsd;
	var quoteValid = true;
	var refreshTimer = null;
	var refreshSequence = 0;
	var lastCartFingerprint = '';

	if ( ! config ) {
		return;
	}

	function message() {
		return config.i18n.label
			.replace( '%1$s', config.amount )
			.replace( '%2$s', config.rate );
	}

	function blockPaymentMethod() {
		var stores = window.wc && window.wc.wcBlocksData;
		var paymentStore = stores && stores.paymentStore;
		var selector;

		if ( ! paymentStore || ! wp || ! wp.data ) {
			return '';
		}
		selector = wp.data.select( paymentStore );
		return selector && selector.getActivePaymentMethod ? selector.getActivePaymentMethod() : '';
	}

	function selectedGateway() {
		var checked = document.querySelector( 'input[name="payment_method"]:checked' );
		return checked ? checked.value : blockPaymentMethod();
	}

	function classicDisclosure() {
		var paymentBox = document.querySelector( '.payment_box.payment_method_' + config.gatewayId );
		var note;

		if ( ! paymentBox ) {
			return;
		}
		note = paymentBox.querySelector( '.yoohw-paypal-usd-disclosure' );
		if ( ! note ) {
			note = document.createElement( 'p' );
			note.className = 'yoohw-paypal-usd-disclosure';
			paymentBox.insertBefore( note, paymentBox.firstChild );
		}
		note.textContent = message();
		note.hidden = ! quoteValid || selectedGateway() !== config.gatewayId;
	}

	function blockDisclosure() {
		var active = selectedGateway();
		var containers = document.querySelectorAll( '.wc-block-components-radio-control__option, .wc-block-checkout__payment-method' );

		containers.forEach( function( container ) {
			var control = container.querySelector( 'input[value="' + config.gatewayId + '"]' );
			var note;

			if ( ! control ) {
				return;
			}
			note = container.querySelector( '.yoohw-paypal-usd-disclosure' );
			if ( ! note ) {
				note = document.createElement( 'p' );
				note.className = 'yoohw-paypal-usd-disclosure';
				container.appendChild( note );
			}
			note.textContent = message();
			note.hidden = ! quoteValid || active !== config.gatewayId;
		} );
	}

	function render() {
		classicDisclosure();
		blockDisclosure();
		placeOrderAvailability();
	}

	function placeOrderAvailability() {
		var selected = selectedGateway() === config.gatewayId;
		var buttons = document.querySelectorAll( '#place_order, .wc-block-components-checkout-place-order-button' );

		buttons.forEach( function( button ) {
			if ( selected && ! quoteValid ) {
				if ( ! button.disabled ) {
					button.disabled = true;
					button.setAttribute( 'data-yoohw-paypal-quote-disabled', 'true' );
				}
			} else if ( button.getAttribute( 'data-yoohw-paypal-quote-disabled' ) === 'true' ) {
				button.disabled = false;
				button.removeAttribute( 'data-yoohw-paypal-quote-disabled' );
			}
		} );
	}

	function refreshQuote() {
		var sequence;

		if ( ! config.ajaxUrl || ! config.nonce ) {
			return;
		}
		quoteValid = false;
		sequence = ++refreshSequence;
		render();
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function() {
			$.post( config.ajaxUrl, {
				action: 'yoohw_paypal_usd_quote',
				nonce: config.nonce
			} ).done( function( response ) {
				if ( sequence !== refreshSequence ) {
					return;
				}
				if ( response && response.success && response.data ) {
					config.amount = response.data.amount;
					config.rate = response.data.rate;
					quoteValid = true;
				} else {
					quoteValid = false;
				}
				render();
			} ).fail( function() {
				if ( sequence !== refreshSequence ) {
					return;
				}
				quoteValid = false;
				render();
			} );
		}, 250 );
	}

	function blocksCartFingerprint() {
		var stores = window.wc && window.wc.wcBlocksData;
		var cartStore = stores && stores.cartStore;
		var selector;
		var cart;

		if ( ! cartStore || ! wp || ! wp.data ) {
			return '';
		}
		selector = wp.data.select( cartStore );
		cart = selector && selector.getCartData ? selector.getCartData() : null;
		return cart && cart.totals ? JSON.stringify( cart.totals ) : '';
	}

	$( document.body ).on( 'updated_checkout', refreshQuote );
	$( document.body ).on( 'payment_method_selected change', render );
	$( 'form.checkout' ).on( 'checkout_place_order_' + config.gatewayId, function() {
		return quoteValid;
	} );
	document.addEventListener( 'change', render );

	if ( wp && wp.data && wp.data.subscribe ) {
		wp.data.subscribe( function() {
			var fingerprint = blocksCartFingerprint();
			render();
			if ( fingerprint && lastCartFingerprint && fingerprint !== lastCartFingerprint ) {
				refreshQuote();
			}
			lastCartFingerprint = fingerprint || lastCartFingerprint;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', render );
} )( jQuery, window.wp );

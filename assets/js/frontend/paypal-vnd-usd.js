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
		note.textContent = quoteValid ? message() : config.i18n.quoteError;
		note.hidden = selectedGateway() !== config.gatewayId;
	}

	function blockDisclosure() {
		var active = selectedGateway();
		var controls = document.querySelectorAll( 'input[value="' + config.gatewayId + '"]' );

		controls.forEach( function( control ) {
			var container = control.closest( '.wc-block-components-radio-control__option, .wc-block-checkout__payment-method' );
			var note;

			if ( ! container ) {
				return;
			}
			note = container.querySelector( '.yoohw-paypal-usd-disclosure' );
			if ( ! note ) {
				note = document.createElement( 'p' );
				note.className = 'yoohw-paypal-usd-disclosure';
				container.appendChild( note );
			}
			note.textContent = quoteValid ? message() : config.i18n.quoteError;
			note.hidden = active !== config.gatewayId;
		} );
	}

	function render() {
		classicDisclosure();
		blockDisclosure();
		placeOrderAvailability();
	}

	function placeOrderAvailability() {
		var selected = selectedGateway() === config.gatewayId;
		var stores = window.wc && window.wc.wcBlocksData;
		var validationStore = stores && stores.validationStore;
		var errorKey = 'yoohw-paypal-usd-quote';
		var currentError;

		if ( validationStore && wp && wp.data ) {
			currentError = wp.data.select( validationStore ).getValidationError( errorKey );
			if ( selected && ! quoteValid && ! currentError ) {
				var errors = {};
				errors[ errorKey ] = { message: config.i18n.quoteError, hidden: true };
				wp.data.dispatch( validationStore ).setValidationErrors( errors );
			} else if ( ( ! selected || quoteValid ) && currentError ) {
				wp.data.dispatch( validationStore ).clearValidationError( errorKey );
			}
		}
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
			var changed = fingerprint && lastCartFingerprint && fingerprint !== lastCartFingerprint;
			lastCartFingerprint = fingerprint || lastCartFingerprint;
			render();
			if ( changed ) {
				refreshQuote();
			}
		} );
	}

	// Blocks can mount its payment rows after the last store notification.
	var checkoutRoot = document.querySelector( '.wp-block-woocommerce-checkout' );
	if ( checkoutRoot && window.MutationObserver ) {
		new window.MutationObserver( function( records ) {
			var selector = 'input[value="' + config.gatewayId + '"]';
			var paymentRowAdded = records.some( function( record ) {
				return Array.prototype.some.call( record.addedNodes, function( node ) {
					return node.nodeType === 1 && ( node.matches( selector ) || node.querySelector( selector ) );
				} );
			} );
			if ( paymentRowAdded ) {
				render();
			}
		} ).observe( checkoutRoot, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', render );
	} else {
		render();
	}
} )( jQuery, window.wp );

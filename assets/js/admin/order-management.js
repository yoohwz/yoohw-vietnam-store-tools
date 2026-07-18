( function ( $ ) {
	'use strict';

	var config = window.yoohwVietnamStoreToolsOrderManagement || {};
	var carriers = Array.isArray( config.carriers ) ? config.carriers : [];
	var wardRequestId = 0;

	function resetWardOptions( $ward, label, disabled ) {
		$ward.empty().append( $( '<option>', { value: '', text: label } ) ).prop( 'disabled', disabled );
	}

	function updateWardOptions() {
		var province = $( '#vck-order-filter-province' ).val() || '';
		var $ward = $( '#vck-order-filter-ward' );
		var requestId = ++wardRequestId;

		if ( ! $ward.length ) {
			return;
		}

		if ( ! province ) {
			resetWardOptions( $ward, config.i18n.selectProvinceFirst, true );
			return;
		}

		resetWardOptions( $ward, config.i18n.loadingWards, true );

		$.post( config.ajaxUrl, {
			action: 'yoohw_vietnam_store_tools_wards',
			nonce: config.wardsNonce || '',
			state: province
		} ).done( function ( response ) {
			var wards = response && response.success && response.data ? response.data.wards : null;

			if ( requestId !== wardRequestId || ! wards || typeof wards !== 'object' ) {
				return;
			}

			resetWardOptions( $ward, config.i18n.selectWard, false );

			$.each( wards, function ( value, label ) {
				$ward.append( $( '<option>', { value: value, text: label } ) );
			} );
		} ).fail( function () {
			if ( requestId === wardRequestId ) {
				resetWardOptions( $ward, config.i18n.loadWardsError, false );
			}
		} );
	}

	function createCarrierSelect( position ) {
		var $select = $( '<select>', {
			class: 'vck-order-bulk-carrier',
			name: 'vck_bulk_carrier_' + position,
			'aria-label': config.i18n.selectCarrier
		} );

		$select.append( $( '<option>', { value: '', text: config.i18n.selectCarrier } ) );

		carriers.forEach( function ( carrier ) {
			$select.append( $( '<option>', { value: carrier.value, text: carrier.label } ) );
		} );

		return $select;
	}

	function setupBulkCarrierSelect( actionSelector, position ) {
		var $action = $( actionSelector );

		if ( ! $action.length || ! config.carrierAction ) {
			return;
		}

		var $carrier = createCarrierSelect( position ).insertAfter( $action );

		function toggleCarrier() {
			var visible = $action.val() === config.carrierAction;

			$carrier.prop( 'disabled', ! visible ).toggle( visible );
		}

		$action.on( 'change', toggleCarrier );
		toggleCarrier();
	}

	function moveVietnamFiltersAfterCoreButton() {
		var $filters = $( '.tablenav.top details.vck-order-filters' );
		var $coreFilterButton = $( '#order-query-submit, #post-query-submit' ).first();

		if ( $filters.length && $coreFilterButton.length ) {
			$filters.insertAfter( $coreFilterButton );
		}
	}

	$( function () {
		moveVietnamFiltersAfterCoreButton();
		$( '#vck-order-filter-province' ).on( 'change', updateWardOptions );
		setupBulkCarrierSelect( 'select[name="action"]', 'top' );
		setupBulkCarrierSelect( 'select[name="action2"]', 'bottom' );
	} );
}( jQuery ) );

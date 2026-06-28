/* global yoohwVietnamStoreToolsAddressFields */
jQuery( function( $ ) {
	'use strict';

	if ( typeof yoohwVietnamStoreToolsAddressFields === 'undefined' ) {
		return;
	}

	var params = yoohwVietnamStoreToolsAddressFields;
	var addressTypes = Array.isArray( params.addressTypes ) && params.addressTypes.length ? params.addressTypes : [ 'billing', 'shipping', 'calc_shipping' ];
	var wardRequests = {};
	var wardLoadAllowed = {};
	var wardCacheTtl = parseInt( params.wardsCacheTtl, 10 );
	var wardCachePrefix = 'yoohwVietnamStoreToolsWards:' + ( params.country || 'VN' ) + ':' + ( params.wardsCacheVersion || '1' ) + ':';
	var checkoutUpdateTimer = null;
	var checkoutUpdateDelay = 700;

	wardCacheTtl = wardCacheTtl > 0 ? wardCacheTtl * 1000 : 7 * 24 * 60 * 60 * 1000;

	if ( ! params.lazyLoadWards ) {
		$.each( addressTypes, function( index, type ) {
			wardLoadAllowed[ type ] = true;
		} );
	}

	function getField( type, field ) {
		return $( '#' + type + '_' + field );
	}

	function getFieldRow( type, field ) {
		return $( '#' + type + '_' + field + '_field' );
	}

	function shouldHideCountryField() {
		return !! ( params.hideCountry && params.singleCountry );
	}

	function getAddressTypeFromFieldId( id ) {
		if ( id.indexOf( 'calc_shipping_' ) === 0 ) {
			return 'calc_shipping';
		}

		if ( id.indexOf( 'shipping_' ) === 0 ) {
			return 'shipping';
		}

		return 'billing';
	}

	function isCheckoutPage() {
		return $( 'form.checkout' ).length > 0 && typeof window.wc_checkout_params !== 'undefined';
	}

	function isCheckoutAddressUpdateField( element ) {
		var id = element && element.id ? element.id : '';

		return [
			'billing_state',
			'billing_city',
			'billing_address_1',
			'billing_address_2',
			'shipping_state',
			'shipping_city',
			'shipping_address_1',
			'shipping_address_2'
		].indexOf( id ) !== -1;
	}

	function shouldBlockCheckoutAddressEvent( event ) {
		var type = event && event.type ? event.type : '';
		var element = event && event.target ? event.target : null;
		var id = element && element.id ? element.id : '';

		if ( ! isCheckoutPage() || ! isCheckoutAddressUpdateField( element ) ) {
			return false;
		}

		if ( type === 'change' && /_(state|city)$/.test( id ) ) {
			return true;
		}

		if ( type === 'keydown' && /_address_[12]$/.test( id ) ) {
			return true;
		}

		if ( ( type === 'blur' || type === 'focusout' || type === 'change' ) && /_address_[12]$/.test( id ) ) {
			return true;
		}

		return false;
	}

	function hasCompleteProvinceWard( type ) {
		var country = getEffectiveCountry( type );
		var state = normalizeProvinceCode( getField( type, 'state' ).val() || getSelectedState( type ) );
		var ward = normalizeWardCode( getField( type, 'city' ).val() || getSelectedWard( type ) );

		if ( country && country !== params.country ) {
			return true;
		}

		return !! ( state && ward );
	}

	function scheduleCheckoutAddressUpdate( type, currentTarget ) {
		if ( ! isCheckoutPage() || ! hasCompleteProvinceWard( type ) ) {
			return;
		}

		window.clearTimeout( checkoutUpdateTimer );
		checkoutUpdateTimer = window.setTimeout( function() {
			$( document.body ).trigger( 'update_checkout', {
				current_target: currentTarget || null
			} );
		}, checkoutUpdateDelay );
	}

	function handleCheckoutAddressEvent( event ) {
		var element = event.target;
		var type = getAddressTypeFromFieldId( element.id );

		if ( event.type === 'change' && element.id.indexOf( '_state' ) !== -1 ) {
			setSelectedState( type, $( element ).val() );
			setSelectedWard( type, '' );
			getField( type, 'city' ).val( '' ).attr( 'data-selected-value', '' );
			allowWardLoad( type );
			syncFields();
			return;
		}

		if ( event.type === 'change' && element.id.indexOf( '_city' ) !== -1 ) {
			allowWardLoad( type );
			setSelectedWard( type, $( element ).val() );
			$( element ).attr( 'data-selected-value', $( element ).val() || '' );
			syncFields();
		}

		scheduleCheckoutAddressUpdate( type, element );
	}

	function captureCheckoutAddressEvent( event ) {
		if ( ! shouldBlockCheckoutAddressEvent( event ) ) {
			return;
		}

		event.stopImmediatePropagation();
		handleCheckoutAddressEvent( event );
	}

	function bindCheckoutAddressUpdateGuard() {
		if ( ! isCheckoutPage() ) {
			return;
		}

		document.addEventListener( 'change', captureCheckoutAddressEvent, true );
		document.addEventListener( 'keydown', captureCheckoutAddressEvent, true );
		document.addEventListener( 'blur', captureCheckoutAddressEvent, true );
		document.addEventListener( 'focusout', captureCheckoutAddressEvent, true );

		$( document.body ).on( 'input', '#billing_address_1, #billing_address_2, #shipping_address_1, #shipping_address_2', function() {
			scheduleCheckoutAddressUpdate( getAddressTypeFromFieldId( this.id ), this );
		} );
	}

	function allowWardLoad( type ) {
		wardLoadAllowed[ type ] = true;
	}

	function shouldDeferWardLoad( type, state ) {
		if ( ! params.lazyLoadWards || ! state || wardLoadAllowed[ type ] ) {
			return false;
		}

		return ! ( params.wards && params.wards[ state ] );
	}

	function getSelectedState( type ) {
		if ( params.selected && params.selected[ type ] && params.selected[ type ].state ) {
			return normalizeProvinceCode( params.selected[ type ].state );
		}

		return '';
	}

	function setSelectedState( type, value ) {
		value = normalizeProvinceCode( value );

		if ( ! params.selected ) {
			params.selected = {};
		}

		if ( ! params.selected[ type ] ) {
			params.selected[ type ] = {};
		}

		params.selected[ type ].state = value || '';
	}

	function getSelectedWard( type ) {
		if ( params.selected && params.selected[ type ] && params.selected[ type ].city ) {
			return normalizeWardCode( params.selected[ type ].city );
		}

		return '';
	}

	function setSelectedWard( type, value ) {
		value = normalizeWardCode( value );

		if ( ! params.selected ) {
			params.selected = {};
		}

		if ( ! params.selected[ type ] ) {
			params.selected[ type ] = {};
		}

		params.selected[ type ].city = value || '';
	}

	function normalizeWardCode( value ) {
		value = value || '';

		if ( /^[0-9]+$/.test( value ) ) {
			while ( value.length < 5 ) {
				value = '0' + value;
			}
		}

		return value;
	}

	function getEffectiveCountry( type ) {
		if ( shouldHideCountryField() ) {
			return params.singleCountry;
		}

		return getField( type, 'country' ).val() || '';
	}

	function normalizeProvinceCode( value ) {
		value = value || '';

		if ( /^[0-9]+$/.test( value ) ) {
			while ( value.length < 2 ) {
				value = '0' + value;
			}
		}

		return value;
	}

	function updateRequiredState( $row, isRequired ) {
		var $label = $row.find( 'label' ).first();

		$row.toggleClass( 'validate-required', isRequired );

		if ( isRequired ) {
			$row.find( 'label .optional' ).remove();

			if ( ! $label.find( '.required' ).length ) {
				$label.append( '&nbsp;<span class="required" aria-hidden="true">*</span>' );
			}
		} else {
			$row.find( 'label .required' ).remove();

			if ( ! $label.find( '.optional' ).length ) {
				$label.append( '&nbsp;<span class="optional">(' + params.i18n.optional + ')</span>' );
			}
		}
	}

	function enhanceSelect( $select ) {
		if ( ! $.fn.selectWoo || ! $select.is( ':visible' ) ) {
			return;
		}

		if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
			$select.trigger( 'change.select2' );
			return;
		}

		$select.selectWoo( {
			allowClear: false,
			placeholder: $select.attr( 'data-placeholder' ) || '',
			width: '100%'
		} );
	}

	function destroySelectEnhancement( $select ) {
		if ( $.fn.selectWoo && $select.hasClass( 'select2-hidden-accessible' ) ) {
			$select.selectWoo( 'destroy' );
		}
	}

	function getWardStorage() {
		try {
			if ( window.sessionStorage ) {
				return window.sessionStorage;
			}
		} catch ( error ) {}

		try {
			if ( window.localStorage ) {
				return window.localStorage;
			}
		} catch ( error ) {}

		return null;
	}

	function getCachedWardsForState( state ) {
		var storage = getWardStorage();
		var cached;
		var parsed;

		if ( ! storage ) {
			return null;
		}

		try {
			cached = storage.getItem( wardCachePrefix + state );

			if ( ! cached ) {
				return null;
			}

			parsed = JSON.parse( cached );

			if ( ! parsed || parsed.state !== state || ! parsed.wards || typeof parsed.wards !== 'object' ) {
				storage.removeItem( wardCachePrefix + state );
				return null;
			}

			if ( parsed.expires && parsed.expires < new Date().getTime() ) {
				storage.removeItem( wardCachePrefix + state );
				return null;
			}

			return parsed.wards;
		} catch ( error ) {
			return null;
		}
	}

	function cacheWardsForState( state, wards ) {
		var storage = getWardStorage();

		if ( ! storage || ! wards || typeof wards !== 'object' ) {
			return;
		}

		try {
			storage.setItem( wardCachePrefix + state, JSON.stringify( {
				state: state,
				wards: wards,
				expires: new Date().getTime() + wardCacheTtl
			} ) );
		} catch ( error ) {}
	}

	function setKnownWardsForState( state, wards ) {
		if ( ! params.wards || typeof params.wards !== 'object' || Array.isArray( params.wards ) ) {
			params.wards = {};
		}

		params.wards[ state ] = wards;
	}

	function getKnownWardsForState( state ) {
		var wards;

		state = normalizeProvinceCode( state );

		if ( ! state ) {
			return null;
		}

		if ( params.wards && params.wards[ state ] ) {
			return params.wards[ state ];
		}

		wards = getCachedWardsForState( state );

		if ( wards ) {
			setKnownWardsForState( state, wards );
			return wards;
		}

		return null;
	}

	function getWardsForState( state, callback ) {
		var knownWards;

		state = normalizeProvinceCode( state );

		if ( ! state ) {
			callback( {} );
			return;
		}

		knownWards = getKnownWardsForState( state );

		if ( knownWards ) {
			callback( knownWards );
			return;
		}

		if ( ! params.ajaxUrl ) {
			callback( {} );
			return;
		}

		if ( wardRequests[ state ] ) {
			wardRequests[ state ].push( callback );
			return;
		}

		wardRequests[ state ] = [ callback ];

		$.ajax( {
			url: params.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'yoohw_vietnam_store_tools_wards',
				state: state,
				nonce: params.wardsNonce || ''
			}
		} ).done( function( response ) {
			var wards = response && response.success && response.data && response.data.wards ? response.data.wards : {};
			var callbacks = wardRequests[ state ] || [];

			setKnownWardsForState( state, wards );
			cacheWardsForState( state, wards );
			delete wardRequests[ state ];

			$.each( callbacks, function( index, queuedCallback ) {
				queuedCallback( wards );
			} );
		} ).fail( function() {
			var callbacks = wardRequests[ state ] || [];

			delete wardRequests[ state ];

			$.each( callbacks, function( index, queuedCallback ) {
				queuedCallback( {} );
			} );
		} );
	}

	function syncCountryField( type ) {
		var $field = getField( type, 'country' );
		var $row = getFieldRow( type, 'country' );
		var previousValue;

		if ( ! shouldHideCountryField() ) {
			$row.removeClass( 'vck-hidden-country-field' ).attr( 'aria-hidden', 'false' ).show();
			return;
		}

		$row.addClass( 'vck-hidden-country-field' ).attr( 'aria-hidden', 'true' ).hide();

		if ( ! $field.length ) {
			return;
		}

		previousValue = $field.val() || '';
		$field.val( params.singleCountry );

		if ( $field.val() === params.singleCountry && previousValue !== params.singleCountry ) {
			$field.trigger( 'change' );
		}
	}

	function ensureWardSelect( type ) {
		var id = type + '_city';
		var $field = getField( type, 'city' );
		var $row = getFieldRow( type, 'city' );

		if ( ! $field.length || ! $row.length ) {
			return $();
		}

		if ( $field.is( 'select' ) ) {
			$field.addClass( 'vck-ward-select' );
			$field.attr( 'data-placeholder', params.i18n.selectWard );
			$field.attr( 'data-selected-value', $field.val() || $field.attr( 'data-selected-value' ) || getSelectedWard( type ) );
			return $field;
		}

		var $select = $( '<select></select>' );

		$select.attr( {
			id: id,
			name: $field.attr( 'name' ) || id,
			'data-placeholder': params.i18n.selectWard,
			'data-selected-value': $field.val() || $field.attr( 'data-selected-value' ) || getSelectedWard( type ),
			autocomplete: $field.attr( 'autocomplete' ) || 'address-level2'
		} );

		$select.addClass( 'select vck-ward-select' );
		destroySelectEnhancement( $field );
		$field.replaceWith( $select );

		return getField( type, 'city' );
	}

	function restoreWardTextInput( type ) {
		var id = type + '_city';
		var $field = getField( type, 'city' );
		var $row = getFieldRow( type, 'city' );

		if ( ! $field.length || ! $row.length || ! $field.is( 'select' ) || ! $field.hasClass( 'vck-ward-select' ) ) {
			return;
		}

		var $input = $( '<input type="text" />' );

		$input.attr( {
			id: id,
			name: $field.attr( 'name' ) || id,
			placeholder: '',
			autocomplete: $field.attr( 'autocomplete' ) || 'address-level2'
		} );

		$input.addClass( 'input-text' );
		destroySelectEnhancement( $field );
		$field.replaceWith( $input );
	}

	function fillWardOptions( type ) {
		syncCountryField( type );

		var country = getEffectiveCountry( type );
		var $state = getField( type, 'state' );
		var state = normalizeProvinceCode( $state.val() || getSelectedState( type ) );
		var $row = getFieldRow( type, 'city' );

		if ( country !== params.country ) {
			restoreWardTextInput( type );
			return;
		}

		if ( state && $state.is( 'select' ) && $state.val() !== state && $state.find( 'option[value="' + state + '"]' ).length ) {
			$state.val( state ).trigger( 'change.select2' );
		}

		setSelectedState( type, state );

		var $ward = ensureWardSelect( type );

		if ( ! $ward.length || ! $row.length ) {
			return;
		}

		var previousValue = normalizeWardCode( $ward.val() || $ward.attr( 'data-selected-value' ) || getSelectedWard( type ) );
		var previousLabel = previousValue ? $ward.find( 'option:selected' ).text() : '';
		var placeholder = state ? params.i18n.selectWard : params.i18n.selectProvinceFirst;

		function renderWardOptions( wards ) {
			var currentState = normalizeProvinceCode( $state.val() || getSelectedState( type ) );

			if ( state !== currentState ) {
				return;
			}

			destroySelectEnhancement( $ward );
			$ward.empty();
			$ward.append( $( '<option></option>' ).attr( 'value', '' ).text( placeholder ) );

			$.each( wards, function( code, label ) {
				$ward.append( $( '<option></option>' ).attr( 'value', code ).text( label ) );
			} );

			if ( previousValue && wards[ previousValue ] ) {
				$ward.val( previousValue );
				setSelectedWard( type, previousValue );
			} else {
				$ward.val( '' );
				setSelectedWard( type, '' );
			}

			$ward.attr( 'data-selected-value', $ward.val() || '' );
			$ward.prop( 'disabled', ! state );
			$row.show();
			updateRequiredState( $row, true );
			enhanceSelect( $ward );
		}

		if ( state && shouldDeferWardLoad( type, state ) ) {
			destroySelectEnhancement( $ward );
			$ward.empty();

			if ( previousValue ) {
				$ward.append( $( '<option></option>' ).attr( 'value', previousValue ).text( previousLabel || previousValue ) );
				$ward.val( previousValue );
			} else {
				$ward.append( $( '<option></option>' ).attr( 'value', '' ).text( placeholder ) );
				$ward.val( '' );
			}

			$ward.attr( 'data-selected-value', previousValue || '' );
			$ward.prop( 'disabled', false );
			$row.show();
			updateRequiredState( $row, true );
			enhanceSelect( $ward );
			return;
		}

		if ( state && ! getKnownWardsForState( state ) ) {
			destroySelectEnhancement( $ward );
			$ward.empty();
			$ward.append( $( '<option></option>' ).attr( 'value', '' ).text( params.i18n.loadingWards || params.i18n.selectWard ) );
			$ward.prop( 'disabled', true );
			$row.show();
			updateRequiredState( $row, true );
			enhanceSelect( $ward );

			getWardsForState( state, renderWardOptions );
			return;
		}

		getWardsForState( state, renderWardOptions );
	}

	function syncFields() {
		$.each( addressTypes, function( index, type ) {
			fillWardOptions( type );
		} );
	}

	$( document.body ).on( 'country_to_state_changed updated_checkout updated_wc_div updated_cart_totals wc_address_i18n_ready', syncFields );
	$( document.body ).on( 'change', '#billing_country, #shipping_country, #calc_shipping_country, #billing_state, #shipping_state, #calc_shipping_state', function() {
		var type = getAddressTypeFromFieldId( this.id );

		if ( this.id.indexOf( '_state' ) !== -1 ) {
			setSelectedState( type, $( this ).val() );
			allowWardLoad( type );
		}

		syncFields();
	} );
	$( document.body ).on( 'click focus mousedown', '#billing_city, #shipping_city, #calc_shipping_city', function() {
		var type = getAddressTypeFromFieldId( this.id );

		allowWardLoad( type );
		syncFields();
	} );
	$( document.body ).on( 'change', '#billing_city, #shipping_city, #calc_shipping_city', function() {
		var type = getAddressTypeFromFieldId( this.id );
		allowWardLoad( type );
		setSelectedWard( type, $( this ).val() );
		$( this ).attr( 'data-selected-value', $( this ).val() || '' );
	} );
	$( document.body ).on( 'change', '#ship-to-different-address-checkbox', syncFields );
	$( document.body ).on( 'click', '.shipping-calculator-button', function() {
		allowWardLoad( 'calc_shipping' );
		window.setTimeout( syncFields, 0 );
	} );

	bindCheckoutAddressUpdateGuard();
	syncFields();
} );

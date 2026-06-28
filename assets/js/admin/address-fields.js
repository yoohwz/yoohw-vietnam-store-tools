/* global yoohwVietnamStoreToolsAdminAddressFields */
jQuery( function( $ ) {
	'use strict';

	if ( typeof yoohwVietnamStoreToolsAdminAddressFields === 'undefined' ) {
		return;
	}

	var params = yoohwVietnamStoreToolsAdminAddressFields;
	var profileTypes = [ 'billing', 'shipping' ];

	function normalizeProvinceCode( value ) {
		value = value || '';

		if ( /^[0-9]+$/.test( value ) ) {
			while ( value.length < 2 ) {
				value = '0' + value;
			}
		}

		return value;
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

	function getSelected( type, field ) {
		if ( params.selected && params.selected[ type ] && params.selected[ type ][ field ] ) {
			return 'state' === field ? normalizeProvinceCode( params.selected[ type ][ field ] ) : normalizeWardCode( params.selected[ type ][ field ] );
		}

		return '';
	}

	function setSelected( type, field, value ) {
		value = 'state' === field ? normalizeProvinceCode( value ) : normalizeWardCode( value );

		if ( ! params.selected ) {
			params.selected = {};
		}

		if ( ! params.selected[ type ] ) {
			params.selected[ type ] = {};
		}

		params.selected[ type ][ field ] = value || '';
	}

	function parseStoreLocation() {
		var value = $( '#woocommerce_default_country' ).val() || '';
		var parts = value.split( ':' );
		var country = parts[ 0 ] || '';
		var state = parts.length > 1 ? parts[ 1 ] : '';

		if ( state === '*' ) {
			state = '';
		}

		return {
			country: country,
			state: normalizeProvinceCode( state )
		};
	}

	function setLabel( $row, label ) {
		var $label = $row.find( 'label' ).first();

		if ( $label.length ) {
			$label.contents().filter( function() {
				return this.nodeType === 3;
			} ).first().replaceWith( label + ' ' );
		}
	}

	function enhanceSelect( $select ) {
		if ( ! $.fn.selectWoo || ! $select.length || ! $select.is( ':visible' ) ) {
			return;
		}

		if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
			$select.trigger( 'change.select2' );
			return;
		}

		$select.selectWoo( {
			allowClear: false,
			placeholder: $select.attr( 'data-placeholder' ) || params.i18n.selectWard,
			width: 'resolve'
		} );
	}

	function destroySelectEnhancement( $select ) {
		if ( $.fn.selectWoo && $select.hasClass( 'select2-hidden-accessible' ) ) {
			$select.selectWoo( 'destroy' );
		}
	}

	function fillOptions( $select, type, state ) {
		var previousValue = normalizeWardCode( $select.val() || $select.attr( 'data-selected-value' ) || getSelected( type, 'city' ) );
		var wards = state && params.wards[ state ] ? params.wards[ state ] : {};
		var placeholder = state ? params.i18n.selectWard : params.i18n.selectProvinceFirst;

		destroySelectEnhancement( $select );
		$select.empty();
		$select.append( $( '<option></option>' ).attr( 'value', '' ).text( placeholder ) );

		$.each( wards, function( code, label ) {
			$select.append( $( '<option></option>' ).attr( 'value', code ).text( label ) );
		} );

		if ( previousValue && wards[ previousValue ] ) {
			$select.val( previousValue );
			setSelected( type, 'city', previousValue );
		} else {
			$select.val( '' );
			setSelected( type, 'city', '' );
		}

		$select.attr( 'data-selected-value', $select.val() || '' );
		$select.prop( 'disabled', ! state );
		enhanceSelect( $select );
	}

	function ensureSelectField( $field, args ) {
		if ( $field.is( 'select' ) ) {
			$field.addClass( args.className );
			$field.attr( 'data-placeholder', params.i18n.selectWard );
			$field.attr( 'data-selected-value', $field.val() || $field.attr( 'data-selected-value' ) || args.selected );
			return $field;
		}

		var $select = $( '<select></select>' );

		$select.attr( {
			id: $field.attr( 'id' ),
			name: $field.attr( 'name' ) || $field.attr( 'id' ),
			'data-placeholder': params.i18n.selectWard,
			'data-selected-value': $field.val() || $field.attr( 'data-selected-value' ) || args.selected
		} );

		if ( args.style ) {
			$select.attr( 'style', args.style );
		}

		$select.addClass( args.className );
		destroySelectEnhancement( $field );
		$field.replaceWith( $select );

		return $( '#' + args.id );
	}

	function restoreTextField( $field, args ) {
		if ( ! $field.length || ! $field.is( 'select' ) || ! $field.hasClass( args.markerClass ) ) {
			return;
		}

		var $input = $( '<input type="text" />' );

		$input.attr( {
			id: $field.attr( 'id' ),
			name: $field.attr( 'name' ) || $field.attr( 'id' ),
			value: $field.val() || $field.attr( 'data-selected-value' ) || ''
		} );

		if ( args.style ) {
			$input.attr( 'style', args.style );
		}

		$input.addClass( args.inputClass );
		destroySelectEnhancement( $field );
		$field.replaceWith( $input );
	}

	function syncStoreSettings() {
		var $city = $( '#woocommerce_store_city' );

		if ( ! $city.length ) {
			return;
		}

		var location = parseStoreLocation();
		var $cityRow = $city.closest( 'tr' );

		if ( location.country !== params.country ) {
			restoreTextField( $city, {
				markerClass: 'vck-admin-store-ward-select',
				inputClass: 'regular-text',
				style: 'min-width:50px;'
			} );
			setLabel( $( '#woocommerce_store_city' ).closest( 'tr' ), params.i18n.cityLabel );
			return;
		}

		setSelected( 'store', 'state', location.state );
		setLabel( $cityRow, params.i18n.wardLabel );

		$city = ensureSelectField( $city, {
			id: 'woocommerce_store_city',
			className: 'wc-enhanced-select vck-admin-store-ward-select',
			selected: getSelected( 'store', 'city' ),
			style: 'min-width:350px;'
		} );

		fillOptions( $city, 'store', location.state );
	}

	function syncProfileAddress( type ) {
		var $country = $( '#' + type + '_country' );
		var $state = $( '#' + type + '_state' );
		var $city = $( '#' + type + '_city' );

		if ( ! $country.length || ! $city.length ) {
			return;
		}

		var country = $country.val();
		var state = normalizeProvinceCode( $state.val() || getSelected( type, 'state' ) );
		var $cityRow = $city.closest( 'tr' );

		if ( country !== params.country ) {
			restoreTextField( $city, {
				markerClass: 'vck-admin-profile-ward-select',
				inputClass: 'regular-text',
				style: 'width: 25em;'
			} );
			setLabel( $state.closest( 'tr' ), params.i18n.stateLabel );
			setLabel( $( '#' + type + '_city' ).closest( 'tr' ), params.i18n.cityLabel );
			return;
		}

		if ( state && $state.is( 'select' ) && $state.val() !== state && $state.find( 'option[value="' + state + '"]' ).length ) {
			$state.val( state ).trigger( 'change.select2' );
		}

		setSelected( type, 'state', state );
		setLabel( $state.closest( 'tr' ), params.i18n.provinceLabel );
		setLabel( $cityRow, params.i18n.wardLabel );

		$city = ensureSelectField( $city, {
			id: type + '_city',
			className: 'vck-admin-profile-ward-select',
			selected: getSelected( type, 'city' ),
			style: 'width: 25em;'
		} );

		fillOptions( $city, type, state );
	}

	function syncProfileFields() {
		$.each( profileTypes, function( index, type ) {
			syncProfileAddress( type );
		} );
	}

	function syncAll() {
		syncStoreSettings();
		syncProfileFields();
	}

	$( document.body ).on( 'country-change.woocommerce', function() {
		window.setTimeout( syncAll, 0 );
	} );

	$( document.body ).on( 'change', '#woocommerce_default_country', syncStoreSettings );
	$( document.body ).on( 'change', '#woocommerce_store_city', function() {
		setSelected( 'store', 'city', $( this ).val() );
		$( this ).attr( 'data-selected-value', normalizeWardCode( $( this ).val() ) );
	} );

	$( document.body ).on( 'change', '#billing_country, #shipping_country, #billing_state, #shipping_state', function() {
		var type = this.id.indexOf( 'shipping_' ) === 0 ? 'shipping' : 'billing';

		if ( this.id.indexOf( '_state' ) !== -1 ) {
			setSelected( type, 'state', $( this ).val() );
		}

		window.setTimeout( syncProfileFields, 0 );
	} );

	$( document.body ).on( 'change', '#billing_city, #shipping_city', function() {
		var type = this.id.indexOf( 'shipping_' ) === 0 ? 'shipping' : 'billing';
		setSelected( type, 'city', $( this ).val() );
		$( this ).attr( 'data-selected-value', normalizeWardCode( $( this ).val() ) );
	} );

	$( document.body ).on( 'click', 'button.js_copy-billing', function() {
		window.setTimeout( function() {
			setSelected( 'shipping', 'city', $( '#billing_city' ).val() || getSelected( 'billing', 'city' ) );
			setSelected( 'shipping', 'state', $( '#billing_state' ).val() || getSelected( 'billing', 'state' ) );
			syncProfileFields();
		}, 0 );
	} );

	syncAll();
} );

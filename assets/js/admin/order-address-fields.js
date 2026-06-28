/* global yoohwVietnamStoreToolsAdminOrderFields */
jQuery( function( $ ) {
	'use strict';

	if ( typeof yoohwVietnamStoreToolsAdminOrderFields === 'undefined' ) {
		return;
	}

	var params = yoohwVietnamStoreToolsAdminOrderFields;
	var addressTypes = [ 'billing', 'shipping' ];

	function getField( type, field ) {
		return $( '#_' + type + '_' + field );
	}

	function getFieldRow( type, field ) {
		return getField( type, field ).closest( 'p.form-field' );
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

	function normalizeProvinceCode( value ) {
		value = value || '';

		if ( /^[0-9]+$/.test( value ) ) {
			while ( value.length < 2 ) {
				value = '0' + value;
			}
		}

		return value;
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

	function setRowLabel( $row, label ) {
		var $label = $row.find( 'label' ).first();

		if ( $label.length ) {
			$label.text( label );
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

	function ensureWardSelect( type ) {
		var id = '_' + type + '_city';
		var $field = getField( type, 'city' );
		var $row = getFieldRow( type, 'city' );

		if ( ! $field.length || ! $row.length ) {
			return $();
		}

		setRowLabel( $row, params.i18n.wardLabel );

		if ( $field.is( 'select' ) ) {
			$field.addClass( 'select short vck-admin-ward-select' );
			$field.attr( 'data-placeholder', params.i18n.selectWard );
			$field.attr( 'data-selected-value', $field.val() || $field.attr( 'data-selected-value' ) || getSelectedWard( type ) );
			return $field;
		}

		var selectedValue = $field.val() || $field.attr( 'data-selected-value' ) || getSelectedWard( type );
		var $select = $( '<select></select>' );

		$select.attr( {
			id: id,
			name: $field.attr( 'name' ) || id,
			'data-placeholder': params.i18n.selectWard,
			'data-selected-value': selectedValue
		} );

		$select.addClass( 'select short vck-admin-ward-select' );
		destroySelectEnhancement( $field );
		$field.replaceWith( $select );

		return getField( type, 'city' );
	}

	function restoreCityTextInput( type ) {
		var id = '_' + type + '_city';
		var $field = getField( type, 'city' );
		var $row = getFieldRow( type, 'city' );

		if ( ! $field.length || ! $row.length || ! $field.is( 'select' ) || ! $field.hasClass( 'vck-admin-ward-select' ) ) {
			return;
		}

		var $input = $( '<input type="text" />' );

		$input.attr( {
			id: id,
			name: $field.attr( 'name' ) || id,
			value: $field.val() || $field.attr( 'data-selected-value' ) || ''
		} );

		$input.addClass( 'short' );
		destroySelectEnhancement( $field );
		$field.replaceWith( $input );
		setRowLabel( $row, params.i18n.cityLabel );
	}

	function fillWardOptions( type ) {
		var country = getField( type, 'country' ).val();
		var $state = getField( type, 'state' );
		var state = normalizeProvinceCode( $state.val() || getSelectedState( type ) );
		var $row = getFieldRow( type, 'city' );

		if ( country !== params.country ) {
			restoreCityTextInput( type );
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
		var wards = state && params.wards[ state ] ? params.wards[ state ] : {};
		var placeholder = state ? params.i18n.selectWard : params.i18n.selectProvinceFirst;

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
		$row.show();
		enhanceSelect( $ward );
	}

	function syncFields() {
		$.each( addressTypes, function( index, type ) {
			fillWardOptions( type );
		} );
	}

	$( document.body ).on( 'country-change.woocommerce', function() {
		window.setTimeout( syncFields, 0 );
	} );

	$( document.body ).on( 'change', '#_billing_country, #_shipping_country, #_billing_state, #_shipping_state', function() {
		if ( this.id === '_billing_state' ) {
			setSelectedState( 'billing', $( this ).val() );
		} else if ( this.id === '_shipping_state' ) {
			setSelectedState( 'shipping', $( this ).val() );
		}

		syncFields();
	} );

	$( document.body ).on( 'change', '#_billing_city, #_shipping_city', function() {
		var type = this.id.indexOf( '_shipping_' ) === 0 ? 'shipping' : 'billing';
		var value = $( this ).val() || $( this ).attr( 'data-selected-value' ) || '';

		setSelectedWard( type, value );
		$( this ).attr( 'data-selected-value', normalizeWardCode( value ) );
	} );

	$( document.body ).on( 'click', 'a.edit_address, a.load_customer_billing, a.load_customer_shipping', function() {
		window.setTimeout( syncFields, 250 );
	} );

	$( document.body ).on( 'click', 'a.billing-same-as-shipping', function() {
		window.setTimeout( function() {
			setSelectedWard( 'shipping', getField( 'billing', 'city' ).val() || getSelectedWard( 'billing' ) );
			syncFields();
		}, 0 );
	} );

	$( document ).ajaxSuccess( function( event, jqXHR, ajaxOptions, response ) {
		var data = ajaxOptions && ajaxOptions.data ? ajaxOptions.data : '';

		if ( typeof data !== 'string' || data.indexOf( 'woocommerce_get_customer_details' ) === -1 ) {
			return;
		}

		response = response || jqXHR.responseJSON || {};

		if ( response.billing && response.billing.city ) {
			setSelectedWard( 'billing', response.billing.city );
		}

		if ( response.billing && response.billing.state ) {
			setSelectedState( 'billing', response.billing.state );
		}

		if ( response.shipping && response.shipping.city ) {
			setSelectedWard( 'shipping', response.shipping.city );
		}

		if ( response.shipping && response.shipping.state ) {
			setSelectedState( 'shipping', response.shipping.state );
		}

		window.setTimeout( syncFields, 0 );
		window.setTimeout( syncFields, 100 );
	} );

	syncFields();
} );

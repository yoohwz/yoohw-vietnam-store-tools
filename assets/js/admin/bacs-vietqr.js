/* global yoohwVietnamStoreToolsBacsVietqr */
( function() {
	'use strict';

	if ( typeof yoohwVietnamStoreToolsBacsVietqr === 'undefined' ) {
		return;
	}

	var params = yoohwVietnamStoreToolsBacsVietqr;
	var i18n = params.i18n || {};
	var countryCode = params.country || 'VN';
	var scheduled = false;
	var initialized = false;
	var transferTemplateSavedValue = normalizeTransferTemplate(
		params.settings && params.settings.transferContent ? params.settings.transferContent : ''
	);
	var transferTemplateDirty = false;
	var transferTemplateSavePromise = null;

	function normalizeText( value ) {
		return String( value || '' )
			.replace( /\*/g, '' )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
	}

	function normalizeValue( value ) {
		return String( value || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function normalizeAccountNumber( value ) {
		return String( value || '' ).replace( /[^A-Za-z0-9]+/g, '' ).toLowerCase();
	}

	function normalizeBankBin( value ) {
		return String( value || '' ).replace( /\D+/g, '' );
	}

	function normalizeTransferTemplate( value ) {
		value = normalizeValue( value );

		return value || 'ORDER-{order_number}';
	}

	function getCandidates( candidates ) {
		return candidates.map( normalizeText ).filter( Boolean );
	}

	function textMatches( text, candidates ) {
		text = normalizeText( text );
		candidates = getCandidates( candidates );

		return candidates.some( function( candidate ) {
			return text === candidate || text.indexOf( candidate ) === 0;
		} );
	}

	function getFieldLabel( field ) {
		return field ? field.querySelector( 'label, .components-base-control__label' ) : null;
	}

	function findFieldByLabel( modal, candidates ) {
		var labels = modal.querySelectorAll( 'label, .components-base-control__label' );
		var index;
		var label;

		for ( index = 0; index < labels.length; index++ ) {
			label = labels[ index ];

			if ( textMatches( label.textContent, candidates ) ) {
				return label.closest( '.bank-account-modal__field' ) || label.closest( '.components-base-control' ) || label.parentElement;
			}
		}

		return null;
	}

	function getFieldInput( field ) {
		return field ? field.querySelector( 'input, select, textarea' ) : null;
	}

	function getFieldValue( modal, candidates ) {
		var field = findFieldByLabel( modal, candidates );
		var input = getFieldInput( field );

		return input ? normalizeValue( input.value ) : '';
	}

	function setNativeValue( element, value ) {
		var descriptor = Object.getOwnPropertyDescriptor( Object.getPrototypeOf( element ), 'value' );

		if ( descriptor && descriptor.set ) {
			descriptor.set.call( element, value );
		} else {
			element.value = value;
		}

		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function ensureStyle() {
		if ( document.getElementById( 'vck-bacs-vietqr-admin-style' ) ) {
			return;
		}

		var style = document.createElement( 'style' );
		style.id = 'vck-bacs-vietqr-admin-style';
		style.textContent = [
			'.bank-account-modal .vck-vietqr-country-locked select{background:#f6f7f7;color:#646970;pointer-events:none;}',
			'.bank-account-modal .vck-vietqr-hidden-field{display:none!important;}',
			'.bank-account-modal .vck-vietqr-original-bank-name{display:none!important;}',
			'.bank-account-modal .vck-vietqr-bank-select{width:100%;max-width:100%;}',
			'.bank-account-modal .vck-vietqr-help{margin:6px 0 0;color:#646970;font-size:12px;line-height:1.4;}',
			'.bank-account-modal .vck-vietqr-error{margin:6px 0 0;color:#cc1818;font-size:12px;line-height:1.4;}',
			'.vck-vietqr-transfer-template-field{margin-top:24px;}',
			'.vck-vietqr-transfer-template-field .components-base-control__label{display:block;margin-bottom:8px;font-weight:500;text-transform:none;}',
			'.vck-vietqr-transfer-template-field input{width:100%;max-width:480px;}',
			'.vck-vietqr-transfer-template-field .components-base-control__help{margin:8px 0 0;color:#646970;font-size:12px;line-height:1.4;}'
		].join( '' );

		document.head.appendChild( style );
	}

	function ensureMessage( field, className, text ) {
		var message;

		if ( ! field || ! text ) {
			return null;
		}

		message = field.querySelector( '.' + className );

		if ( ! message ) {
			message = document.createElement( 'p' );
			message.className = className;
			field.appendChild( message );
		}

		message.textContent = text;

		return message;
	}

	function removeError( field ) {
		var message = field ? field.querySelector( '.vck-vietqr-error' ) : null;

		if ( message ) {
			message.remove();
		}
	}

	function setLabelText( field, text, required ) {
		var label = getFieldLabel( field );

		if ( label ) {
			label.textContent = text + ( required ? ' *' : '' );
		}
	}

	function getBanks() {
		return Array.isArray( params.banks ) ? params.banks : [];
	}

	function getBankLabel( bank ) {
		if ( ! bank ) {
			return '';
		}

		return ( bank.short_name || bank.name || bank.code || bank.bin ) + ( bank.code ? ' (' + bank.code + ')' : '' );
	}

	function hasBacsSettingsTitle() {
		var headings = document.querySelectorAll( 'h1, h2, h3, .woocommerce-layout__header-heading' );
		var index;

		for ( index = 0; index < headings.length; index++ ) {
			if ( textMatches( headings[ index ].textContent, [
				'Direct bank transfer',
				'Chuyển khoản ngân hàng trực tiếp',
				'Chuyen khoan ngan hang truc tiep'
			] ) ) {
				return true;
			}
		}

		return false;
	}

	function isBacsSettingsScreen() {
		var href = decodeURIComponent( window.location.href ).toLowerCase();

		if (
			Boolean( params.isBacsSettings ) ||
			-1 !== href.indexOf( 'settings/payments/bacs' ) ||
			-1 !== href.indexOf( 'section=bacs' ) ||
			-1 !== href.indexOf( 'gateway=bacs' ) ||
			-1 !== href.indexOf( 'method=bacs' )
		) {
			return true;
		}

		return hasBacsSettingsTitle();
	}

	function getTransferTemplateInput() {
		return document.querySelector( '.vck-vietqr-transfer-template-input' );
	}

	function getBacsSettingsForm() {
		var forms = document.querySelectorAll( 'form' );
		var index;

		for ( index = 0; index < forms.length; index++ ) {
			if ( forms[ index ].querySelector( 'textarea' ) && forms[ index ].querySelector( 'button' ) ) {
				return forms[ index ];
			}
		}

		return document.querySelector( '.settings-form' );
	}

	function getControlWrapper( input ) {
		return input ? input.closest( '.components-base-control, .components-textarea-control, .woocommerce-settings-field' ) || input.parentElement : null;
	}

	function getPaymentSettingsControls() {
		var form = getBacsSettingsForm();
		var section;
		var textareas;
		var anchor;

		if ( ! form ) {
			return null;
		}

		textareas = form.querySelectorAll( 'textarea' );

		if ( ! textareas.length ) {
			return null;
		}

		anchor = getControlWrapper( textareas[ textareas.length - 1 ] );
		section = anchor ? anchor.closest( '.settings-section, .woocommerce-settings-section' ) : null;

		return section ? section.querySelector( '.settings-section__controls, .woocommerce-settings-section__content' ) || section : anchor ? anchor.parentElement : null;
	}

	function createTransferTemplateField() {
		var id = 'vck-vietqr-transfer-template';
		var wrapper = document.createElement( 'div' );
		var field = document.createElement( 'div' );
		var label = document.createElement( 'label' );
		var input = document.createElement( 'input' );
		var help = document.createElement( 'p' );

		wrapper.className = 'components-base-control vck-vietqr-transfer-template-field';
		field.className = 'components-base-control__field';
		label.className = 'components-base-control__label';
		label.setAttribute( 'for', id );
		label.textContent = i18n.transferTemplateLabel || 'Transfer content template';

		input.id = id;
		input.type = 'text';
		input.className = 'components-text-control__input vck-vietqr-transfer-template-input';
		input.value = transferTemplateSavedValue;
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'placeholder', 'ORDER-{order_number}' );

		help.className = 'components-base-control__help';
		help.textContent = i18n.transferTemplateHelp || 'Available placeholders: {order_id}, {order_number}, {site_name}.';

		input.addEventListener( 'input', function() {
			transferTemplateDirty = normalizeTransferTemplate( input.value ) !== transferTemplateSavedValue;
			syncTransferTemplateSaveButton();
		} );

		field.appendChild( label );
		field.appendChild( input );
		wrapper.appendChild( field );
		wrapper.appendChild( help );

		return wrapper;
	}

	function ensureTransferTemplateField() {
		var controls;
		var existingInput;
		var field;
		var textareas;
		var anchor;

		if (
			! isBacsSettingsScreen() ||
			getTransferTemplateInput() ||
			document.getElementById( 'woocommerce_bacs_yoohw_vietnam_store_tools_vietqr_transfer_content' )
		) {
			return;
		}

		ensureStyle();
		controls = getPaymentSettingsControls();

		if ( ! controls ) {
			return;
		}

		field = createTransferTemplateField();
		textareas = controls.querySelectorAll( 'textarea' );
		anchor = textareas.length ? getControlWrapper( textareas[ textareas.length - 1 ] ) : null;

		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( field, anchor.nextSibling );
		} else {
			controls.appendChild( field );
		}

		existingInput = getTransferTemplateInput();

		if ( existingInput ) {
			transferTemplateDirty = normalizeTransferTemplate( existingInput.value ) !== transferTemplateSavedValue;
			syncTransferTemplateSaveButton();
		}
	}

	function isSettingsSaveButton( button ) {
		if ( ! button || button.closest( '.bank-account-modal' ) ) {
			return false;
		}

		return 'submit' === button.type ||
			textMatches( button.textContent, [
				'Save changes',
				'Lưu thay đổi',
				'Luu thay doi'
			] );
	}

	function getSettingsSaveButtons() {
		return Array.from( document.querySelectorAll( 'button' ) ).filter( isSettingsSaveButton );
	}

	function syncTransferTemplateSaveButton() {
		if ( ! transferTemplateDirty ) {
			return;
		}

		getSettingsSaveButtons().forEach( function( button ) {
			if ( button.disabled || button.hasAttribute( 'disabled' ) || 'true' === button.getAttribute( 'aria-disabled' ) ) {
				button.disabled = false;
				button.removeAttribute( 'disabled' );
				button.setAttribute( 'aria-disabled', 'false' );
			}
		} );
	}

	function showTransferTemplateSaveError() {
		if (
			window.wp &&
			window.wp.data &&
			window.wp.data.dispatch &&
			window.wp.data.dispatch( 'core/notices' ) &&
			window.wp.data.dispatch( 'core/notices' ).createErrorNotice
		) {
			window.wp.data.dispatch( 'core/notices' ).createErrorNotice(
				i18n.transferTemplateSaveError || 'Could not save the VietQR transfer content template.',
				{ type: 'snackbar' }
			);
			return;
		}

		if ( window.console && window.console.error ) {
			window.console.error( i18n.transferTemplateSaveError || 'Could not save the VietQR transfer content template.' );
		}
	}

	function saveTransferTemplate() {
		var input = getTransferTemplateInput();
		var value;
		var body;

		if ( ! input || ! transferTemplateDirty || ! params.ajaxUrl || ! params.nonce ) {
			return Promise.resolve();
		}

		if ( transferTemplateSavePromise ) {
			return transferTemplateSavePromise;
		}

		value = normalizeTransferTemplate( input.value );
		body = new URLSearchParams();
		body.append( 'action', 'yoohw_vietnam_store_tools_save_bacs_vietqr_settings' );
		body.append( 'nonce', params.nonce );
		body.append( 'transfer_content', value );

		transferTemplateSavePromise = window.fetch( params.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		} ).then( function( response ) {
			return response.json();
		} ).then( function( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( 'yoohw_vietnam_store_tools_transfer_template_save_failed' );
			}

			transferTemplateSavedValue = normalizeTransferTemplate(
				response.data && response.data.transferContent ? response.data.transferContent : value
			);
			input.value = transferTemplateSavedValue;
			transferTemplateDirty = false;
		} ).catch( function( error ) {
			showTransferTemplateSaveError();
			throw error;
		} ).finally( function() {
			transferTemplateSavePromise = null;
		} );

		return transferTemplateSavePromise;
	}

	function getBankDisplayName( bank ) {
		return bank ? ( bank.short_name || bank.name || bank.code || bank.bin || '' ) : '';
	}

	function findBankByBin( bin ) {
		bin = normalizeBankBin( bin );

		if ( ! bin ) {
			return null;
		}

		return getBanks().find( function( bank ) {
			return normalizeBankBin( bank.bin ) === bin;
		} ) || null;
	}

	function findBankByName( name ) {
		name = normalizeText( name );

		if ( ! name ) {
			return null;
		}

		return getBanks().find( function( bank ) {
			return normalizeText( bank.short_name ) === name ||
				normalizeText( bank.name ) === name ||
				normalizeText( bank.code ) === name ||
				normalizeText( getBankLabel( bank ) ) === name;
		} ) || null;
	}

	function forceVietnamCountry( modal ) {
		var field = findFieldByLabel( modal, [
			i18n.countryLabel,
			'Country',
			'Quốc gia',
			'Quoc gia'
		] );
		var select = getFieldInput( field );

		if ( ! field || ! select || 'SELECT' !== select.tagName ) {
			return;
		}

		if ( select.value !== countryCode && Array.from( select.options ).some( function( option ) {
			return option.value === countryCode;
		} ) ) {
			setNativeValue( select, countryCode );
		}

		select.disabled = true;
		select.setAttribute( 'aria-disabled', 'true' );
		field.classList.add( 'vck-vietqr-country-locked' );
		ensureMessage( field, 'vck-vietqr-help', i18n.countryLockedNote );
	}

	function getExistingAccountBankBin( modal ) {
		var accountNumber = normalizeAccountNumber(
			getFieldValue( modal, [
				i18n.accountNumberLabel,
				'Account Number',
				'Số tài khoản',
				'So tai khoan'
			] )
		);
		var accountName = normalizeText(
			getFieldValue( modal, [
				i18n.accountNameLabel,
				'Account Name',
				'Tên tài khoản',
				'Ten tai khoan'
			] )
		);
		var bankName = normalizeText(
			getFieldValue( modal, [
				i18n.bankNameLabel,
				'Bank Name',
				'Tên ngân hàng',
				'Ten ngan hang'
			] )
		);
		var accounts = Array.isArray( params.accounts ) ? params.accounts : [];
		var match = accounts.find( function( account ) {
			if ( ! accountNumber ) {
				return false;
			}

			if ( accountNumber && normalizeAccountNumber( account.account_number ) !== accountNumber ) {
				return false;
			}

			if ( accountName && normalizeText( account.account_name ) !== accountName ) {
				return false;
			}

			if ( bankName && normalizeText( account.bank_name ) !== bankName ) {
				return false;
			}

			return Boolean( account.sort_code || account.bic );
		} );

		if ( ! match ) {
			return '';
		}

		return normalizeBankBin( match.sort_code ) || normalizeBankBin( match.bic );
	}

	function getBankNameField( modal ) {
		return findFieldByLabel( modal, [
			i18n.bankNameLabel,
			i18n.bankSelectLabel,
			'Bank Name',
			'Bank',
			'Tên ngân hàng',
			'Ten ngan hang'
		] );
	}

	function getBankBinField( modal ) {
		return findFieldByLabel( modal, [
			i18n.bankBinLabel,
			i18n.bicSwiftLabel,
			'Bank BIN',
			'BIC / SWIFT'
		] );
	}

	function getBankBinInput( modal ) {
		return getFieldInput( getBankBinField( modal ) );
	}

	function getSelectedBankFromModal( modal ) {
		var select = modal.querySelector( '.vck-vietqr-bank-select' );
		var selectedBin = select ? normalizeBankBin( select.value ) : '';

		return findBankByBin( selectedBin );
	}

	function getBestKnownBank( modal ) {
		var bankBinInput = getBankBinInput( modal );
		var bankFromBin = findBankByBin( bankBinInput ? bankBinInput.value : '' ) || findBankByBin( getExistingAccountBankBin( modal ) );
		var bankNameField = getBankNameField( modal );
		var bankNameInput = getFieldInput( bankNameField );

		return bankFromBin || findBankByName( bankNameInput ? bankNameInput.value : '' );
	}

	function applyBankSelection( modal, bank ) {
		var bankNameField = getBankNameField( modal );
		var bankNameInput = getFieldInput( bankNameField );
		var bankBinInput = getBankBinInput( modal );

		if ( bankNameInput ) {
			setNativeValue( bankNameInput, getBankDisplayName( bank ) );
		}

		if ( bankBinInput ) {
			setNativeValue( bankBinInput, bank ? bank.bin : '' );
		}

		removeError( bankNameField );
	}

	function prepareBankSelectField( modal ) {
		var field = getBankNameField( modal );
		var input = getFieldInput( field );
		var select = field ? field.querySelector( '.vck-vietqr-bank-select' ) : null;
		var selectedBank;

		if ( ! field || ! input || ! getBanks().length ) {
			return;
		}

		setLabelText( field, i18n.bankSelectLabel || 'Bank', true );
		input.classList.add( 'vck-vietqr-original-bank-name' );
		input.setAttribute( 'aria-hidden', 'true' );
		input.setAttribute( 'tabindex', '-1' );

		if ( ! select ) {
			select = document.createElement( 'select' );
			select.className = 'vck-vietqr-bank-select';
			select.setAttribute( 'aria-label', i18n.bankSelectLabel || 'Bank' );

			select.appendChild( new Option( i18n.selectBank || 'Select a bank', '' ) );

			getBanks().forEach( function( bank ) {
				select.appendChild( new Option( getBankLabel( bank ), bank.bin ) );
			} );

			input.parentNode.insertBefore( select, input.nextSibling );
			select.addEventListener( 'change', function() {
				applyBankSelection( modal, findBankByBin( select.value ) );
			} );
		}

		selectedBank = getBestKnownBank( modal );

		if ( selectedBank && select.value !== selectedBank.bin ) {
			select.value = selectedBank.bin;
			applyBankSelection( modal, selectedBank );
		}

		ensureMessage( field, 'vck-vietqr-help', i18n.bankSelectHelp );
	}

	function prepareBankBinField( modal ) {
		var field = findFieldByLabel( modal, [
			i18n.bankBinLabel,
			i18n.bicSwiftLabel,
			'Bank BIN',
			'BIC / SWIFT'
		] );
		var input = getFieldInput( field );
		var existingBankBin;

		if ( ! field || ! input ) {
			return;
		}

		input.setAttribute( 'inputmode', 'numeric' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'placeholder', '970436' );
		input.setAttribute( 'data-vck-vietqr-bank-bin', '1' );
		field.classList.add( 'vck-vietqr-hidden-field' );

		existingBankBin = getExistingAccountBankBin( modal );

		if ( ! normalizeBankBin( input.value ) && existingBankBin ) {
			setNativeValue( input, existingBankBin );
		}
	}

	function hideIbanField( modal ) {
		var field = findFieldByLabel( modal, [
			i18n.ibanLabel,
			'IBAN'
		] );

		if ( field ) {
			field.classList.add( 'vck-vietqr-hidden-field' );
		}
	}

	function validateBankSelection( modal ) {
		var field = getBankNameField( modal );
		var input = getFieldInput( field );
		var bankBinInput = getBankBinInput( modal );
		var selectedBank = getSelectedBankFromModal( modal );
		var bankBin = bankBinInput ? normalizeBankBin( bankBinInput.value ) : '';

		if ( ! field || ! input ) {
			return true;
		}

		if ( selectedBank ) {
			applyBankSelection( modal, selectedBank );
			return true;
		}

		if ( bankBin ) {
			removeError( field );
			return true;
		}

		ensureMessage( field, 'vck-vietqr-error', i18n.bankSelectRequired || 'Please select a bank to generate VietQR codes.' );

		if ( modal.querySelector( '.vck-vietqr-bank-select' ) ) {
			modal.querySelector( '.vck-vietqr-bank-select' ).focus();
		}

		return false;
	}

	function enhanceModal( modal ) {
		if ( ! modal || ! modal.classList.contains( 'bank-account-modal' ) ) {
			return;
		}

		ensureStyle();
		forceVietnamCountry( modal );
		prepareBankBinField( modal );
		prepareBankSelectField( modal );
		hideIbanField( modal );
	}

	function syncModals() {
		document.querySelectorAll( '.bank-account-modal' ).forEach( enhanceModal );
	}

	function syncAdminUi() {
		syncModals();
		ensureTransferTemplateField();
		syncTransferTemplateSaveButton();
	}

	function scheduleSync() {
		if ( scheduled ) {
			return;
		}

		scheduled = true;
		window.requestAnimationFrame( function() {
			scheduled = false;
			syncAdminUi();
		} );
	}

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest( '.bank-account-modal__save' );
		var modal;

		if ( ! button ) {
			return;
		}

		modal = button.closest( '.bank-account-modal' );

		if ( ! modal ) {
			return;
		}

		enhanceModal( modal );

		if ( validateBankSelection( modal ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();
	}, true );

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest( 'button' );

		if ( ! transferTemplateDirty || ! isSettingsSaveButton( button ) ) {
			return;
		}

		saveTransferTemplate().catch( function() {} );
	}, true );

	document.addEventListener( 'submit', function( event ) {
		if ( ! transferTemplateDirty || ! event.target.querySelector( '.vck-vietqr-transfer-template-input' ) ) {
			return;
		}

		saveTransferTemplate().catch( function() {} );
	}, true );

	function init() {
		if ( initialized || ! document.body ) {
			return;
		}

		initialized = true;
		syncAdminUi();

		new MutationObserver( scheduleSync ).observe( document.body, {
			attributeFilter: [ 'aria-disabled', 'disabled' ],
			attributes: true,
			childList: true,
			subtree: true
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

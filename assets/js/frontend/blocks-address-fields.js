(function() {
	'use strict';

	var params = window.yoohwVietnamStoreToolsBlocksAddressFields || {};
	var dataApi = window.wp && window.wp.data ? window.wp.data : null;
	var wardsCache = {};
	var wardsRequests = {};
	var renderFrame = 0;
	var lastAddressSignature = '';

	if (!dataApi || !params.ajaxUrl) {
		return;
	}

	function normalizeCode(value, length) {
		value = String(value || '').trim();

		if (/^[0-9]+$/.test(value)) {
			while (value.length < length) {
				value = '0' + value;
			}
		}

		return value;
	}

	function getCartData() {
		var store = dataApi.select('wc/store/cart');

		if (!store || typeof store.getCartData !== 'function') {
			return {};
		}

		return store.getCartData() || {};
	}

	function getAddress(type) {
		var cartData = getCartData();
		var key = type === 'billing' ? 'billingAddress' : 'shippingAddress';

		return cartData[key] || {};
	}

	function getAddressType(input) {
		var id = input && input.id ? input.id : '';

		if (id.indexOf('billing') === 0 || input.closest('.wc-block-checkout__billing-fields')) {
			return 'billing';
		}

		return 'shipping';
	}

	function getDomFieldValue(cityInput, field) {
		var fieldId = cityInput.id.replace(/-city$/, '-' + field);
		var element = document.getElementById(fieldId);

		return element ? element.value || '' : '';
	}

	function getEffectiveCountry(address, cityInput) {
		if (params.singleCountry) {
			return params.singleCountry;
		}

		return address.country || getDomFieldValue(cityInput, 'country');
	}

	function getNativeFieldWrapper(input) {
		return input.closest('.wc-block-components-text-input') || input.parentElement;
	}

	function getStateFieldWrapper(cityInput) {
		var stateId = cityInput.id.replace(/-city$/, '-state');
		var stateField = document.getElementById(stateId);

		if (!stateField) {
			return null;
		}

		return stateField.closest('.wc-block-components-address-form__state') || stateField.parentElement;
	}

	function hideNativeCityField(input) {
		var nativeWrapper = getNativeFieldWrapper(input);

		if (nativeWrapper) {
			nativeWrapper.classList.add('vck-block-native-city-field');
			nativeWrapper.setAttribute('aria-hidden', 'true');
		}
	}

	function restoreNativeCityField(input) {
		var nativeWrapper = getNativeFieldWrapper(input);
		var customField = document.querySelector('[data-vck-block-ward-for="' + input.id + '"]');

		if (nativeWrapper) {
			nativeWrapper.classList.remove('vck-block-native-city-field');
			nativeWrapper.removeAttribute('aria-hidden');
		}

		if (customField) {
			customField.remove();
		}
	}

	function createChevron() {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');

		svg.setAttribute('class', 'wc-blocks-components-select__expand');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '24');
		svg.setAttribute('height', '24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		path.setAttribute('d', 'M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z');
		svg.appendChild(path);

		return svg;
	}

	function createWardField(cityInput, type) {
		var nativeWrapper = getNativeFieldWrapper(cityInput);
		var wrapper = document.createElement('div');
		var component = document.createElement('div');
		var container = document.createElement('div');
		var label = document.createElement('label');
		var select = document.createElement('select');
		var selectId = 'vck-' + type + '-ward';

		wrapper.className = 'wc-block-components-select-input wc-block-components-address-form__city vck-block-ward-field';
		wrapper.setAttribute('data-vck-block-ward-for', cityInput.id);
		component.className = 'wc-blocks-components-select';
		container.className = 'wc-blocks-components-select__container';
		label.className = 'wc-blocks-components-select__label';
		label.htmlFor = selectId;
		label.textContent = (params.i18n && params.i18n.wardLabel) || 'Ward / Commune';
		select.className = 'wc-blocks-components-select__select';
		select.id = selectId;
		select.required = true;
		select.autocomplete = type + ' address-level2';
		select.setAttribute('data-vck-block-ward-select', type);
		select.addEventListener('change', function() {
			updateAddressCity(type, select.value);
		});

		container.appendChild(label);
		container.appendChild(select);
		container.appendChild(createChevron());
		component.appendChild(container);
		wrapper.appendChild(component);

		if (nativeWrapper) {
			hideNativeCityField(cityInput);
			(getStateFieldWrapper(cityInput) || nativeWrapper).insertAdjacentElement('afterend', wrapper);
		}

		return wrapper;
	}

	function setSelectMessage(select, message, disabled, renderKey) {
		var option = document.createElement('option');

		if (select.getAttribute('data-vck-render-key') === renderKey) {
			return;
		}

		select.replaceChildren();
		option.value = '';
		option.textContent = message;
		select.appendChild(option);
		select.disabled = Boolean(disabled);
		select.setAttribute('data-vck-render-key', renderKey);
	}

	function populateWardOptions(select, wards, selected, state) {
		var placeholder = document.createElement('option');
		var renderKey = 'ready:' + state + ':' + normalizeCode(selected, 5);

		if (select.getAttribute('data-vck-render-key') === renderKey) {
			return;
		}

		select.replaceChildren();
		placeholder.value = '';
		placeholder.textContent = (params.i18n && params.i18n.selectWard) || 'Select a ward / commune';
		select.appendChild(placeholder);

		Object.keys(wards || {}).forEach(function(code) {
			var option = document.createElement('option');

			option.value = normalizeCode(code, 5);
			option.textContent = wards[code];
			select.appendChild(option);
		});

		select.disabled = false;
		select.value = normalizeCode(selected, 5);

		if (select.value !== normalizeCode(selected, 5)) {
			select.value = '';
		}

		select.setAttribute('data-vck-render-key', renderKey);
	}

	function requestWards(state) {
		state = normalizeCode(state, 2);

		if (wardsCache[state]) {
			return Promise.resolve(wardsCache[state]);
		}

		if (wardsRequests[state]) {
			return wardsRequests[state];
		}

		var body = new URLSearchParams();
		body.append('action', 'yoohw_vietnam_store_tools_wards');
		body.append('nonce', params.wardsNonce || '');
		body.append('state', state);

		wardsRequests[state] = window.fetch(params.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		}).then(function(response) {
			if (!response.ok) {
				throw new Error('Ward request failed');
			}

			return response.json();
		}).then(function(response) {
			var wards = response && response.success && response.data ? response.data.wards : null;

			if (!wards || typeof wards !== 'object') {
				throw new Error('Invalid ward response');
			}

			wardsCache[state] = wards;
			delete wardsRequests[state];

			return wards;
		}).catch(function(error) {
			delete wardsRequests[state];
			throw error;
		});

		return wardsRequests[state];
	}

	function updateAddressCity(type, city) {
		var address = getAddress(type);
		var action = type === 'billing' ? 'setBillingAddress' : 'setShippingAddress';
		var store = dataApi.dispatch('wc/store/cart');

		city = normalizeCode(city, 5);

		if (!store || typeof store[action] !== 'function' || normalizeCode(address.city, 5) === city) {
			return;
		}

		store[action](Object.assign({}, address, {
			city: city
		}));
	}

	function renderWardField(cityInput) {
		var type = getAddressType(cityInput);
		var address = getAddress(type);
		var country = getEffectiveCountry(address, cityInput);
		var state = normalizeCode(address.state || getDomFieldValue(cityInput, 'state'), 2);
		var city = normalizeCode(address.city || cityInput.value, 5);
		var selector = '[data-vck-block-ward-for="' + cityInput.id + '"]';
		var wrapper = document.querySelector(selector);
		var select;

		if (country !== params.country) {
			restoreNativeCityField(cityInput);
			return;
		}

		hideNativeCityField(cityInput);

		if (!wrapper) {
			wrapper = createWardField(cityInput, type);
		} else {
			var stateWrapper = getStateFieldWrapper(cityInput);

			if (stateWrapper && stateWrapper.nextElementSibling !== wrapper) {
				stateWrapper.insertAdjacentElement('afterend', wrapper);
			}
		}

		select = wrapper ? wrapper.querySelector('select') : null;

		if (!select) {
			return;
		}

		if (!state) {
			setSelectMessage(select, (params.i18n && params.i18n.selectProvinceFirst) || 'Select a city / province first', true, 'empty-state');
			return;
		}

		if (wardsCache[state]) {
			populateWardOptions(select, wardsCache[state], city, state);

			if (city && !Object.prototype.hasOwnProperty.call(wardsCache[state], city)) {
				updateAddressCity(type, '');
			}

			return;
		}

		setSelectMessage(select, (params.i18n && params.i18n.loadingWards) || 'Loading ward / commune list...', true, 'loading:' + state);

		requestWards(state).then(function(wards) {
			var currentAddress = getAddress(type);

			if (normalizeCode(currentAddress.state, 2) !== state || !document.body.contains(select)) {
				return;
			}

			populateWardOptions(select, wards, currentAddress.city || city, state);

			if (currentAddress.city && !Object.prototype.hasOwnProperty.call(wards, normalizeCode(currentAddress.city, 5))) {
				updateAddressCity(type, '');
			}
		}).catch(function() {
			if (document.body.contains(select)) {
				setSelectMessage(select, (params.i18n && params.i18n.loadError) || 'Could not load the ward / commune list. Please try again.', true, 'error:' + state);
			}
		});
	}

	function renderFields() {
		renderFrame = 0;

		document.querySelectorAll('.wc-block-components-address-form input[id$="-city"]').forEach(function(input) {
			renderWardField(input);
		});
	}

	function scheduleRender() {
		if (!renderFrame) {
			renderFrame = window.requestAnimationFrame(renderFields);
		}
	}

	function getAddressSignature() {
		var cartData = getCartData();
		var billing = cartData.billingAddress || {};
		var shipping = cartData.shippingAddress || {};

		return [
			billing.country,
			billing.state,
			billing.city,
			shipping.country,
			shipping.state,
			shipping.city
		].join('|');
	}

	var observer = new MutationObserver(scheduleRender);
	observer.observe(document.documentElement, {
		childList: true,
		subtree: true
	});

	dataApi.subscribe(function() {
		var signature = getAddressSignature();

		if (signature !== lastAddressSignature) {
			lastAddressSignature = signature;
			scheduleRender();
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scheduleRender);
	} else {
		scheduleRender();
	}
})();

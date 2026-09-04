/* global yoohwVietnamStoreToolsShippingZones, yoohwVietnamStoreToolsShippingRules, shippingZoneMethodsLocalizeScript, yoohwVietnamStoreToolsShippingZoneInitialWards */
(function ($, data) {
	'use strict';

	var rulesData = typeof yoohwVietnamStoreToolsShippingRules !== 'undefined' ? yoohwVietnamStoreToolsShippingRules : null;
	var prefix = data.locationType + ':';

	function getString(key, fallback) {
		return rulesData && rulesData.i18n && rulesData.i18n[key] ? rulesData.i18n[key] : fallback;
	}

	function getEventLocations(event) {
		if (event && event.originalEvent && Array.isArray(event.originalEvent.detail)) {
			return event.originalEvent.detail;
		}

		return event && Array.isArray(event.detail) ? event.detail : [];
	}

	function initWardEditor() {
		var zoneData = typeof shippingZoneMethodsLocalizeScript !== 'undefined' ? shippingZoneMethodsLocalizeScript : null;
		var initialWards = typeof yoohwVietnamStoreToolsShippingZoneInitialWards !== 'undefined' && Array.isArray(yoohwVietnamStoreToolsShippingZoneInitialWards) ? yoohwVietnamStoreToolsShippingZoneInitialWards : [];
		var $reactRoot = $('#wc-shipping-zone-region-picker-root');
		var syncing = false;
		var coreLocations;
		var $container;
		var $select;
		var wardLabel = getString('ward', 'Ward / Commune');
		var anyWardLabel = getString('anyWard', 'Any ward / commune');
		var provinces = rulesData && rulesData.provinces ? rulesData.provinces : {};
		var wards = rulesData && rulesData.wards ? rulesData.wards : {};

		if (!zoneData || !$reactRoot.length || $('.vck-shipping-zone-wards').length) {
			return;
		}

		coreLocations = Array.isArray(zoneData.locations) ? zoneData.locations.slice() : [];
		$container = $('<div class="vck-shipping-zone-wards">');
		$select = $('<select multiple="multiple" class="wc-enhanced-select vck-shipping-zone-wards__select">');
		$select.attr({
			id: 'vck-shipping-zone-wards',
			'data-placeholder': anyWardLabel
		});
		$container.append(
			$('<label class="vck-shipping-zone-wards__label">')
				.attr('for', 'vck-shipping-zone-wards')
				.text(wardLabel)
		);

		Object.keys(provinces).forEach(function (provinceCode) {
			var provinceWards = wards[provinceCode] || {};
			var $group = $('<optgroup>').attr('label', provinces[provinceCode]);

			Object.keys(provinceWards).forEach(function (wardCode) {
				var value = prefix + provinceCode + ':' + wardCode;
				var $option = $('<option>').attr('value', value).text(provinceWards[wardCode]);

				if (initialWards.indexOf(value) !== -1) {
					$option.prop('selected', true);
				}

				$group.append($option);
			});

			if ($group.children().length) {
				$select.append($group);
			}
		});

		$container.append($select);
		$reactRoot.after($container);

		if (typeof $select.selectWoo === 'function') {
			$select.selectWoo({
				width: '100%',
				placeholder: anyWardLabel,
				allowClear: true
			});
		} else if (typeof $select.select2 === 'function') {
			$select.select2({
				width: '100%',
				placeholder: anyWardLabel,
				allowClear: true
			});
		}

		function selectedWards() {
			return ($select.val() || []).map(String);
		}

		function dispatchCombinedLocations() {
			var locations = coreLocations.concat(selectedWards());

			syncing = true;
			document.body.dispatchEvent(new CustomEvent('wc_region_picker_update', { detail: locations }));
			syncing = false;
		}

		$select.on('change.vckShippingZones', dispatchCombinedLocations);

		$(document.body).on('wc_region_picker_update.vckShippingZones', function (event) {
			var locations;

			if (syncing) {
				return;
			}

			locations = getEventLocations(event);
			coreLocations = locations.filter(function (location) {
				return String(location).indexOf(prefix) !== 0;
			});

			if (selectedWards().length) {
				/*
				 * Let WooCommerce record the native event first. The deferred
				 * combined event must be last regardless of listener order.
				 */
				window.setTimeout(dispatchCombinedLocations, 0);
			}
		});
	}

	function enhanceZonesList() {
		var summariesByZone = data.zoneSummaries || {};
		var wardLabel = getString('ward', 'Ward / Commune');

		Object.keys(summariesByZone).forEach(function (zoneId) {
			var summary = summariesByZone[zoneId] || {};
			var labels = summary.labels || [];
			var $cell = $('.wc-shipping-zone-rows tr[data-id="' + zoneId + '"] .wc-shipping-zone-region');
			var $details;

			if (!$cell.length || !labels.length || $cell.find('.vck-shipping-zone-ward-labels').length) {
				return;
			}

			if (!summary.hasNativeLocations) {
				$cell.empty();
			}

			$details = $('<div class="vck-shipping-zone-ward-labels">');
			$details.append($('<strong>').text(wardLabel + ': '));
			$details.append(document.createTextNode(labels.join(', ')));
			$cell.append($details);
		});
	}

	function observeZonesList() {
		var rows = document.querySelector('.wc-shipping-zone-rows');

		if (!rows || typeof MutationObserver === 'undefined') {
			return;
		}

		new MutationObserver(function () {
			enhanceZonesList();
		}).observe(rows, { childList: true });
	}

	$(function () {
		initWardEditor();
		enhanceZonesList();
		observeZonesList();
	});
})(jQuery, yoohwVietnamStoreToolsShippingZones);

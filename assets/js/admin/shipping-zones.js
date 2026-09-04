/* global yoohwVietnamStoreToolsShippingZones, shippingZoneMethodsLocalizeScript */
(function ($, data) {
	'use strict';

	function getZoneEditorData() {
		return typeof shippingZoneMethodsLocalizeScript !== 'undefined' ? shippingZoneMethodsLocalizeScript : null;
	}

	function getEventLocations(event) {
		if (event && event.originalEvent && Array.isArray(event.originalEvent.detail)) {
			return event.originalEvent.detail;
		}

		return event && Array.isArray(event.detail) ? event.detail : [];
	}

	function initWardEditor() {
		var zoneData = getZoneEditorData();
		var $reactRoot = $('#wc-shipping-zone-region-picker-root');
		var $legacyPicker = $('#zone_locations');
		var prefix = data.locationType + ':';
		var syncing = false;
		var coreLocations;
		var initialWards;
		var $container;
		var $select;

		if (!zoneData || (!$reactRoot.length && !$legacyPicker.length) || $('.vck-shipping-zone-wards').length) {
			return;
		}

		coreLocations = (zoneData.locations || []).filter(function (location) {
			return String(location).indexOf(prefix) !== 0;
		});
		initialWards = (zoneData.locations || []).filter(function (location) {
			return String(location).indexOf(prefix) === 0;
		});

		$container = $('<div class="vck-shipping-zone-wards">');
		$container.css({ marginTop: '12px', maxWidth: '600px' });
		$container.append($('<label>').css({ display: 'block', fontWeight: '600', marginBottom: '4px' }).text(data.i18n.ward));
		$select = $('<select multiple="multiple" class="wc-enhanced-select" style="width:100%">');
		$select.attr('data-placeholder', data.i18n.anyWard);

		Object.keys(data.provinces || {}).forEach(function (provinceCode) {
			var provinceWards = data.wards && data.wards[provinceCode] ? data.wards[provinceCode] : {};
			var $group = $('<optgroup>').attr('label', data.provinces[provinceCode]);

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

		if ($reactRoot.length) {
			$reactRoot.after($container);
		} else {
			$legacyPicker.closest('td, .forminp, .wc-shipping-zone-region-select').first().append($container);
		}

		if (typeof $select.selectWoo === 'function') {
			$select.selectWoo({
				width: '100%',
				placeholder: data.i18n.anyWard,
				allowClear: true
			});
		} else if (typeof $select.select2 === 'function') {
			$select.select2({
				width: '100%',
				placeholder: data.i18n.anyWard,
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
				dispatchCombinedLocations();
			}
		});
	}

	function enhanceZonesList() {
		var labelsByZone = data.zoneLabels || {};

		Object.keys(labelsByZone).forEach(function (zoneId) {
			var labels = labelsByZone[zoneId] || [];
			var $cell = $('.wc-shipping-zone-rows tr[data-id="' + zoneId + '"] .wc-shipping-zone-region');
			var $details;

			if (!$cell.length || !labels.length || $cell.find('.vck-shipping-zone-ward-labels').length) {
				return;
			}

			$details = $('<div class="vck-shipping-zone-ward-labels">').css({ marginTop: '4px' });
			$details.append($('<strong>').text(data.i18n.ward + ': '));
			$details.append(document.createTextNode(labels.join(', ')));
			$cell.append($details);
		});
	}

	$(function () {
		initWardEditor();
		enhanceZonesList();

		$(document.body).on('wc_backbone_modal_loaded saved:zones', function () {
			window.setTimeout(function () {
				initWardEditor();
				enhanceZonesList();
			}, 0);
		});
	});
})(jQuery, yoohwVietnamStoreToolsShippingZones);

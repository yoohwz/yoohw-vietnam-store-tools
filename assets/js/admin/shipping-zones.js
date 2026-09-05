/* global yoohwVietnamStoreToolsShippingZones */
(function ($, data) {
	'use strict';

	function enhanceZonesList() {
		var summariesByZone = data.zoneSummaries || {};
		var wardLabel = data.wardLabel || 'Ward / Commune';

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
		enhanceZonesList();
		observeZonesList();
	});
})(jQuery, yoohwVietnamStoreToolsShippingZones);

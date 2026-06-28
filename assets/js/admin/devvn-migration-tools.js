(function ($) {
	'use strict';

	var config = window.yoohwVietnamStoreToolsDevvnMigrationTools || {};

	function getString(key, fallback) {
		return config.strings && config.strings[key] ? config.strings[key] : fallback;
	}

	function parseCount(value) {
		var parsed = parseInt(value, 10);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function requestMigration(mode) {
		return $.post(config.ajaxUrl, {
			action: 'yoohw_vietnam_store_tools_devvn_migration_step',
			nonce: config.nonce,
			mode: mode
		});
	}

	$(function () {
		if (!config.ajaxUrl || !config.nonce || !config.migrationTool) {
			return;
		}

		var form = $('#form_' + config.migrationTool);
		var button = $('input[type="submit"][form="form_' + config.migrationTool + '"]');
		var row = $('.' + config.migrationTool);
		var progress = row.find('.vck-devvn-migration-progress');
		var bar = progress.find('.vck-devvn-migration-progress__bar');
		var barFill = bar.find('span');
		var status = progress.find('.vck-devvn-migration-progress__status');
		var detail = progress.find('.vck-devvn-migration-progress__detail');
		var initialRemaining = 0;
		var running = false;

		if (!form.length || !button.length || !progress.length) {
			return;
		}

		function setProgress(percent, statusText, detailText) {
			var safePercent = Math.max(0, Math.min(100, percent));
			progress.prop('hidden', false);
			bar.attr('aria-valuenow', safePercent);
			barFill.css('width', safePercent + '%');
			status.text(statusText || '');
			detail.text(detailText || '');
		}

		function stopRunning() {
			running = false;
			button.prop('disabled', false);
		}

		function fail(message) {
			setProgress(100, getString('requestFailed', 'Sync request failed. Please try again.'), message || '');
			progress.addClass('is-error').removeClass('is-running is-complete');
			stopRunning();
		}

		function complete(message) {
			setProgress(100, getString('completed', 'Sync completed.'), message || '');
			progress.addClass('is-complete').removeClass('is-running is-error');
			stopRunning();
		}

		function runStep() {
			requestMigration('step')
				.done(function (response) {
					if (!response || !response.success || !response.data) {
						fail(response && response.data && response.data.message ? response.data.message : '');
						return;
					}

					var data = response.data;
					var remaining = parseCount(data.remaining);
					var completed = Math.max(0, initialRemaining - remaining);
					var percent = initialRemaining > 0 ? Math.round((completed / initialRemaining) * 100) : 100;
					var step = data.step || {};
					var stepDetail = data.message || '';

					if (step.orderAddressesMoved || step.customerAddressesMoved || step.trackingSynced) {
						stepDetail += ' ' + [
							step.orderAddressesMoved ? step.orderAddressesMoved + ' ' + getString('orderAddresses', 'order address rows') : '',
							step.customerAddressesMoved ? step.customerAddressesMoved + ' ' + getString('userAddresses', 'customer address rows') : '',
							step.trackingSynced ? step.trackingSynced + ' ' + getString('trackingOrders', 'shipment orders') : ''
						].filter(Boolean).join(', ') + '.';
					}

					setProgress(percent, getString('processing', 'Syncing...'), stepDetail);

					if (data.stopped) {
						setProgress(percent, getString('stopped', 'Sync stopped because no progress was made in the latest step.'), data.message || '');
						progress.addClass('is-error').removeClass('is-running is-complete');
						stopRunning();
						return;
					}

					if (data.done) {
						complete(data.message || '');
						return;
					}

					window.setTimeout(runStep, 250);
				})
				.fail(function () {
					fail('');
				});
		}

		form.on('submit', function (event) {
			event.preventDefault();

			if (running) {
				return;
			}

			if (!window.confirm(getString('confirmMigrate', 'Continue?'))) {
				return;
			}

			running = true;
			button.prop('disabled', true);
			progress.removeClass('is-error is-complete').addClass('is-running');
			setProgress(0, getString('preparing', 'Preparing sync...'), '');

			requestMigration('start')
				.done(function (response) {
					if (!response || !response.success || !response.data) {
						fail(response && response.data && response.data.message ? response.data.message : '');
						return;
					}

					initialRemaining = parseCount(response.data.remaining);

					if (response.data.done || initialRemaining <= 0) {
						complete(response.data.message || getString('noData', 'No safe data from Le Van Toan plugins is available to sync.'));
						return;
					}

					setProgress(1, getString('processing', 'Syncing...'), response.data.message || '');
					runStep();
				})
				.fail(function () {
					fail('');
				});
		});
	});
})(jQuery);

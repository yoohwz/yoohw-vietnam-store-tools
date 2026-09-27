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

	function initHealthAssistant() {
		var page = $('.vck-store-health');
		if (!page.length) { return; }
		var scan = page.find('.vck-health-scan');
		var migrate = page.find('.vck-health-migrate');
		var report = page.find('.vck-health-report');
		var assistant = page.find('.vck-health-assistant');
		var scanResult = page.find('.vck-health-scan-result');
		var progress = page.find('.vck-health-progress');
		var errors = page.find('.vck-health-errors');
		var running = false;
		var lastStatus = null;
		var moved = [0, 0, 0];

		function lock(value) {
			running = value;
			scan.prop('disabled', value);
			migrate.prop('disabled', value || !lastStatus || parseCount(lastStatus.remaining) <= 0);
		}
		function display(data) {
			lastStatus = data;
			var safe = parseCount(data.remaining);
			var review = parseCount(data.addressesReview) + parseCount(data.customerAddressesReview);
			page.find('[data-health-metric="legacy"]').text(safe);
			page.find('[data-health-hint="legacy"]').text(getString('exactSafeRows', 'Exact-safe rows'));
			page.find('[data-health-metric="review"]').text(review);
			assistant.prop('hidden', safe <= 0);
			scanResult.prop('hidden', safe > 0).text(safe > 0 ? '' : getString(review > 0 ? 'manualOnly' : 'noMigratable', ''));
			page.find('.vck-health-counts').prop('hidden', false).find('[data-count]').each(function () {
				$(this).text(parseCount(data[$(this).attr('data-count')]));
			});
			report.text(data.report || data.message || '');
		}
		function fail(response) {
			lastStatus = null;
			assistant.prop('hidden', true);
			progress.text(getString('requestFailed', '') + ' ' + (response && response.data && response.data.message || ''));
			lock(false);
		}
		function showMoved() {
			progress.empty();
			['orderAddresses', 'userAddresses', 'trackingOrders'].forEach(function (key, index) {
				$('<p>').text(getString(key, key) + ': ' + moved[index]).appendTo(progress);
			});
		}
		function finishScan(message) {
			// An explicit final read also refreshes samples after the final write.
			requestMigration('scan').done(function (response) {
				if (!response || !response.success || !response.data) { fail(response); return; }
				display(response.data);
				$('<p>').text(message).appendTo(progress);
				lock(false);
			}).fail(function () { fail(); });
		}
		function step() {
			var before = parseCount(lastStatus.remaining);
			requestMigration('step').done(function (response) {
				if (!response || !response.success || !response.data) { fail(response); return; }
				var data = response.data;
				var result = data.step || {};
				moved[0] += parseCount(result.orderAddressesMoved);
				moved[1] += parseCount(result.customerAddressesMoved);
				moved[2] += parseCount(result.trackingSynced);
				display(data);
				showMoved();
				['addressErrors', 'customerAddressErrors', 'trackingErrors'].forEach(function (key) {
					(result[key] || []).forEach(function (message) {
						if (errors.children().length < 15) { $('<li>').text(message).appendTo(errors); }
					});
				});
				if (data.stopped || (!data.done && parseCount(data.remaining) >= before)) {
					finishScan(getString('stopped', ''));
				} else if (data.done) {
					finishScan(getString('completed', ''));
				} else {
					window.setTimeout(step, 250);
				}
			}).fail(function () { fail(); });
		}
		scan.on('click', function () {
			if (running) { return; }
			lock(true);
			errors.empty();
			progress.text(getString('scanning', ''));
			requestMigration('scan').done(function (response) {
				if (!response || !response.success || !response.data) { fail(response); return; }
				display(response.data);
				progress.empty();
				lock(false);
			}).fail(function () { fail(); });
		});
		migrate.on('click', function () {
			if (running || !lastStatus || parseCount(lastStatus.remaining) <= 0 || !window.confirm(getString('confirmMigrate', 'Continue?'))) { return; }
			lock(true);
			errors.empty();
			moved = [0, 0, 0];
			showMoved();
			step();
		});
	}

	$(function () {
		if (!config.ajaxUrl || !config.nonce || !config.migrationTool) {
			return;
		}

		initHealthAssistant();

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

					var errors = [].concat(step.addressErrors || [], step.customerAddressErrors || [], step.trackingErrors || []).slice(0, 15).join(' ');
					stepDetail += ' ' + errors;

					setProgress(percent, getString('processing', 'Syncing...'), stepDetail);

					if (data.stopped) {
						setProgress(percent, getString('stopped', 'Sync stopped because no progress was made in the latest step.'), stepDetail);
						progress.addClass('is-error').removeClass('is-running is-complete');
						stopRunning();
						return;
					}

					if (data.done) {
						complete(stepDetail);
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

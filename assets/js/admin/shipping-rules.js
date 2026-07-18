(function($) {
	'use strict';

	var config = window.yoohwVietnamStoreToolsShippingRules || {};
	var provinces = config.provinces || {};
	var wards = config.wards || {};
	var shippingClasses = config.shippingClasses || {};
	var csvHeaders = config.csvHeaders || [];

	function getString(key, fallback) {
		return config.i18n && config.i18n[key] ? config.i18n[key] : fallback;
	}

	function applyAdminColorScheme(root) {
		var primaryButton = root.find('.button-primary').get(0);
		var accent;
		var channels;

		if (!primaryButton || !window.getComputedStyle) {
			return;
		}

		accent = window.getComputedStyle(primaryButton).backgroundColor;
		channels = String(accent || '').match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);

		if (!channels || !root.get(0)) {
			return;
		}

		root.get(0).style.setProperty('--vck-rule-accent', accent);
		root.get(0).style.setProperty('--vck-rule-accent-soft', 'rgba(' + channels[1] + ', ' + channels[2] + ', ' + channels[3] + ', 0.1)');
		root.get(0).style.setProperty('--vck-rule-accent-ring', 'rgba(' + channels[1] + ', ' + channels[2] + ', ' + channels[3] + ', 0.18)');
	}

	function makeId() {
		return 'rule_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
	}

	function normalizeEnabled(value) {
		return value === true || value === 1 || ['1', 'yes', 'true', 'on'].indexOf(String(value || '').toLowerCase()) !== -1 ? 'yes' : 'no';
	}

	function normalizeOptionalValue(value) {
		return typeof value === 'undefined' || value === null ? '' : String(value);
	}

	function normalizeRule(rule, index) {
		rule = rule && typeof rule === 'object' ? rule : {};

		return {
			id: String(rule.id || makeId()),
			enabled: normalizeEnabled(typeof rule.enabled === 'undefined' ? 'yes' : rule.enabled),
			name: String(rule.name || getString('newRule', 'New shipping rule') + ' ' + (index + 1)),
			province: String(rule.province || ''),
			ward: String(rule.ward || ''),
			min_total: normalizeOptionalValue(rule.min_total),
			max_total: normalizeOptionalValue(rule.max_total),
			min_weight: normalizeOptionalValue(rule.min_weight),
			max_weight: normalizeOptionalValue(rule.max_weight),
			shipping_class: String(rule.shipping_class || ''),
			fee: String(typeof rule.fee === 'undefined' || rule.fee === '' ? '0' : rule.fee),
			free_threshold: normalizeOptionalValue(rule.free_threshold),
			cod: ['inherit', 'yes', 'no'].indexOf(String(rule.cod || 'inherit')) !== -1 ? String(rule.cod || 'inherit') : 'inherit'
		};
	}

	function parseRules(value) {
		try {
			var parsed = JSON.parse(value || '[]');
			return Array.isArray(parsed) ? parsed.map(normalizeRule) : [];
		} catch (error) {
			return [];
		}
	}

	function createOption(value, label, selected) {
		return $('<option>')
			.attr('value', value)
			.prop('selected', String(value) === String(selected))
			.text(label);
	}

	function createSelect(options, value, key, disabled) {
		var select = $('<select>')
			.attr('data-vck-rule-key', key)
			.prop('disabled', Boolean(disabled));

		options.forEach(function(option) {
			select.append(createOption(option.value, option.label, value));
		});

		return select;
	}

	function createNumberInput(value, key, suffix) {
		var input = $('<input>')
			.attr({
				type: 'number',
				min: '0',
				step: 'any',
				'data-vck-rule-key': key
			})
			.val(value);

		if (!suffix) {
			return input;
		}

		return $('<span class="vck-shipping-rules-editor__number">')
			.append(input)
			.append($('<span>').text(suffix));
	}

	function createField(label, control, className) {
		var field = $('<label class="vck-shipping-rules-editor__field">');

		if (className) {
			field.addClass(className);
		}

		field.append($('<span class="vck-shipping-rules-editor__label">').text(label));
		field.append(control);

		return field;
	}

	function createAction(label, action, disabled, icon) {
		return $('<button type="button" class="button button-small vck-shipping-rules-editor__action">')
			.attr({
				'data-vck-rule-action': action,
				'aria-label': label,
				title: label
			})
			.prop('disabled', Boolean(disabled))
			.toggleClass('is-destructive', action === 'remove')
			.append($('<span aria-hidden="true" class="dashicons">').addClass(icon))
			.append($('<span class="screen-reader-text">').text(label));
	}

	function buildProvinceOptions(selected) {
		var options = [{ value: '', label: getString('anyProvince', 'Any city / province') }];

		Object.keys(provinces).forEach(function(code) {
			options.push({ value: code, label: provinces[code] });
		});

		return createSelect(options, selected, 'province');
	}

	function buildWardOptions(province, selected) {
		var provinceWards = province && wards[province] ? wards[province] : {};
		var options = [{
			value: '',
			label: province ? getString('anyWard', 'Any ward / commune') : getString('selectProvince', 'Select a city / province first')
		}];

		Object.keys(provinceWards).forEach(function(code) {
			options.push({ value: code, label: provinceWards[code] });
		});

		return createSelect(options, selected, 'ward', !province);
	}

	function buildClassOptions(selected) {
		var options = [
			{ value: '', label: getString('anyClass', 'Any shipping class') },
			{ value: '__none__', label: getString('noClass', 'Products without a shipping class') }
		];

		Object.keys(shippingClasses).forEach(function(slug) {
			options.push({ value: slug, label: shippingClasses[slug] });
		});

		return createSelect(options, selected, 'shipping_class');
	}

	function buildCodOptions(selected) {
		return createSelect([
			{ value: 'inherit', label: getString('inheritCod', 'Use method default') },
			{ value: 'yes', label: getString('allowCod', 'Allow COD') },
			{ value: 'no', label: getString('disallowCod', 'Disallow COD') }
		], selected, 'cod');
	}

	function getRuleSummary(rule) {
		var provinceLabel = rule.province && provinces[rule.province] ? provinces[rule.province] : getString('anyProvince', 'Any city / province');
		var wardLabel = rule.province && rule.ward && wards[rule.province] && wards[rule.province][rule.ward] ? wards[rule.province][rule.ward] : '';
		var location = wardLabel ? provinceLabel + ' / ' + wardLabel : provinceLabel;
		var fee = Number(rule.fee || 0);
		var formattedFee = Number.isFinite(fee) ? new Intl.NumberFormat(document.documentElement.lang || 'vi-VN').format(fee) : String(rule.fee || 0);

		return location + ' · ' + formattedFee + (config.currencySymbol ? ' ' + config.currencySymbol : '');
	}

	function ShippingRulesEditor(root) {
		this.root = $(root);
		this.input = this.root.find('input[type="hidden"]').first();
		this.list = this.root.find('[data-vck-rule-list]');
		this.status = this.root.find('[data-vck-status]');
		this.fileInput = this.root.find('[data-vck-csv-file]');
		this.rules = parseRules(this.input.val());
		this.openRuleId = '';
		applyAdminColorScheme(this.root);
		this.bind();
		this.render();
	}

	ShippingRulesEditor.prototype.bind = function() {
		var editor = this;

		this.root.on('click', '[data-vck-add-rule]', function() {
			var rule = normalizeRule({ enabled: 'yes' }, editor.rules.length);

			editor.rules.push(rule);
			editor.openRuleId = rule.id;
			editor.render();
		});

		this.root.on('click', '[data-vck-toggle-rule]', function() {
			var card = $(this).closest('[data-vck-rule-index]');
			var index = parseInt(card.attr('data-vck-rule-index'), 10);

			if (!Number.isFinite(index) || !editor.rules[index]) {
				return;
			}

			editor.openRuleId = editor.openRuleId === editor.rules[index].id ? '' : editor.rules[index].id;
			editor.render();
		});

		this.root.on('click', '[data-vck-rule-action]', function() {
			var card = $(this).closest('[data-vck-rule-index]');
			var index = parseInt(card.attr('data-vck-rule-index'), 10);
			var action = $(this).attr('data-vck-rule-action');

			if (!Number.isFinite(index) || !editor.rules[index]) {
				return;
			}

			if (action === 'remove') {
				var wasOpen = editor.openRuleId === editor.rules[index].id;

				editor.rules.splice(index, 1);

				if (wasOpen) {
					editor.openRuleId = '';
				}
			} else if (action === 'up' && index > 0) {
				editor.rules.splice(index - 1, 0, editor.rules.splice(index, 1)[0]);
			} else if (action === 'down' && index < editor.rules.length - 1) {
				editor.rules.splice(index + 1, 0, editor.rules.splice(index, 1)[0]);
			}

			editor.render();
		});

		this.root.on('input change', '[data-vck-rule-key]', function() {
			var control = $(this);
			var card = control.closest('[data-vck-rule-index]');
			var index = parseInt(card.attr('data-vck-rule-index'), 10);
			var key = control.attr('data-vck-rule-key');

			if (!Number.isFinite(index) || !editor.rules[index] || !key) {
				return;
			}

			editor.rules[index][key] = control.is(':checkbox') ? (control.prop('checked') ? 'yes' : 'no') : String(control.val() || '');

			if (key === 'enabled') {
				card.toggleClass('is-disabled', editor.rules[index][key] !== 'yes');
			}

			if (key === 'name') {
				card.find('.vck-shipping-rules-editor__title').text(editor.rules[index][key] || getString('ruleName', 'Rule name'));
				card.find('[data-vck-toggle-rule]').attr('aria-label', getString('editRule', 'Edit rule') + ': ' + (editor.rules[index][key] || getString('ruleName', 'Rule name')));
			}

			if (key === 'province') {
				editor.rules[index].ward = '';
				editor.render();
				return;
			}

			card.find('.vck-shipping-rules-editor__summary').text(getRuleSummary(editor.rules[index]));

			editor.sync();
		});

		this.root.on('click', '[data-vck-import-csv]', function() {
			editor.fileInput.trigger('click');
		});

		this.fileInput.on('change', function() {
			var file = this.files && this.files[0] ? this.files[0] : null;

			if (file) {
				editor.importCsv(file);
			}
		});

		this.root.on('click', '[data-vck-export-csv]', function() {
			editor.exportCsv();
		});
	};

	ShippingRulesEditor.prototype.render = function() {
		var editor = this;

		this.list.empty();

		if (!this.rules.length) {
			this.list.append($('<p class="vck-shipping-rules-editor__empty">').text(getString('emptyRules', 'No shipping rules have been added yet.')));
			this.sync();
			return;
		}

		this.rules.forEach(function(rule, index) {
			var isOpen = editor.openRuleId === rule.id;
			var bodyId = 'vck-shipping-rule-body-' + rule.id.replace(/[^a-zA-Z0-9_-]/g, '');
			var card = $('<section class="vck-shipping-rules-editor__rule">')
				.attr('data-vck-rule-index', index)
				.toggleClass('is-open', isOpen)
				.toggleClass('is-disabled', rule.enabled !== 'yes');
			var header = $('<div class="vck-shipping-rules-editor__rule-header">');
			var position = $('<span class="vck-shipping-rules-editor__position">')
				.attr('aria-label', getString('ruleName', 'Rule name') + ' ' + (index + 1))
				.text('#' + (index + 1));
			var toggle = $('<button type="button" class="vck-shipping-rules-editor__toggle">')
				.attr({
					'data-vck-toggle-rule': '',
					'aria-expanded': isOpen ? 'true' : 'false',
					'aria-controls': bodyId,
					'aria-label': getString('editRule', 'Edit rule') + ': ' + rule.name
				})
				.append(
					$('<span class="vck-shipping-rules-editor__heading">')
						.append($('<strong class="vck-shipping-rules-editor__title">').text(rule.name))
						.append($('<span class="vck-shipping-rules-editor__summary">').text(getRuleSummary(rule)))
				)
				.append($('<span aria-hidden="true" class="dashicons vck-shipping-rules-editor__chevron">').addClass(isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'));
			var enabled = $('<input type="checkbox">')
				.attr('data-vck-rule-key', 'enabled')
				.prop('checked', rule.enabled === 'yes');
			var enabledLabel = $('<label class="vck-shipping-rules-editor__enabled">')
				.append(enabled)
				.append($('<span class="vck-shipping-rules-editor__switch" aria-hidden="true">'))
				.append($('<span>').text(getString('enabled', 'Enabled')));
			var name = $('<input type="text" class="vck-shipping-rules-editor__name">')
				.attr({
					'data-vck-rule-key': 'name',
					'aria-label': getString('ruleName', 'Rule name'),
					placeholder: getString('ruleName', 'Rule name')
				})
				.val(rule.name);
			var actions = $('<div class="vck-shipping-rules-editor__actions">')
				.append(createAction(getString('moveUp', 'Move up'), 'up', index === 0, 'dashicons-arrow-up-alt2'))
				.append(createAction(getString('moveDown', 'Move down'), 'down', index === editor.rules.length - 1, 'dashicons-arrow-down-alt2'))
				.append(createAction(getString('remove', 'Remove rule'), 'remove', false, 'dashicons-trash'));
			var body = $('<div class="vck-shipping-rules-editor__rule-body">')
				.attr('id', bodyId)
				.prop('hidden', !isOpen);
			var conditions = $('<section class="vck-shipping-rules-editor__group">');
			var conditionsGrid = $('<div class="vck-shipping-rules-editor__grid vck-shipping-rules-editor__grid--conditions">');
			var outcome = $('<section class="vck-shipping-rules-editor__group vck-shipping-rules-editor__group--outcome">');
			var outcomeGrid = $('<div class="vck-shipping-rules-editor__grid vck-shipping-rules-editor__grid--outcome">');

			header.append(position).append(toggle).append(enabledLabel).append(actions);
			body.append(createField(getString('ruleName', 'Rule name'), name, 'vck-shipping-rules-editor__field--rule-name'));
			conditions.append($('<h4 class="vck-shipping-rules-editor__group-title">').text(getString('conditions', 'Conditions')));
			conditionsGrid.append(createField(getString('province', 'City / Province'), buildProvinceOptions(rule.province), 'vck-shipping-rules-editor__field--half'));
			conditionsGrid.append(createField(getString('ward', 'Ward / Commune'), buildWardOptions(rule.province, rule.ward), 'vck-shipping-rules-editor__field--half'));
			conditionsGrid.append(createField(getString('minTotal', 'Minimum cart total'), createNumberInput(rule.min_total, 'min_total', config.currencySymbol || '')));
			conditionsGrid.append(createField(getString('maxTotal', 'Maximum cart total'), createNumberInput(rule.max_total, 'max_total', config.currencySymbol || '')));
			conditionsGrid.append(createField(getString('minWeight', 'Minimum weight'), createNumberInput(rule.min_weight, 'min_weight', config.weightUnit || 'kg')));
			conditionsGrid.append(createField(getString('maxWeight', 'Maximum weight'), createNumberInput(rule.max_weight, 'max_weight', config.weightUnit || 'kg')));
			conditionsGrid.append(createField(getString('shippingClass', 'Shipping class'), buildClassOptions(rule.shipping_class), 'vck-shipping-rules-editor__field--half vck-shipping-rules-editor__field--shipping-class'));
			conditions.append(conditionsGrid);

			outcome.append($('<h4 class="vck-shipping-rules-editor__group-title">').text(getString('shippingOutcome', 'Shipping outcome')));
			outcomeGrid.append(createField(getString('fee', 'Shipping fee'), createNumberInput(rule.fee, 'fee', config.currencySymbol || '')));
			outcomeGrid.append(createField(getString('freeThreshold', 'Free shipping threshold'), createNumberInput(rule.free_threshold, 'free_threshold', config.currencySymbol || '')));
			outcomeGrid.append(createField(getString('cod', 'Cash on delivery'), buildCodOptions(rule.cod), 'vck-shipping-rules-editor__field--cod'));
			outcome.append(outcomeGrid);

			body.append(conditions).append(outcome);
			card.append(header).append(body);
			editor.list.append(card);
		});

		this.sync();
	};

	ShippingRulesEditor.prototype.sync = function() {
		this.input.val(JSON.stringify(this.rules));
	};

	ShippingRulesEditor.prototype.setStatus = function(message, error) {
		this.status.text(message || '').toggleClass('is-error', Boolean(error));
	};

	ShippingRulesEditor.prototype.importCsv = function(file) {
		var editor = this;

		if (!window.confirm(getString('confirmImport', 'Importing will replace the current rules in this editor. Continue?'))) {
			this.fileInput.val('');
			return;
		}

		var reader = new FileReader();

		reader.onload = function(event) {
			try {
				var rows = parseCsv(String(event.target.result || '').replace(/^\uFEFF/, ''));
				var headers = rows.shift().map(function(header) { return String(header || '').trim(); });

				if (!csvHeaders.length || csvHeaders.some(function(header) { return headers.indexOf(header) === -1; })) {
					throw new Error('Invalid CSV headers');
				}

				editor.rules = rows.filter(function(row) {
					return row.some(function(value) { return String(value || '').trim() !== ''; });
				}).map(function(row, index) {
					var raw = {};

					headers.forEach(function(header, column) {
						raw[header] = typeof row[column] === 'undefined' ? '' : row[column];
					});

					raw.id = makeId();
					return normalizeRule(raw, index);
				});
				editor.openRuleId = '';

				editor.render();
				editor.setStatus(getString('imported', 'CSV rules were imported. Save changes to apply them.'), false);
			} catch (error) {
				editor.setStatus(getString('importFailed', 'Could not import the CSV file. Check the header row and data format.'), true);
			}

			editor.fileInput.val('');
		};

		reader.onerror = function() {
			editor.setStatus(getString('importFailed', 'Could not import the CSV file. Check the header row and data format.'), true);
			editor.fileInput.val('');
		};

		reader.readAsText(file, 'UTF-8');
	};

	ShippingRulesEditor.prototype.exportCsv = function() {
		var rows = [csvHeaders];

		this.rules.forEach(function(rule) {
			rows.push(csvHeaders.map(function(header) {
				return typeof rule[header] === 'undefined' ? '' : rule[header];
			}));
		});

		var csv = rows.map(function(row) {
			return row.map(escapeCsvValue).join(',');
		}).join('\r\n');
		var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');

		link.href = url;
		link.download = getString('csvFilename', 'vietnam-shipping-rules.csv');
		document.body.appendChild(link);
		link.click();
		link.remove();
		URL.revokeObjectURL(url);
		this.setStatus(getString('exported', 'CSV rules were exported.'), false);
	};

	function escapeCsvValue(value) {
		value = String(value || '');
		return '"' + value.replace(/"/g, '""') + '"';
	}

	function parseCsv(text) {
		var rows = [];
		var row = [];
		var value = '';
		var quoted = false;

		for (var index = 0; index < text.length; index += 1) {
			var character = text[index];

			if (quoted) {
				if (character === '"' && text[index + 1] === '"') {
					value += '"';
					index += 1;
				} else if (character === '"') {
					quoted = false;
				} else {
					value += character;
				}
			} else if (character === '"') {
				quoted = true;
			} else if (character === ',') {
				row.push(value);
				value = '';
			} else if (character === '\n') {
				row.push(value.replace(/\r$/, ''));
				rows.push(row);
				row = [];
				value = '';
			} else {
				value += character;
			}
		}

		if (value !== '' || row.length) {
			row.push(value.replace(/\r$/, ''));
			rows.push(row);
		}

		if (!rows.length) {
			throw new Error('Empty CSV');
		}

		return rows;
	}

	function initEditors(context) {
		$(context).find('.vck-shipping-rules-editor').addBack('.vck-shipping-rules-editor').each(function() {
			if (!$(this).data('vckShippingRulesEditor')) {
				$(this).data('vckShippingRulesEditor', new ShippingRulesEditor(this));
			}
		});
	}

	$(function() {
		if (!csvHeaders.length) {
			return;
		}

		initEditors(document);

		if (window.MutationObserver) {
			new MutationObserver(function(mutations) {
				mutations.forEach(function(mutation) {
					mutation.addedNodes.forEach(function(node) {
						if (node.nodeType === 1) {
							initEditors(node);
						}
					});
				});
			}).observe(document.body, { childList: true, subtree: true });
		}
	});
})(jQuery);

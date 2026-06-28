(function($) {
	'use strict';

	function updateTaxInvoiceFields() {
		$('.vck-tax-invoice-request').each(function() {
			var $wrapper = $(this);
			var checked = $wrapper.find('input[name="yoohw_vietnam_store_tools_tax_invoice_requested"]').is(':checked');
			var $fields = $wrapper.find('[data-vck-tax-invoice-fields]');

			$fields.prop('hidden', ! checked).toggleClass('is-visible', checked);
			$fields.find(':input').prop('disabled', ! checked);
		});
	}

	$(function() {
		updateTaxInvoiceFields();
	});

	$(document.body)
		.on('change', 'input[name="yoohw_vietnam_store_tools_tax_invoice_requested"]', updateTaxInvoiceFields)
		.on('updated_checkout', updateTaxInvoiceFields);
})(jQuery);

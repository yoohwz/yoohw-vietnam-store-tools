=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout blocks, shipping
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.9
WC tested up to: 11.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Vietnam-focused WooCommerce toolkit for addresses, payments, invoices, shipping, tracking, and store operations.

== Description ==

Vietnam Store Toolkit for WooCommerce adds Vietnam-specific checkout and address fields, payment and invoice tools, shipping and tracking, order operations, and guided migration. It works with WooCommerce's checkout, orders, and shipping flows.

Learn more about the plugin on the [official Vietnam Store Toolkit website](https://vietnamstore.org/).

= Official Links =

* [Vietnam Store Toolkit Website](https://vietnamstore.org/) — overview and features.
* [Documentation](https://vietnamstore.org/documentation/) — system requirements, configuration, and examples.
* [Support](https://vietnamstore.org/support/) — official support resources and instructions for submitting requests.
* [Source Code and Development on GitHub](https://github.com/yoohwz/yoohw-vietnam-store-tools) — view the code, report issues, suggest features, and submit pull requests.
* [WordPress.org Release](https://wordpress.org/plugins/yoohw-vietnam-store-tools/) — the official public installation source.
* [Vietnamese Addresses for WooCommerce](https://vietnamstore.org/dia-chi-viet-nam-woocommerce/)
* [VietQR for WooCommerce](https://vietnamstore.org/vietqr-woocommerce/)
* [VAT Invoices for WooCommerce](https://vietnamstore.org/hoa-don-gtgt-woocommerce/)
* [WooCommerce Order Tracking](https://vietnamstore.org/tracking-don-hang-woocommerce/)
* [Classic Checkout vs. Checkout Blocks](https://vietnamstore.org/classic-checkout-vs-checkout-blocks/)
* [WooCommerce Address Data Migration Guide](https://vietnamstore.org/chuyen-du-lieu-dia-chi-woocommerce/)

= Key Features =

* Two-tier Province/City and Ward/Commune addresses in Classic Checkout, Cart, and Checkout Blocks, with Store API support.
* Ward-level restrictions in native WooCommerce Shipping Zones.
* VietQR payment details for WooCommerce Direct bank transfer.
* Optional VND-to-USD conversion for compatible WooCommerce PayPal Payments checkouts using a merchant-set rate.
* Manual payment reconciliation that records evidence without automatically marking orders paid.
* VAT request fields and a provider-neutral electronic invoice handoff.
* Shipping fee rules for address, cart, weight, shipping class, free shipping, and COD conditions.
* Shipment details, a manual tracking timeline, and a customer order tracking page.
* HPOS-compatible order filters, bulk actions, and CSV exports.
* Store Health checks and guided migration of compatible legacy address and GHTK data.
* Vietnamese phone validation and localized interface, email, and validation messages.

= Vietnamese Addresses and Checkout Blocks =

The plugin stores province/city codes in WooCommerce's `state` field and ward/commune codes in its `city` field. It validates address combinations and hides the postcode when appropriate.

The fields appear at checkout, in My Account and the cart, and in store, customer, and order addresses. In Cart and Checkout Blocks, the Ward/Commune list follows the selected Province/City through the Store API, including on custom block pages.

= Payments, VietQR and Reconciliation =

VietQR extends WooCommerce Direct bank transfer (`bacs`); it does not create a new gateway. QR images and transfer details can appear on order confirmation, in My Account, in emails, and in wp-admin. VietQR does not confirm bank transactions or mark an order paid.

Staff can record transfer observations and references, then manually reconcile an exact order amount on the order screen. Payment Reconciliation retains evidence, history, and corrections. It does not complete WooCommerce payments, set a paid date or transaction ID, issue refunds, or change stock.

Optional PayPal VND-to-USD conversion requires a compatible WooCommerce PayPal Payments setup and a merchant-entered VND-per-USD rate. The rate is locked for each payment attempt while the WooCommerce order remains in VND. Compatibility checks prevent conversion when the supported payment path is unavailable; this is not a general currency switcher.

= VAT Requests and Electronic Invoices =

When enabled under Vietnam store > Core features, Classic Checkout and Checkout Blocks can collect company, tax, invoice email, and company address details. This is independent of WooCommerce tax calculation.

The provider-neutral Electronic Invoice Handoff tracks status, provider references, document history, PDF/XML files, and customer email handling. The plugin does not issue a legal electronic invoice or call an invoice provider API; issuance remains with your provider.

= Shipping Fees and Order Tracking =

Native WooCommerce Shipping Zones can be narrowed to one or more Vietnamese wards/communes from the built-in Zone regions tree, where each ward/commune is a child of its province/city. Ward restrictions apply to every shipping method in that zone and work together with the zone's country, province/city, and postcode regions. WooCommerce checks zones in their configured order, so place ward-specific zones above broader fallback zones.

Deactivating the plugin removes ward narrowing: mixed zones fall back to their native country, province/city, continent, and postcode regions, while ward-only zones stop matching until the plugin is active again. Toggling the separate Vietnamese address-field feature does not delete or disable saved ward restrictions.

The “Shipping Fee Rules” method works within WooCommerce Shipping Zones. Rules are evaluated from top to bottom and can be based on province/city, ward/commune, cart total, weight, shipping class, fee, free-shipping threshold, and COD. The editor supports sorting and UTF-8 CSV import/export.

The Shipping panel lets staff enter a carrier, tracking number, and tracking URL, send an email, and maintain a customer-facing timeline. Updates belong to the current shipment; stale actions from a replaced or cancelled shipment are rejected. Core manual tracking needs no carrier API. URL templates can create tracking links from `{tracking_code}`.

Add the “Order Tracking” block or `[yoohw_order_tracking]` shortcode to let customers look up orders using the order number together with their billing email address or phone number. Results do not display addresses, products, totals, or contact details.

= Order Management and Store Health =

The WooCommerce > Orders screen has a compact Information column, relevant filters and bulk actions, and CSV exports. Staff can manage shipment and invoice handoffs there; Payment Reconciliation stays on the order screen. These tools support HPOS and legacy order storage.

Store Health shows configuration readiness and lets staff explicitly scan legacy data. Opening it does not scan or migrate orders or customers. Migration starts only after a merchant action: scan, review the report, back up data, then synchronize compatible data in batches. Ambiguous data requires manual review.

= Migration and Data =

Migration tools support compatible legacy addresses and GHTK tracking data from Le Van Toan's plugin. Review the scan report before synchronizing.

The 2026-07 administrative dataset is based on the National Statistics Office of Viet Nam through Vietnam Provinces API v2. The bank and BIN lists are based on the VietQR bank list API. Sources and update procedures are documented in `data/SOURCES.md`.

= External Services and Privacy =

Source APIs are used only to build static data; the plugin does not call them at runtime. When VietQR is enabled, the browser or email client loads the QR image from VietQR.io by CASSO. The image URL may contain the BIN, account number, QR template, amount, transfer description, and account holder name.

* Service: https://vietqr.io/
* Documentation: https://vietqr.io/danh-sach-api/link-tao-ma-nhan/
* Terms: https://casso.vn/thoa-thuan-su-dung-phan-mem/
* Privacy: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Address, phone, invoice, and shipping data is stored in the store's WordPress/WooCommerce installation. The plugin does not add analytics, advertising, or remote data collection services.

The plugin stores no separate PayPal credentials. If conversion is enabled, the installed WooCommerce PayPal Payments extension continues to communicate with PayPal under its own configuration; this plugin participates in the guarded amount and currency conversion path.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Vietnam Store Toolkit for WooCommerce.
3. Review your selling locations, store address, and Vietnam store settings.
4. Configure Direct bank transfer and VietQR if you use bank transfers.
5. Configure PayPal conversion only with a compatible WooCommerce PayPal Payments setup.
6. Enable invoice requests and configure shipping zones, fee rules, and tracking as needed.
7. Open Store Health and run an explicit scan before migrating legacy data.

See the [Documentation](https://vietnamstore.org/documentation/) for detailed setup.

== Frequently Asked Questions ==

= Does the plugin support Cart and Checkout Blocks? =

Yes. The plugin supports Province/City and Ward/Commune fields, VAT invoices, phone number validation, VietQR, and shipping information. WooCommerce 8.9 or later is required.

= How does the plugin store Vietnamese addresses? =

Province/city codes are stored in WooCommerce's `state` field; ward/commune/special-zone codes are stored in its `city` field.

= Can customers request VAT invoices? =

Yes, when “Accept invoice requests at checkout” is enabled under Vietnam store > Core features.

= Does VietQR automatically confirm payments? =

No. VietQR is added to Direct bank transfer but does not connect to bank transactions.

= Does payment reconciliation mark an order as paid? =

No. It records and reconciles payment evidence without completing the WooCommerce payment or changing its paid state.

= Does PayPal VND-to-USD conversion change the WooCommerce order currency? =

No. The order remains in VND; a compatible PayPal payment attempt uses the guarded USD conversion path.

= Does Store Health automatically scan or migrate my store? =

No. Opening Store Health is safe; scanning and migration each require an explicit merchant action.

= Where do I configure shipping zones or fees at the ward/commune level? =

Go to WooCommerce > Settings > Shipping and open a zone. In the native Zone regions tree, expand Vietnam and a Province / City, then select one or more Ward / Commune children to restrict every method in that zone. To calculate different fees with additional cart or product conditions, add “Shipping Fee Rules” to the zone.

= How do I create an order tracking page? =

Add the “Order Tracking” block or `[yoohw_order_tracking]` shortcode to a page.

= Does the plugin include carrier API integrations? =

No. The public release provides API-free tracking and a framework for custom connectors.

= Does the plugin support HPOS and Vietnamese? =

Yes. The plugin is HPOS-compatible and includes Vietnamese translations for the interface, emails, and validation messages.

== Changelog ==

= 1.2.0 (September 27, 2026) =

* New: Added optional VND-to-USD payment conversion for compatible WooCommerce PayPal Payments checkout flows, using a merchant-entered rate locked to each payment attempt and guarded compatibility checks.
* New: Added manual payment reconciliation on the order screen to record observations, match exact order amounts, review evidence, and correct entries without automatically marking orders paid.
* New: Added a Store health dashboard and a guided assistant to scan, back up, and batch-migrate compatible legacy address and GHTK tracking data.
* New: Expanded the provider-neutral electronic invoice handoff with revision-guarded updates, document history, provider references, and clearer order administration and email details; invoice issuance remains external.
* Improve: Protected shipment and tracking updates with shipment identity and stale-action checks, preserving the current shipment and its timeline across order instances and cancellations.
* Improve: Refined order information layout and compacted the payment reconciliation controls in a side metabox; improved BACS/VietQR transfer details and copy behavior.
* Compatibility: Filled missing Vietnamese translation keys from bundled catalogs while retaining WordPress language-pack priority, and refined the Vietnamese Store health label.
* Developer: Expanded payment, shipment, and electronic-invoice extension contracts and strengthened release, checkout, localization, and HPOS validation.

See `changelog.txt` for the complete change history.

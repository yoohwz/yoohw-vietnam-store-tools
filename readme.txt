=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout blocks, shipping
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.9
WC tested up to: 10.9
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Vietnam-focused WooCommerce toolkit for two-tier addresses, Checkout Blocks, VietQR, VAT invoices, shipping fees, and order tracking.

== Description ==

Vietnam Store Toolkit for WooCommerce adds essential tools for stores in Vietnam: province/city and ward/commune addresses, VietQR, VAT invoices, phone numbers, shipping fees, tracking numbers, and order tracking.

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

* Two-tier addresses covering 34 provinces/cities and 3,321 wards/communes/special zones.
* Province/city-dependent ward/commune lists in Classic Checkout, Cart, and Checkout Blocks.
* VAT invoice requests and a provider-neutral electronic invoicing workflow.
* VietQR for WooCommerce Direct bank transfer.
* Vietnamese phone number normalization and validation.
* Shipping fee rules based on address, cart, weight, shipping class, free shipping, and COD.
* Tracking numbers, a manual timeline, and an order tracking page with no carrier API required.
* HPOS-compatible order filters, bulk actions, and CSV exports.

= Vietnamese Addresses and Checkout Blocks =

The plugin stores province/city codes in WooCommerce's `state` field and ward/commune codes in its `city` field, and hides the postcode when appropriate. Address combinations are validated before they are saved, and ward/commune lists are loaded only when needed.

The fields are applied to checkout, My Account, the cart, store addresses, customer profiles, and orders in wp-admin. In Cart and Checkout Blocks, Ward/Commune is a Province/City-dependent list that synchronizes directly with the Store API, including on custom block pages.

= VAT Invoices and VietQR =

The invoice request feature is enabled independently under Vietnam store > Core features and does not depend on WooCommerce tax calculation. Classic Checkout and Checkout Blocks can collect the company name, tax identification number, invoice email address, and company address.

The electronic invoicing workflow stores the status, number/series, issue date, lookup URL, provider, PDF/XML files, and change log. The plugin does not issue invoices itself or call a provider API.

VietQR is added to WooCommerce Direct bank transfer (`bacs`) and does not create a new payment gateway. The QR image and transfer details can appear on the order confirmation page, in My Account, in emails, and in wp-admin. The plugin does not confirm transactions or automatically mark orders as paid.

= Shipping Fees and Order Tracking =

The “Shipping Fee Rules” method works within WooCommerce Shipping Zones. Rules are evaluated from top to bottom and can be based on province/city, ward/commune, cart total, weight, shipping class, fee, free-shipping threshold, and COD. The editor supports sorting and UTF-8 CSV import/export.

The Shipping panel lets you enter a carrier, tracking number, and tracking URL; send an email; and maintain a manual timeline. URL templates generate links from `{tracking_code}`, and administrators can also define custom carriers.

Add the “Order Tracking” block or `[yoohw_order_tracking]` shortcode to let customers look up orders using the order number together with their billing email address or phone number. Results do not display addresses, products, totals, or contact details.

= Order Management and HPOS =

The WooCommerce > Orders screen includes a compact Information column for invoice and tracking details, advanced filters, actions to resend tracking emails and update carriers, CSV exports, and handoff progress controls. The plugin uses the WooCommerce order API and supports both HPOS and legacy storage.

= Migration and Data =

WooCommerce Status Tools can scan, back up, and batch-synchronize legacy addresses and GHTK data from Le Van Toan's plugin. Run the scan tool and review its report before synchronizing.

The 2026-07 administrative dataset is based on the National Statistics Office of Viet Nam through Vietnam Provinces API v2. The bank and BIN lists are based on the VietQR bank list API. Sources and update procedures are documented in `data/SOURCES.md`.

= External Services and Privacy =

Source APIs are used only to build static data; the plugin does not call them at runtime. When VietQR is enabled, the browser or email client loads the QR image from VietQR.io by CASSO. The image URL may contain the BIN, account number, QR template, amount, transfer description, and account holder name.

* Service: https://vietqr.io/
* Documentation: https://vietqr.io/danh-sach-api/link-tao-ma-nhan/
* Terms: https://casso.vn/thoa-thuan-su-dung-phan-mem/
* Privacy: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Address, phone, invoice, and shipping data is stored in the store's WordPress/WooCommerce installation. The plugin does not add analytics, advertising, or remote data collection services.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Vietnam Store Toolkit for WooCommerce.
3. Review your selling locations and store address in WooCommerce settings.
4. Configure Direct bank transfer if you need VietQR.
5. If you need invoices, enable “Accept invoice requests at checkout” under Vietnam store > Core features.
6. If you need address-based fees, add “Shipping Fee Rules” to a Shipping Zone.
7. If you are migrating from Le Van Toan's plugin, run the scan tool before synchronizing.

== Frequently Asked Questions ==

= Does the plugin support Cart and Checkout Blocks? =

Yes. The plugin supports Province/City and Ward/Commune fields, VAT invoices, phone number validation, VietQR, and shipping information. WooCommerce 8.9 or later is required.

= How does the plugin store Vietnamese addresses? =

Province/city codes are stored in WooCommerce's `state` field; ward/commune/special-zone codes are stored in its `city` field.

= Can customers request VAT invoices? =

Yes, when “Accept invoice requests at checkout” is enabled under Vietnam store > Core features.

= Does VietQR automatically confirm payments? =

No. VietQR is added to Direct bank transfer but does not connect to bank transactions.

= Where do I configure shipping fees at the ward/commune level? =

Go to WooCommerce > Settings > Shipping, open a zone, and add “Shipping Fee Rules.”

= How do I create an order tracking page? =

Add the “Order Tracking” block or `[yoohw_order_tracking]` shortcode to a page.

= Does the plugin include carrier API integrations? =

No. The public release provides API-free tracking and a framework for custom connectors.

= Does the plugin support HPOS and Vietnamese? =

Yes. The plugin is HPOS-compatible and includes Vietnamese translations for the interface, emails, and validation messages.

== Changelog ==

= 1.1.3 (August 14, 2026) =

* Improve: Collapsed the Create shipment and Add shipment journey event forms in the order Shipping metabox, keeping the interface compact while preserving keyboard-accessible toggles.
* Fix: Kept manually added shipment journey events visible in the order metabox after saving.
* Update: Refined the customer-facing Order Tracking status row: Core hides it when no journey event exists, while connector-provided status information remains available.
* Developer: Added an extension point that lets shipping connectors append journey events without replacing their detailed carrier status.

See `changelog.txt` for the complete change history.

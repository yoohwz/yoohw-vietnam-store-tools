=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout, vat invoice
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Vietnam checkout fields, VietQR bank transfer, VAT invoice requests, phone normalization, and shipping tools for WooCommerce.

== Description ==

Vietnam Store Toolkit for WooCommerce helps WooCommerce stores sell in Vietnam with checkout fields, address data, VAT invoice requests, VietQR bank transfer details, phone number normalization, and admin shipping tools built for Vietnamese commerce workflows.

WooCommerce is flexible, but its default checkout and bank transfer experience is not optimized for Vietnam. This plugin keeps WooCommerce's core order and customer data model intact while making the customer checkout, store settings, admin order screen, and Direct bank transfer flow more practical for Vietnam-based stores.

= Built for WooCommerce stores in Vietnam =

Use Vietnam Store Toolkit for WooCommerce when you need:

* Vietnam address fields for WooCommerce checkout, My Account, cart shipping calculator, customer profiles, and admin orders.
* City/province and ward/commune dropdowns based on the 2026 two-level Vietnam administrative unit data.
* A simpler Vietnam checkout layout with Full name, phone, email, province, ward, street address, and optional address line 2.
* VietQR for WooCommerce Direct bank transfer without adding another payment gateway.
* VAT invoice request fields for Vietnamese company buyers.
* Vietnamese phone number normalization for cleaner customer and order data.
* A shipping provider framework for private connectors, internal tools, or SaaS integrations.
* Migration tools for stores moving from Le Van Toan Vietnam checkout or GHTK plugins.

= Core features =

* Adds Vietnam provinces/cities to WooCommerce states.
* Adds ward/commune selections for Vietnam addresses.
* Stores province codes in WooCommerce `state` and ward codes in WooCommerce `city`.
* Hides postcode for Vietnam checkout flows.
* Formats Vietnam addresses with ward/commune and city/province names instead of raw codes.
* Supports checkout, My Account address edit pages, cart shipping calculator, WooCommerce Store Address settings, customer profiles, and admin order editing.
* Hides the country field on the frontend when WooCommerce is configured to sell to only one country.
* Uses lazy ward loading and browser-side caching for frontend forms.
* Validates province and ward combinations before checkout or address saves.
* Normalizes Vietnamese mobile and landline phone numbers.
* Stores E.164 phone metadata, phone type, and detected mobile carrier metadata.
* Adds VAT invoice request fields to checkout when enabled.
* Shows VAT invoice requests in admin orders and New Order emails sent to store admins.
* Enhances WooCommerce Direct bank transfer with VietQR display.
* Adds a Vietnamese bank selector and VietQR/NAPAS BIN handling for BACS accounts.
* Shows VietQR details on the order received page, My Account order page, customer bank transfer emails, and admin orders.
* Adds copy buttons for account number, transfer content, and tracking code where available.
* Adds a Vietnam shipping metabox in admin orders for provider connectors.
* Provides standardized shipment metadata for provider, service, label, tracking, fee, COD amount, status, and sync time.
* Hides technical shipping-rate metadata from WooCommerce admin order line items.
* Declares compatibility with WooCommerce High-Performance Order Storage (HPOS).

= Vietnam address fields for WooCommerce =

The plugin replaces free-text Vietnam address fields with structured WooCommerce address fields.

For Vietnam addresses:

* `state` stores the official city/province code.
* `city` stores the official ward/commune/special-zone code.
* `postcode` is hidden because most Vietnam checkout flows do not need it.
* `first_name` is used as Full name.
* `last_name` and company fields are hidden on the customer checkout flow.
* `address_2` remains available as an optional extra address line.

Because the plugin uses WooCommerce's existing address fields, orders, customers, shipping rates, taxes, admin screens, exports, and integrations can keep using the WooCommerce data model they already understand.

= Address data =

Vietnam administrative unit data is bundled with the plugin for speed and reliability. The bundled data uses the 2026-07 two-level Vietnam administrative structure:

* 34 provinces/cities.
* 3,321 wards/communes/special zones.

Province codes are stored as two-character strings, such as `01`. Ward/commune/special-zone codes are stored as five-character strings, such as `00070`. Keeping these values as strings preserves leading zeroes and avoids duplicate-name ambiguity.

= Admin address support =

Vietnam Store Toolkit for WooCommerce also improves address handling inside wp-admin:

* WooCommerce Store Address settings can use Vietnam city/province and ward/commune selections.
* Customer profile billing and shipping addresses get Vietnam-aware province and ward fields.
* Admin order billing and shipping addresses can be edited with province and ward dropdowns.
* Saved admin order addresses are normalized through WooCommerce order setters where available.

= VAT invoice requests =

The plugin can collect Vietnam VAT invoice requests during checkout. This feature is available when WooCommerce taxes are enabled and the setting is turned on in WooCommerce tax settings.

When customers request a VAT invoice, the checkout can collect:

* Company legal name.
* Tax code.
* Invoice recipient email.
* Company address.

The tax code field accepts the common Vietnam tax code format: 10 digits, optionally followed by a hyphen and 3 digits.

Invoice request data is saved to the order, displayed in a dedicated admin order metabox, and included in the New Order email sent to store admins. It is not added to customer-facing emails by default.

= VietQR bank transfer for WooCommerce =

Vietnam Store Toolkit for WooCommerce enhances the built-in WooCommerce Direct bank transfer payment method (`bacs`) instead of creating a separate gateway.

Store owners can configure bank accounts in the existing WooCommerce Direct bank transfer settings. For Vietnam bank accounts, the plugin adds a bank selector and stores the VietQR/NAPAS bank BIN needed to generate VietQR payment images.

For eligible bank transfer orders, the plugin can display:

* VietQR QR image.
* Bank name.
* Account number.
* Account holder.
* Order amount.
* Transfer content.

The transfer content template supports placeholders:

* `{order_id}`
* `{order_number}`
* `{site_name}`

The default template is `ORDER-{order_number}`.

VietQR details can appear on:

* Order received page.
* My Account order view.
* Customer bank transfer emails, when enabled.
* Admin order screen.

The plugin generates payment QR information only. It does not process payments, confirm bank transfers, connect to bank transaction APIs, or automatically mark orders as paid.

= Vietnamese phone number normalization =

The plugin accepts common Vietnam phone number formats and normalizes valid numbers for billing and shipping phone fields.

Examples:

* `0987654321`
* `098 765 4321`
* `+84987654321`
* `0084 987654321`
* `84 987654321`

Valid Vietnamese mobile and landline numbers are saved in national format, such as `0987654321`. The plugin also stores useful metadata for orders and customers:

* E.164 format, such as `+84987654321`.
* Phone type: `mobile` or `landline`.
* Mobile carrier when detected from the prefix.

Order search is extended to include the normalized phone metadata.

= Vietnam shipping tools =

Vietnam Store Toolkit for WooCommerce includes a shipping provider framework for internal connectors, private plugins, or SaaS integrations.

The framework provides:

* A Vietnam shipping metabox on the WooCommerce admin order screen.
* Provider registration through the `yoohw_vietnam_store_tools_shipping_providers` filter.
* Admin actions for create shipment, sync shipment, print label, and cancel shipment when a provider supports them.
* Standardized order shipment metadata for provider, service, label ID, tracking code, tracking URL, status, fee, insurance fee, COD amount, and last sync time.
* Preselection of the create-shipment provider from the shipping method or checkout shipping-rate metadata when available.
* Cleanup of technical `vck_*` shipping-rate metadata from the admin order line-item display.

The public WordPress.org plugin does not bundle GHTK, Viettel Post, or another carrier API integration. Carrier-specific logic should be provided by a separate connector.

= Migration tools for Le Van Toan plugins =

For stores moving from Le Van Toan Vietnam checkout or GHTK plugins, Vietnam Store Toolkit for WooCommerce adds tools under WooCommerce Status Tools.

The tools can:

* Scan legacy order addresses without changing data.
* Sync safely mappable legacy order address rows into the current province/ward structure.
* Back up legacy address values before saving migrated values.
* Sync legacy GHTK tracking data into the plugin's standardized shipment metadata.
* Process migration steps in browser-side chunks to reduce timeout risk.

The plugin also shows migration guard warnings when known legacy plugins are active and may conflict with Vietnam checkout address fields.

= Data sources =

Vietnam administrative unit data is bundled with the plugin. The bundled file includes source metadata and is based on data from the National Statistics Office of Viet Nam through Vietnam Provinces API v2.

VietQR bank data is bundled from the VietQR bank list API so WooCommerce Direct bank transfer settings can show a bank selector and store the correct VietQR/NAPAS BIN.

The bundled datasets contain factual public identifiers and names only, such as administrative unit codes, administrative unit names, bank names, bank short names, and VietQR/NAPAS BIN values. They are static reference data and are not tracking pixels or runtime API clients.

= External services =

The Vietnam administrative unit and VietQR bank list API URLs referenced in `data/SOURCES.md` are build-time data sources for bundled static data files. The plugin does not call those data source APIs at runtime.

When VietQR display is enabled for WooCommerce Direct bank transfer, the plugin uses the VietQR QR image service to generate payment QR images for bank transfer orders. The service is contacted by the shopper's browser, the store admin's browser, or the email client when a page or email containing the QR image is displayed.

Service provider: VietQR.io by CASSO.

Service website: https://vietqr.io/

Quick Link documentation: https://vietqr.io/danh-sach-api/link-tao-ma-nhanh/

CASSO terms: https://casso.vn/thoa-thuan-su-dung-phan-mem/

CASSO privacy policy: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Data sent to the service can include the receiving bank BIN, bank account number, QR template, order amount, transfer content, and account holder name. These values are placed in the QR image URL only when VietQR display is enabled and a bank transfer order has enough configured bank account data to show a QR code.

The plugin does not use VietQR to process payments, confirm payments, store customer card data, or move money.

= Privacy =

Vietnam Store Toolkit for WooCommerce stores checkout, address, phone, tax invoice, and shipment metadata in the site's own WordPress/WooCommerce database.

The plugin does not add analytics tracking, advertising pixels, or a remote telemetry service.

If VietQR display is enabled, QR images are loaded from the VietQR image service as described in the External services section. Store owners should mention this in their privacy policy if they display VietQR payment QR images to customers.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Install Vietnam Store Toolkit for WooCommerce from the WordPress Plugins screen, or upload the plugin files to `/wp-content/plugins/yoohw-vietnam-store-tools`.
3. Activate the plugin.
4. Review WooCommerce selling locations and store address settings.
5. Configure WooCommerce Direct bank transfer if you want to use VietQR.
6. Enable Vietnam VAT invoice requests in WooCommerce tax settings if your store needs invoice request fields.
7. If you are migrating from Le Van Toan plugins, run the scan tool before syncing legacy data.

== Frequently Asked Questions ==

= Is this a WooCommerce Vietnam checkout plugin? =

Yes. The plugin adapts WooCommerce checkout, My Account address forms, cart shipping calculator, customer profiles, store address settings, and admin order address editing for Vietnam address data.

= How does the plugin store Vietnam addresses? =

It stores the city/province code in WooCommerce `state` and the ward/commune/special-zone code in WooCommerce `city`. This keeps WooCommerce compatibility while avoiding ambiguous text-only address values.

= Does it support the 2026 Vietnam administrative unit structure? =

Yes. The bundled address data uses the 2026-07 two-level Vietnam structure with 34 provinces/cities and 3,321 wards/communes/special zones.

= Does this plugin add a new payment gateway? =

No. VietQR is added to WooCommerce Direct bank transfer. Customers still choose the built-in bank transfer payment method.

= Does VietQR automatically confirm payments? =

No. The plugin displays payment QR information. It does not connect to bank transaction APIs or automatically mark orders as paid.

= Can VietQR include the order amount? =

Yes, when amount inclusion is enabled and the order currency is VND. For non-VND orders, the QR can still show transfer information without an embedded amount.

= Where do I configure the VietQR bank account? =

Go to WooCommerce payment settings and edit Direct bank transfer. The plugin enhances the existing bank account settings with Vietnam bank and VietQR options.

= Can customers request VAT invoices at checkout? =

Yes. Enable WooCommerce taxes, then enable Vietnam tax invoice requests in WooCommerce tax settings. Customers can request an invoice and enter company details during checkout.

= What Vietnam tax code format is accepted? =

The plugin accepts 10 digits, optionally followed by a hyphen and 3 digits, such as `0312345678` or `0312345678-001`.

= Does phone normalization change old orders automatically? =

No. New and edited order/customer phone data is normalized when saved. Existing phone data is not backfilled automatically.

= Does the plugin support HPOS? =

Yes. The plugin declares compatibility with WooCommerce High-Performance Order Storage.

= Does the public plugin include GHTK or Viettel Post integration? =

No. The public plugin includes the shared shipping framework and admin order tools. Carrier-specific integrations should be provided by a separate private, internal, or SaaS connector.

= Can I migrate from Le Van Toan plugins? =

Yes. The plugin includes WooCommerce Status Tools that can scan and sync safely mappable legacy address rows and GHTK shipment metadata. Run the scan tool first and review the report before syncing.

= Does the plugin call external APIs during checkout? =

Address data and bank list data are bundled. The only runtime external service is the VietQR image service when VietQR display is enabled and a QR image is shown.

= Does the plugin include Vietnamese translations? =

Yes. The plugin includes Vietnamese translation files for labels, validation messages, admin UI text, and administrative unit names.

== Changelog ==

= 1.0.2 =

* Added a WooCommerce customer email template for Vietnam shipping tracking updates.
* Added a checkbox in the admin order Vietnam shipping metabox to send the tracking email when saving a manual tracking code.
* Updated the shipping tracking email details table to better match WooCommerce's new email template layout.
* Added Vietnamese translations for the new shipping tracking email and manual-send controls.

= 1.0.1 =

* Added a manual shipment form in the admin order Vietnam shipping metabox when no shipping provider connector is registered.
* Added manual carrier selection and tracking code entry using the plugin's standard `_vck_shipping_*` order metadata.
* Expanded Le Van Toan migration tools to scan and sync customer profile billing and shipping addresses in addition to order addresses and shipment data.
* Improved legacy address migration for pre-2025 ward aliases and Vietnam customer addresses missing a country value.
* Updated migration progress messages to show order address rows, customer address rows, and shipment orders separately.
* Reordered Vietnam customer profile address fields in the admin user screen to country, city/province, ward/commune, address line 1, address line 2, and postcode.

= 1.0.0 =

* First public release as Vietnam Store Toolkit for WooCommerce.
* Added Vietnam city/province and ward/commune address selections for WooCommerce.
* Added two-level Vietnam administrative unit data for 34 provinces/cities and 3,321 wards/communes/special zones.
* Added Vietnam checkout field adjustments for full name, contact fields, country visibility, company field, and postcode.
* Added Vietnam VAT invoice request fields and admin order invoice information.
* Added VAT invoice information to New Order emails sent to store admins.
* Added VietQR support to WooCommerce Direct bank transfer.
* Added Vietnamese bank selector and transfer content template for bank transfer accounts.
* Added VietQR QR display on frontend orders, customer emails, and admin orders.
* Added copy actions for bank account number and transfer content.
* Added Vietnamese phone number normalization and metadata storage.
* Added shipping provider framework with standardized order shipment metadata and admin order actions.
* Added cleaner WooCommerce admin order shipping item display for technical shipping-rate metadata.
* Added shipment creation preselection from the customer's selected checkout shipping method where provider metadata is available.
* Added WooCommerce Status Tools with one-click progress processing for order address and shipment data from Le Van Toan plugins.
* Added migration guard warnings for stores moving from Le Van Toan plugins.
* Added WordPress.org-ready text domain, unique public prefixes, AJAX actions, JavaScript globals, and request permission checks.
* Added Vietnamese translations.

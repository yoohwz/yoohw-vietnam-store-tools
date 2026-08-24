# Vietnam Store Toolkit 1.1.5 extension contracts

This document identifies the existing shipping, shipment-tracking, and electronic-invoice surfaces supported for extensions in the 1.1.5 architecture tranche. These contracts are additive to normal WordPress hook compatibility. They do not introduce provider integrations, endpoints, queues, or mandatory PHP interfaces.

The shared `Yoohw_Vietnam_Store_Tools_Security` and `Yoohw_Vietnam_Store_Tools_Logger` helpers are internal foundations in 1.1.5 and are not stable extension APIs. Their names, methods, and implementation remain internal unless a later public-API decision says otherwise.

## Shipping providers and shipment data

Extensions register providers with the `yoohw_vietnam_store_tools_shipping_providers` filter. The filter value is an array keyed by provider ID or containing provider arrays with an `id`. Each provider supports these normalized fields:

- `id` and `name`;
- `supports`, containing any of `create`, `sync`, `print`, or `cancel`;
- optional callable `render_create_fields`;
- optional callable `create_shipment`, `sync_shipment`, `print_shipment`, and `cancel_shipment` matching the declared capabilities.

Shipment action callbacks receive the WooCommerce order plus a context array containing the action, provider ID, and sanitized provider request. They return a shipment-data array or `WP_Error`. The `render_create_fields` callback receives the order, current shipment data, and render context. The provider registry remains an array/callback contract in 1.1.5; implementations are not required to implement a PHP interface.

Supported public methods are `Yoohw_Vietnam_Store_Tools_Shipping::get_providers()`, `get_provider()`, `get_order_shipping_data()`, and `update_order_shipping_data()`. The read result remains filterable with `yoohw_vietnam_store_tools_order_shipping_data`.

Existing shipment lifecycle actions remain supported:

- `yoohw_vietnam_store_tools_shipping_manual_shipment_saved`;
- `yoohw_vietnam_store_tools_shipping_shipment_created`, `yoohw_vietnam_store_tools_shipping_shipment_synced`, `yoohw_vietnam_store_tools_shipping_shipment_printed`, and `yoohw_vietnam_store_tools_shipping_shipment_cancelled`;
- `yoohw_vietnam_store_tools_shipping_shipment_auto_synced` and `yoohw_vietnam_store_tools_shipping_auto_sync_failed`.

Existing order metadata represented by the `Yoohw_Vietnam_Store_Tools_Shipping::META_*` constants and the shape returned by `get_order_shipping_data()` remain compatibility contracts. Extensions should use the public methods instead of duplicating persistence logic.

## Shipment tracking

The manual-carrier registry remains filterable through `yoohw_vietnam_store_tools_manual_shipping_providers`. URL behavior remains extensible through `yoohw_vietnam_store_tools_default_tracking_url_templates` and `yoohw_vietnam_store_tools_tracking_url`.

Supported public timeline methods are `Yoohw_Vietnam_Store_Tools_Shipment_Tracking::get_timeline_statuses()`, `get_timeline()`, `add_timeline_event()`, and `delete_timeline_event()`. Successful timeline mutations continue to fire `yoohw_vietnam_store_tools_tracking_timeline_updated` with the order, normalized events, and mutation type.

The stored timeline and shipment metadata keep their current shapes in 1.1.5. Extensions should use the public timeline and shipping methods rather than writing those arrays directly.

Public lookup customization remains available through `yoohw_vietnam_store_tools_tracking_lookup_rate_limit`, `yoohw_vietnam_store_tools_tracking_lookup_rate_limit_identifier`, and `yoohw_vietnam_store_tools_tracking_lookup_order`. These filters do not bypass the plugin's normal lookup validation and privacy checks.

## Electronic invoice workflow

The provider-neutral workflow is exposed through `Yoohw_Vietnam_Store_Tools_Electronic_Invoice::get_statuses()`, `get_order_data()`, `update_order_data()`, and `get_order_history()`. Connector add-ons may call `update_order_data()` after their own provider operation; the Free plugin does not make a provider request.

Successful workflow mutations continue to fire `yoohw_vietnam_store_tools_einvoice_workflow_updated` with the order, normalized next state, changes, and history entry. Existing workflow metadata represented by the class `META_*` constants, including its history shape, remains compatible in 1.1.5.

## Compatibility boundary

The hooks, method signatures, provider-array/callback shape, and persistence semantics listed above are stable for 1.1.5. Private methods, admin rendering details, internal helper classes, and undocumented implementation structure are not stable extension contracts.

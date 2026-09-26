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

## Payment reconciliation foundation (VST-50)

`Yoohw_Vietnam_Store_Tools_Payment_Reconciliation` owns append-only-by-API evidence history in WooCommerce order meta (`META_HISTORY`). `get_order_data($order)` derives `state` (`unreconciled`, `recorded`, `reconciled`), `trust` (`manual`, `external_verified`, or empty), `source_id`, and `entry_id` from active history. `get_history($order)` returns the stored records. An old order has `unreconciled` and empty history without any write. State and trust are separate: `reconciled + manual` is an operator comparison, not external bank verification. `record_manual_observation()`, `match_manual_observation()`, and `reverse_entry()` require per-order `edit_shop_order` capability and use the current server actor/time. Corrections pass `context['supersedes']` to a new manual observation. Reversals append a `reversal` entry pointing to the target. Read projection ignores superseded/reversed entries and matches whose observation is no longer active.

`record_verified_evidence($order, $source_id, $evidence, $context)` accepts only a callable registered under `yoohw_vietnam_store_tools_payment_evidence_sources[$source_id]`. The callback receives the order and connector evidence; it must validate authenticated provider evidence already held by the connector, including its association with this exact order, and return normalized `amount`, `currency`, `observed_at`, and a source-scoped `transaction_id`. Core validates full amount/currency equality against the order and rejects conflicting replays; an identical transaction replay is idempotent. A hashed source/transaction key in WooCommerce order meta enables a cross-order duplicate check through `wc_get_orders()` for both HPOS and legacy storage. The callback need not make an outbound request. Caller-supplied `trust`/`verified` flags have no authority. Installed PHP remains trusted code; this is a public semantic boundary, not isolation from arbitrary plugins. A successful new record fires `yoohw_vietnam_store_tools_payment_reconciliation_updated` with order, derived current data, and entry. Payment instructions, VietQR rendering, manual reference entry, and a manual match never produce `external_verified`. None of these methods changes WooCommerce order status, paid date, transaction ID, refund data, or calls `payment_complete()`. This foundation does not aggregate partial payments.

Evidence entries contain `id`, `kind` (`observation`, `match`, `verified`, or `reversal`), `trust`, `source_id`, `reference`, `transaction_id`, normalized decimal `amount`, three-letter `currency`, UTC `observed_at` and `recorded_at`, `actor_id`, `note`, and `supersedes`. Match entries also carry `evidence_id`. The caller may supply the observation's `reference` and `observed_at`; Core supplies record time and manual actor. The full-settlement rule compares normalized amount and currency to the WooCommerce order using WooCommerce decimal helpers. A different amount/currency can be stored as a manual observation but cannot be matched; there is no partial allocation or sum across observations. A provider transaction ID is scoped to its source. Core rejects reuse already indexed on another order; connectors must validate exact-order binding and serialize concurrent handling of the same provider transaction. A reversed transaction cannot be reactivated by replaying its original event. Reconciliation data and exception histories use the WooCommerce order CRUD/meta APIs for both HPOS and legacy storage.

## Shipment exceptions and identity (VST-50)

`Yoohw_Vietnam_Store_Tools_Fulfillment_Exceptions` extends the existing shipping projection with a current shipment ID and exception history in WooCommerce order meta. `get_current_shipment($order)` returns `id`, `closed`, and the existing shipping data; `get_exceptions($order)` returns the ledger. An existing shipment without an ID reads with a deterministic virtual `legacy:<order ID>` identity, without persisting anything. First applicable write assigns a real UUID. The exception types are `failed_handoff`, `delivery_failed`, `cancelled`, `returned_to_sender`, and `replaced`. `record_exception($order, ['type' => ..., 'expected_shipment_id' => ...], $context)` and `replace_shipment($order, $new_provider, $new_data, ['expected_shipment_id' => ...])` require a current-ID match; manual calls require `edit_shop_order`. Connector calls set `context['source_id']` and need a callable registered through `yoohw_vietnam_store_tools_shipment_exception_sources[$source_id]` that receives the order, operation payload, and context, validates its local provider evidence, and returns true. A new exception fires `yoohw_vietnam_store_tools_shipment_exception_recorded` with order and entry. Cancellation closes the identity; replacement links predecessor and successor IDs. Tracking-code correction via the existing manual flow does not create a replacement.

`Shipping::get_order_shipping_data()` and the three-argument `update_order_shipping_data()` remain the legacy projection contract. The latter does not advance an epoch and cannot guarantee stale-write rejection for previously installed PHP. Identity-aware connectors should use `update_order_shipping_data_for_shipment($order, $provider, $data, $expected_shipment_id)`, which rejects a stale or closed ID before writing. New tracking events may pass `expected_shipment_id` to `Shipment_Tracking::add_timeline_event()`; the event is bound to the current shipment through separate meta, leaving `META_TIMELINE` and the event return shape unchanged. Existing unbound timeline events affect the projection only while the legacy shipment remains current. Deleting predecessor events after replacement or cancellation cannot restore predecessor status to the new current shipment. Existing provider, shipping, tracking, and order-list fulfillment metadata and hooks retain their prior meanings. There is no migration, carrier API, RMA workflow, or activation/deactivation write.

Exception entries contain `id`, `shipment_id`, `type`, a provider/tracking snapshot, UTC `occurred_at`, `actor_id`, `source_id`, `note`, `parent_shipment_id`, and `replacement_shipment_id`. Failed handoff, delivery failed, and returned to sender add operational evidence for a particular shipment without rewriting the existing customer timeline. Existing `delivery_failed` and `returned_to_sender` timeline statuses keep their meanings. New identity-aware callers must pass their expected shipment ID; the legacy timeline API without one operates on the current shipment and does not claim stale-caller protection.

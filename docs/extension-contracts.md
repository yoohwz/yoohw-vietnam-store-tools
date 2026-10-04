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

## Electronic invoice handoff v2 (VST-58)

The eight existing fields in `get_order_data($order)` remain the current compatibility projection. The method also returns `provider_document_id`, `handoff_reference`, `provider_status_text`, `handed_off_at`, `confirmed_at`, `workflow_revision` (integer, default 0), and `current_document_id` (empty by default). Existing `META_*` keys, seven status IDs, `update_order_data($order, $data, $context = [])`, `get_order_history($order)`, and the `yoohw_vietnam_store_tools_einvoice_workflow_updated` hook and argument order remain compatible. A legacy caller may still submit any status, including `adjusted` or `replaced`, with partial fields and the prior per-field validation. `context['source']` is a sanitized attribution string, not provider verification. A status-only legacy correction never invents document lineage. Once v2 state exists, such a call advances the workflow revision under the same per-order lock so stale v2 edits fail.

`get_order_documents($order)` returns immutable oldest-first document snapshots. `record_order_document($order, $document, $context)` appends an `original`, `adjustment`, or `replacement` snapshot; `legacy` explicitly captures an existing projection once with unknown provenance. The document argument includes `kind`, optional `prior_document_id`, the provider-neutral projection fields, and optional handoff references. `context['expected_revision']` is mandatory. Adjustment/replacement also require `context['expected_current_document_id']` and a matching prior document ID. New documents pass stricter request, invoice identity, date, URL, and attachment checks. Successful records fire `yoohw_vietnam_store_tools_einvoice_document_recorded` with the order and new snapshot in addition to the existing workflow hook. The document ledger and lock storage are private implementation details; integrations must use these methods, not write the new meta keys.

New admin and bulk v2 operations use `context['v2_strict'] = true` with `expected_revision`; legacy callers without it keep their prior validation behavior. The strict path checks request completeness for new status advances and documents and checks new attachment ownership, type and readable file. Existing incomplete records remain readable without migration or write-on-read. Historical document snapshots preserve attachment IDs, filenames and captured URL text even if media later disappears; active downloads require a live readable file. Updating/unlinking the current PDF/XML fields does not delete historical media. The current projection remains what the order list, legacy CSV and customer email read; new records never send email automatically.

The selected-order handoff CSV has fixed machine headers and numeric Woo order ID as identity. The existing translated VAT CSV stays unchanged. CSV import is deferred. Core never calls an invoice provider, stores provider credentials, or treats provider references as issuance proof. A validated lookup URL is a link to the provider page, not a verification signal.

## Compatibility boundary

The hooks, method signatures, provider-array/callback shape, and persistence semantics listed above are stable for 1.1.5. Private methods, admin rendering details, internal helper classes, and undocumented implementation structure are not stable extension contracts.

## Payment reconciliation foundation (VST-50)

`Yoohw_Vietnam_Store_Tools_Payment_Reconciliation` owns append-only-by-API evidence history in WooCommerce order meta (`META_HISTORY`). `get_order_data($order)` derives `state` (`unreconciled`, `recorded`, `reconciled`), `trust` (`manual`, `external_verified`, or empty), `source_id`, and `entry_id` from active history. `get_history($order)` returns the stored records. An old order has `unreconciled` and empty history without any write. State and trust are separate: `reconciled + manual` is an operator comparison, not external bank verification. `record_manual_observation()`, `match_manual_observation()`, and `reverse_entry()` require per-order `edit_shop_order` capability and use the current server actor/time. Corrections pass `context['supersedes']` to a new manual observation. Reversals append a `reversal` entry pointing to the target. Read projection ignores superseded/reversed entries and matches whose observation is no longer active.

Payment reconciliation is opt-in through `yoohw_vietnam_store_tools_payment_reconciliation_enabled` (`yes` enables it; absent or any other value disables it). When disabled, `record_manual_observation()`, `match_manual_observation()`, `reverse_entry()` and `record_verified_evidence()` return `yoohw_vietnam_store_tools_payment_feature_disabled` without writing history or transaction-owner metadata, including replay requests. Providers are not invoked while disabled. Read APIs remain available and existing history stays read-only in the admin. Re-enabling requires no migration.

`record_verified_evidence($order, $source_id, $evidence, $context)` accepts only a callable registered under `yoohw_vietnam_store_tools_payment_evidence_sources[$source_id]`. The callback receives the order and connector evidence; it must validate authenticated provider evidence already held by the connector, including its association with this exact order, and return normalized `amount`, `currency`, `observed_at`, and a source-scoped `transaction_id`. Core validates full amount/currency equality against the order and rejects conflicting replays; an identical transaction replay is idempotent. A hashed source/transaction key in WooCommerce order meta enables a cross-order duplicate check through `wc_get_orders()` for both HPOS and legacy storage, including orders retained in Trash. The callback need not make an outbound request. Caller-supplied `trust`/`verified` flags have no authority. Installed PHP remains trusted code; this is a public semantic boundary, not isolation from arbitrary plugins. A successful new record fires `yoohw_vietnam_store_tools_payment_reconciliation_updated` with order, derived current data, and entry. Payment instructions, VietQR rendering, manual reference entry, and a manual match never produce `external_verified`. None of these methods changes WooCommerce order status, paid date, transaction ID, refund data, or calls `payment_complete()`. This foundation does not aggregate partial payments.

Evidence entries contain `id`, `kind` (`observation`, `match`, `verified`, or `reversal`), `trust`, `source_id`, `reference`, `transaction_id`, normalized decimal `amount`, three-letter `currency`, UTC `observed_at` and `recorded_at`, `actor_id`, `note`, and `supersedes`. Match entries also carry `evidence_id`. The caller may supply the observation's `reference` and `observed_at`; Core supplies record time and manual actor. The full-settlement rule compares normalized amount and currency to the WooCommerce order using WooCommerce decimal helpers. A different amount/currency can be stored as a manual observation but cannot be matched; there is no partial allocation or sum across observations. A provider transaction ID is scoped to its source. Core rejects reuse already indexed on another order; connectors must validate exact-order binding and serialize concurrent handling of the same provider transaction. A reversed transaction cannot be reactivated by replaying its original event. Reconciliation data uses the WooCommerce order CRUD/meta APIs for both HPOS and legacy storage.

## Shipment identity and tracking (VST-70)

`Yoohw_Vietnam_Store_Tools_Shipment_Identity` owns the current shipment ID, closed lifecycle marker, and tracking-event bindings in WooCommerce order meta. `get_current_shipment($order)` returns `id`, `closed`, and the existing shipping data. An existing shipment without an ID reads with a virtual `legacy:<order ID>` identity without a write. First identity-aware write materializes a UUID. `assert_current($order, $expected_id)` refreshes persisted meta and rejects a stale or closed shipment. `close_current($order, $expected_id)` closes a shipment after carrier cancellation without an exception ledger entry. `replace_shipment($order, $provider, $data, ['expected_shipment_id' => ...])` starts a new identity and returns its ID and predecessor ID; manual replacement requires `edit_shop_order`. Existing identity and event-binding meta keys remain unchanged. A separate closed-ID marker records cancellation. The old exception-history meta is preserved without migration and is no longer read or written by the feature.

`Shipping::get_order_shipping_data()` and the three-argument `update_order_shipping_data()` remain the legacy projection contract. The latter does not advance an epoch and cannot guarantee stale-write rejection for previously installed PHP. Identity-aware connectors should use `update_order_shipping_data_for_shipment($order, $provider, $data, $expected_shipment_id)`, which refreshes persisted order meta and rejects a stale or closed ID before writing. Timeline mutations also refresh persisted meta before checking identity; callers should save unrelated pending order edits first. New tracking events may pass `expected_shipment_id` to `Shipment_Tracking::add_timeline_event()`; the event is bound to the current shipment through separate meta, leaving `META_TIMELINE` and the event return shape unchanged. Existing unbound timeline events affect the projection only while the legacy shipment remains current. Deleting predecessor events after replacement or cancellation cannot restore predecessor status to the new current shipment. The Create shipment admin action after cancellation starts a new identity. Existing provider, shipping, tracking, and order-list fulfillment metadata and hooks retain their prior meanings. There is no migration, carrier API, RMA workflow, or activation/deactivation write.

The tracking timeline remains the customer-facing record for `delivery_failed` and `returned_to_sender`. New identity-aware callers must pass their expected shipment ID; the legacy timeline API without one operates on the current shipment and does not claim stale-caller protection.

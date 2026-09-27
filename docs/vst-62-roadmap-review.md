# VST-62 combined roadmap review

This dossier reviews the six merged phases on `main@6069b54a418af614c0e38941856b45d14849ff3f` (PRs #51, #53, #55, #57, #59, #61). It records shipped behavior and the limits of the combined validation. It does not select a version or prepare a release. The binding public extension surface is [extension-contracts.md](extension-contracts.md); this dossier is a product review, not an additional API promise.

## What actually shipped

### Phase 1 — Domain contract foundation (#50 / PR #51)

- Payment reconciliation gained order-meta evidence history and a derived state (`unreconciled`, `recorded`, `reconciled`) independent of trust (`manual`, `external_verified`). Manual observations/matches and source-validated external evidence have separate entry points. Only a registered source callback can create externally verified evidence; Core checks full amount/currency and source-scoped transaction reuse, including retained trashed orders. Instructions and a manual match are never bank verification.
- Fulfillment gained a current shipment identity and exception ledger for failed handoff/delivery, cancellation, return to sender, and replacement. Existing shipments read with a virtual `legacy:<order ID>` identity until an applicable write. Identity-aware writes reject a stale/closed shipment; replacement links old and new identities.
- Shipping provider arrays/callbacks, the three-argument shipping update projection, tracking timeline shape, and existing order metadata and hooks remain supported. New identity-aware shipping methods, exception source filter, payment evidence source filter, and update hooks are additive. Legacy callers cannot retroactively receive stale-write protection.
- No provider integration, broad merchant UI, migration, custom table, partial-payment aggregation, or carrier automation was added.

### Phase 2 — Payment Reconciliation v1 (#52 / PR #53)

- The order admin panel supports plain BACS orders and orders with existing history even when VietQR is off. Operators can record an observation/reference, correct it through a superseding entry, match an exact full-order amount, and reverse a match. The order Information column shows the derived state; it is not a separately mutable index. Verified external entries are read only in the manual UI.
- Entries retain actor/time, amount/currency, reference, note, trust/source and reversal relationship. Stale action IDs and invalid observations are rejected. A manual match means operator comparison, not external verification.
- Observation/match/reversal never calls `payment_complete()` and never changes WooCommerce status, paid date, transaction ID, refund or stock. A future connector must validate provider evidence and exact-order binding before calling the registered-source API.

### Phase 3 — Store Health and Migration Assistant v2 (#54 / PR #55)

- Store Health shows configuration readiness for address/store, BACS/VietQR, invoice and tracking. Opening the page does not run a legacy-corpus scan. Explicit scan/dry-run is read only and reports exact-safe/review counts with bounded examples.
- The assistant exposes the existing DevVN/current address and legacy shipment engine. Explicitly confirmed migration uses bounded 200-row batches, separate order/customer/shipment progress, backup of original fields and a final rescan. Only exact-safe rows are written; ambiguous rows remain for Human review. WooCommerce Status Tools callbacks remain available.
- It does not schedule scanning, broaden classification rules, rewrite review rows, or resolve ambiguous legacy addresses. An explicit full-corpus scan can be slow on a large store.

### Phase 4 — Fulfillment Exceptions and Returns Lite (#56 / PR #57)

- Order admin can record shipment exceptions against the exact current identity. Timeline events remain customer-facing tracking evidence; exception history does not silently rewrite them. Cancellation closes an identity; replacement starts a linked successor. Tracking-code correction is still a correction, not a replacement.
- Returns Lite adds a manual order-item event ledger with quantity allocation and revision checks. Operators can create, correct, receive, close and cancel records. Item snapshots, reason, actor/time, optional refund reference and shipment exception reference are retained. Cancellation releases allocation; stale writes and over-allocation are rejected.
- Refund/shipment references are informational. Returns Lite never issues a refund, adjusts stock, changes WooCommerce order status, sends a customer email, or creates a carrier/RMA workflow. The optional order-list indicator was omitted.

### Phase 5 — Electronic Invoice Handoff v2 (#58 / PR #59)

- The existing eight-field current projection remains the compatibility view for order lists, the legacy VAT CSV and customer email. New provider-neutral handoff fields and immutable oldest-first document snapshots distinguish original, adjustment, replacement and captured legacy records. Legacy provenance is explicitly unknown.
- New document/admin/bulk operations use expected workflow revision and current document ID under an order lock. Strict v2 validation checks request completeness, identity, dates and attachments. The legacy `update_order_data()` API retains partial/status-only behavior, including adjusted/replaced, and advances the revision after v2 opt-in without inventing lineage. Pending caller metadata writes/deletions are preserved.
- A separate selected-order handoff CSV has fixed machine headers and Woo order ID. Bulk mark-ready works on complete eligible orders. Current PDF/XML/customer email behavior remains; snapshots preserve historical attachment references but active downloads need a live readable file. Recording a document does not email the customer.
- CSV import, provider issuance/sync, credentials, legal verification and automatic provider status changes remain outside Core.

### Phase 6 — Native Payment Link (#60 / PR #61)

- A capability-guarded order-admin metabox renders WooCommerce's current native order-pay URL only for a persisted keyed order that `needs_payment()`. WooCommerce retains recipient/session, order-key, stock, gateway and payment checks. The URL is rendered on demand, not stored in VST metadata, logs, lists or exports.
- BACS instructions can be copied independently for payable BACS and WooCommerce's BACS instruction status, including accounts without a usable QR BIN or with VietQR disabled. BACS text and existing VietQR output share internal account/reference normalization. VND can include the QR amount; non-VND QR omits it. Copy/link use is never reconciliation evidence.
- No custom checkout route/token, social API, source/provenance metadata, payment tracking or provider integration was added. Source attribution was explicitly deferred.

## Cross-phase product impact

| Area | Shipped impact and boundary |
| --- | --- |
| Customer | Existing Vietnam address/phone, Classic and Blocks checkout, shipping fee/ward zones, tracking, VAT request, invoice email/data and BACS/VietQR surfaces remain. Phase 6 gives a merchant a native WooCommerce order-pay URL to share; no new VST customer route exists. |
| Merchant | Order admin now includes payment evidence, fulfillment exceptions/returns, invoice documents/handoff, payment-link/BACS copy and Store Health/migration. Manual operations work without connectors. |
| Persisted order data | Additive payment evidence, shipment identity/exception history, tracking-event identity binding, Returns Lite ledger, invoice handoff fields/revision/document ledger. WooCommerce order CRUD/meta is used for legacy and HPOS. The payment URL/key is not duplicated in VST metadata. |
| Public extension contracts | Shipping provider registry and callbacks, shipping projection and identity-aware method, timeline methods/events, payment evidence and shipment exception source registries, electronic-invoice legacy and v2 APIs/hooks. See `docs/extension-contracts.md` for exact signatures and meanings. |
| Internal only | Store Health UI helpers, BACS/VietQR account preparation, return ledger/locks, invoice ledger/locks and admin render details are implementation details. Public PHP visibility alone does not turn them into stable extension APIs. |
| Concurrency | Shipment expected ID, Returns Lite ledger/return revisions and lock, invoice workflow revision/current-document ID and lock reject stale writes. Payment admin actions validate active entry IDs. Legacy shipping projection and invoice APIs preserve their documented compatibility limits. |
| Security/privacy | Admin actions require capabilities and nonces; order-level mutations require `edit_shop_order`. Tracking lookup retains rate/privacy checks. Payment URL remains a sensitive on-demand admin value. Share text excludes customer PII. Attachments require validation/readability for active download. |
| Localization | New strings from all phases were added to POT and both Vietnamese catalogs/compiled files; deterministic localization and translation runtime gates remain required. |

## Explicitly deferred and known limits

- Bank/payment webhooks, automatic matching or paid transitions; carrier APIs/live rates/labels/webhooks; invoice provider issuance/import/sync; CSV import; marketplace/POS/social automation; provenance/source attribution; full RMA/refund/stock automation; scheduled migration scans; new release/version work.
- Payment reconciliation is full-settlement only. A manual match is not external proof. Connector code remains responsible for authentic provider evidence and serialization of the same provider transaction.
- Legacy shipping calls cannot supply a shipment epoch. A legacy invoice status-only correction does not create a document lineage. Historical invoice attachments can become unavailable if media is removed.
- Store Health's explicit scan may be expensive on a large corpus. Review-classified migration rows need Human action. Ward-only shipping zones stop matching while VST is inactive; mixed zones fall back to their native WooCommerce region behavior.

## Validation map for the candidate

The exact candidate SHA, deep CI run and any remaining gaps must be recorded on Issue #62 before Technical Review. The new CI smoke runs the existing Returns Lite and invoice tests, the combined order workflow, Store Health scan/migration, a cross-process deactivation/reactivation check, and HTTP Classic/Blocks/Store API/native order-pay/My Account paths in both legacy and HPOS storage. The combined order checks that payment, shipment, return and invoice histories coexist and do not change WooCommerce payment/order/refund state. The lifecycle check compares stored metadata before, during and after plugin deactivation and verifies reads do not rewrite it. HTTP checkout verifies province, ward, phone, VAT request, BACS status and customer BACS output. The native order-pay test uses a disposable customer and WooCommerce's own form, nonce and BACS gateway; the same customer's order view and address editor exercise tracking and province/ward output.

The repository contract suites cover Classic/Blocks address and phone integration, VAT Store API fields, shipping zones, payment/QR rules, admin capability/nonce paths, tracking privacy, upload/attachment checks, public hooks and legacy methods. Exact-SHA CI separately checks governance, classification, repository contracts, PHP 7.4/8.2/8.4 syntax, localization quality, WordPress 6.3/6.7/latest translation runtime, strict Plugin Check and `VST Required Gate`.

Runtime tests exercise real HTTP endpoints but do not run a JavaScript browser or every checkout/payment state on every WordPress/WooCommerce version. The merged child PRs contain additional HTTP/browser evidence for selected surfaces; this dossier distinguishes that earlier evidence from the exact candidate CI. Exact-candidate HTTP coverage does not include public tracking lookup, invoice email delivery, or live provider callbacks; their repository and runtime contract checks remain separate. There are no provider credentials in Core, so external bank, carrier, invoice and online gateway callbacks are intentionally unavailable. A Human may want to revisit the amount of text shown in the payment-link metabox, the manual workflow density on the order screen, full-settlement-only reconciliation, and migration scan cost before any future release decision.

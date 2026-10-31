# Free-VST Project Instructions

These instructions apply to the entire `yoohw-vietnam-store-tools` repository. Read [the canonical workflow](docs/workflow.md) before governed work. GitHub Issue `#N` remains task `VST-N`; recover its current PR, base/head, CI, reviews and Human decisions first. The PR is the primary execution record.

## Authority and roles

- This repository is the official source of truth for Vietnam Store Toolkit for WooCommerce. Do not implement plugin changes in the Vietnam Store website project.
- Human owns product acceptance, unresolved product/scope/safety decisions, merge approval, versions/releases, production actions and WordPress.org publication.
- Codex owns task framing, risk, technical planning/architecture, implementation, validation, proportional review, corrections, acceptance evidence, coordination and PR upkeep. One lead remains accountable for delegated work.
- Subagents perform bounded work with appropriate independence and evidence; they add no approval stage. For future work after VST-102 merges, ChatGPT is not a required participant or approval handoff.
- GitHub is the durable evidence record. Tool access does not change authority. These roles take precedence over generic global role descriptions.
- The public site is `https://vietnamstore.org/`; the plugin listing is `https://wordpress.org/plugins/yoohw-vietnam-store-tools/`. Publication requires explicit Human release scope.

## Admission and recovery

- Every workflow-governance semantic amendment follows the workflow accepted on its admitted base through Human merge. Unmerged text cannot authorize, waive, downgrade, or redefine its own gates.
- VST-102 stays Controlled under base `aa1b95855c5c8dc939fb9894b6232a7eae2d9965`, including fresh exact-SHA independent Technical Review, separate external ChatGPT `Acceptance Review VST-102`, then explicit Human `Merge VST-102`. Its candidate rules cannot waive those requirements.
- For future ordinary tasks, Codex handles planning, review and corrections internally through the Human boundary. Recover both PR conversation comments and review submissions, by time and exact-head applicability; preserve Human decisions and unresolved findings. Historical handoffs do not hide newer evidence.
- Resolve short commands from the conversation/GitHub when unambiguous: `Codex - Continue VST-N`, `Codex - Review VST-N`, `Human - Decide VST-N`, `Human - Merge PR #N`. Preserve legacy aliases and admitted-base exceptions as described in the workflow.

## Scope, risk, and review

- Use one task = one draft PR by default; split only at a real semantic/review boundary. Use the existing Issue/PR for briefs/plans; add no registry or planning document that mirrors history.
- Use Fast or Controlled plus a proportional Structural, Functional, Safety-critical or Release review profile. Controlled includes checkout/Store API, payments/VietQR/PayPal, shipping/tracking, invoices, order/customer data/HPOS, persistence/migrations, security/uploads, public contracts/compatibility, release semantics and cross-feature architecture. Semantic governance amendments remain Controlled.
- Record a Controlled plan before runtime implementation, with invariants, scenarios where ambiguous, validation, complexity budget and stop-loss. Revise unexpected persistence/locking/recovery/destructive architecture with proportional independent critique. Ask Human only for reserved scope/product/safety decisions or genuinely blocking missing information.
- Fresh independent review is mandatory for materially safety-critical Controlled behavior, including payment/data integrity, persistence/schema, authorization, destructive work, concurrency/replay and recovery. Use a fresh context, read-only separate checkout and exact head; never have an implementer independently approve their own work. Missing required evidence means unfinished work.
- Candidate movement invalidates prior review/acceptance applicability and handoffs. Review corrections as a delta with affected invariants and a new exact-head result; rerun applicable checks. Advisory-only findings do not create another correction gate.
- Delegate bounded tasks proportionally. Sol is the default; choose supported effort by actual need. Bounded Astra escalation needs a concrete technical reason; the workflow authorizes subagent model/effort choices without another approval. Never claim an unavailable runtime switch. Follow the canonical workflow for details.

## Git and worktree safety

- Inspect `git status -sb`, branch, remote and relevant diff before staging/committing. Preserve unrelated work; stage explicit files. Use `git add -A` only after Human confirms the whole tree belongs to one change.
- Use separate worktrees/clean checkouts for concurrent tasks. Start version work from latest `origin/main` after a fast-forward-only local `main` update. Use `release/<version>` for official integration, `agent/<version>-<scope>` for version-bound tasks or `agent/<scope>` for independent work.
- Never push directly to main, force push, rewrite shared history, destructively reset or weaken protection. Push the task branch and open/update a draft PR; verify a replacement before closing the original.
- Delete only verified merged same-repository disposable `agent/*` heads unused by another open PR. Retain main and every `release/*`; keep `delete_branch_on_merge` disabled. Follow the existing selective/stale cleanup policy and evidence requirements.

## Validation, handoffs, and release

- Before commit/push, lint changed PHP with a compatible runtime, run email placeholder tests for email changes, validate changed JSON/block metadata, check version consistency, run `git diff --check` and inspect the complete staged diff for secrets, local paths, generated junk and unrelated files. Add relevant WordPress/WooCommerce runtime evidence for affected behavior.
- Preserve staged CI, `VST Required Gate`, PHP matrix, localization/translation-runtime, strict Plugin Check, product contracts and WordPress.org publication validation. Mark ready-for-review and wait for exact-head applicable final CI before merge readiness. Record unavailable checks as `NOT RUN — reason`.
- For future tasks, use only `HUMAN_DECISION_REQUIRED` or `READY_FOR_HUMAN_MERGE` for Human handoffs. End a final response with one copy-ready `Next: <Target> - <short command>` when a clear next action exists, matching the durable handoff. Internal work does not require Human relays. VST-102 retains its admitted-base handoff protocol through merge.
- Link the same canonical Issue with `Closes #N`. After an explicitly authorized merge succeeds, verify the Issue is closed as completed, closing it explicitly if needed. Never close it before merge or report `FINALIZED` while it is open.
- Do not change Stable tag, finalize changelog date, tag, deploy or publish merely because a PR merged. Release preparation updates all applicable version sources together; Stable tag stays at the last public version until explicit Human release scope.

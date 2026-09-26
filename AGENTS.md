# Free-VST Project Instructions

These instructions apply to the entire `yoohw-vietnam-store-tools` repository. Read [the canonical workflow](docs/workflow.md) before running a governed task. GitHub Issue `#N` is task `VST-N`; start from the Issue and recover its current PR, head, base, CI, and review evidence.

## Authority and roles

- This repository is the official source of truth for Vietnam Store Toolkit for WooCommerce. Do not implement plugin changes in the Vietnam Store website project.
- Human owns product decisions, unresolved trade-offs, Merge, version and release decisions, production actions, and WordPress.org publication.
- Codex alone implements repository changes, validates them, maintains the PR, and orchestrates independent Technical Review.
- ChatGPT frames tasks, reviews genuinely unresolved plans, and performs external Acceptance Review. ChatGPT must not mutate implementation branches, files, commits, pushes, or PRs, or review its own implementation.
- GitHub is the durable task and execution record. A tool's write capability does not change role ownership.
- The public site is `https://vietnamstore.org/`; the plugin listing is `https://wordpress.org/plugins/yoohw-vietnam-store-tools/`. Publication requires an explicit Human release instruction.

## Git and worktree safety

- Inspect `git status -sb`, branch, remote, and relevant diff before staging or committing. Preserve unrelated modified or untracked work.
- Use a separate worktree or clean checkout for concurrent tasks. Start version work from the latest `origin/main` after a fast-forward-only local `main` update. Use `release/<version>` for official integration, `agent/<version>-<scope>` for version-bound tasks, or `agent/<scope>` for independent governance/tooling work.
- Never push directly to `main`, force push, rewrite shared history, perform destructive resets, or weaken branch protection. Stage explicit files; use `git add -A` only after Human confirmation that the whole working tree belongs to one change.
- Push a task branch and open/update a draft PR into the intended base. Do not replace or close an existing PR until the replacement is pushed and verified complete.
- Delete only verified merged, same-repository disposable `agent/*` heads when no other open PR uses them. Retain `main` and every `release/*` branch; keep repository-wide `delete_branch_on_merge` disabled. Use the selective cleanup workflow and its evidence requirements for stale branches.

## Commands, risk, and review

- Canonical Human commands: `Create ...`, `Run VST-N`, `Continue VST-N`, `Plan Review VST-N`, `Review VST-N` (manual Technical Review fallback), `Acceptance Review VST-N`, `Merge VST-N`, and eligible `Finalize VST-N`. Short legacy aliases work only when one task is unambiguous.
- Classify each task as Fast or Controlled. Controlled includes checkout/Store API, payment/VietQR/PayPal, shipping/tracking, e-invoice, order/customer data and HPOS, persistence/schema/migration, security/capabilities/uploads, public contracts/compatibility, release semantics, and cross-feature architecture. A Controlled plan review is needed only for a genuinely unresolved boundary; governance amendments always require Controlled review.
- Use phase-based compute and the fresh independent Technical Reviewer described in [the workflow](docs/workflow.md). A candidate SHA change invalidates prior Technical Review and Acceptance.
- Navigation status text guides the next command; it is not a state database. The Issue boundary, Git objects, PR/base/head, CI, exact-SHA review evidence, and explicit Human commands determine authority.

## Validation and release boundary

- Before commit or push, lint each changed PHP file with a compatible runtime; run email placeholder tests for email behavior changes; validate changed JSON/block metadata; check version consistency; run `git diff --check`; inspect the complete staged diff for secrets, local paths, generated junk, and unrelated files. Run relevant WordPress/WooCommerce checks for admin, checkout, order, email, upload, or HPOS changes. Record unavailable required checks accurately in the PR.
- Preserve VST staged CI, `VST Required Gate`, PHP matrix, localization and translation-runtime checks, strict Plugin Check, product contract suites, and WordPress.org publication validation.
- Do not change Stable tag, finalize changelog date, tag, deploy, or publish merely because a PR is merged. Release preparation must update all applicable version sources together while Stable tag stays at the last public version until an explicit release.

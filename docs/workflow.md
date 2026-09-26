# VST AI-assisted delivery workflow

This is the canonical workflow for governed changes after VST-47 is Human-merged. VST-47 itself remains under the workflow admitted at base `e54450fd545976f87458affd251551c454cf3aea` through its Human merge. Every future workflow-governance semantic amendment also remains governed by the workflow accepted on its admitted base through Human merge. Proposed or unmerged workflow text cannot authorize, waive, downgrade, or redefine its own role separation, compute/review, Acceptance, Merge, or release requirements. Existing merged tasks and comments remain historical records; do not rewrite or revalidate them under this document.

## Authority and task identity

GitHub Issue `#N` is the canonical task `VST-N`. Every open governed implementation PR links one readable, open Issue; a closed historical Issue cannot authorize fresh implementation. The Issue states the goal, problem, scope, invariants, acceptance criteria, validation, and Fast or Controlled lane. GitHub holds the Issue boundary, branch, PR, exact candidate SHA, checks, reviews, decisions, and merge history. Do not create a separate task registry, state JSON, digest chain, controller, approval parser, or governance database.

For a known VST repository, `Run VST-N` and `Continue VST-N` open Issue `#N` first. Recover its linked PR, base/head, commit identity, current CI, review evidence, and Human decisions from GitHub. Search repository text by task code only if the Issue is missing/unreadable, repository identity is uncertain, or GitHub facts materially conflict. If one active task is unambiguous, legacy `Chạy`, `Tiếp tục`, and `Review` can route to it.

The Human owns product direction, unresolved trade-offs, Merge, version/release decisions, production actions, and WordPress.org publication. ChatGPT owns task framing, genuine Plan Review decisions, fresh standalone manual fallback Technical Review when needed, and separate external Acceptance Review; it never implements repository source. ChatGPT may post the durable Issue/PR review comments assigned to those roles, but cannot edit implementation PR source/body/state as the implementer. Codex owns discovery, implementation, validation, corrections, PR upkeep, and Technical Review orchestration. GitHub is the durable evidence record. Tool access does not grant a role another role's authority.

## Commands and navigation

| Command | Meaning |
| --- | --- |
| `Create ...` | Frame a new Issue and its boundary. |
| `Run VST-N` | Start discovery and implementation within the current gate. |
| `Continue VST-N` | Recover the latest GitHub facts and take the next authorized step. |
| `Plan Review VST-N` | ChatGPT resolves a persisted material boundary; only when needed. |
| `Review VST-N` | ChatGPT performs a fresh standalone independent Technical Review fallback when native delegation is unavailable; later Acceptance Review is a separate step. |
| `Acceptance Review VST-N` | ChatGPT checks the exact candidate against the Issue, CI, and Technical Review. |
| `Merge VST-N` | Human alone authorizes merge of the unchanged accepted candidate. |
| `Finalize VST-N` | Convenience shortcut only when all unchanged-candidate prerequisites already hold; never combines Acceptance and Merge for workflow-governance semantic amendments. |

Every durable handoff ends with one exact next command and this Human-facing footer:

```text
STATUS: <navigation label>
Task: VST-N
Next: <one exact command, or None>
```

Navigation labels are `READY_TO_RUN`, `IN_PROGRESS`, `PLAN_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_BLOCKED`, `ACCEPTANCE_REVIEW_REQUIRED`, `READY_TO_MERGE`, `HUMAN_DECISION_REQUIRED`, and `FINALIZED`. These strings are hints, not machine authority. Comment ordering and marker text do not reconstruct lifecycle state. Authority comes from the current Issue boundary, PR/base/head, Git object identity, CI/check results, exact-SHA review and acceptance evidence, and explicit Human commands.

## Risk and compute

Use Fast for bounded docs, translations, isolated UI/CSS/JS, tests without runtime changes, CI/tooling, understood small bugs, and local refactors that preserve public and persistence contracts. Use Controlled for Classic Checkout or Blocks, Store API, VietQR/payment/PayPal, shipping/tracking/carriers, VAT/e-invoice, order/customer data or HPOS, migrations/persistence/schema, REST/AJAX/capability/nonce/upload/security, public hooks/APIs/stored metadata/compatibility, release semantics, and cross-feature architecture. Promote a Fast task if discovery reaches a Controlled boundary. Workflow-governance semantic amendments are always Controlled.

Compute follows phase, not mutable task metadata:

| Phase | Default |
| --- | --- |
| Root orchestration; Fast, Controlled, or correction implementation | GPT-6 Sol / MEDIUM |
| Separate Controlled discovery/architecture with genuine Plan Review | GPT-6 Sol / HIGH |
| Each fresh independent Technical Review or re-review | GPT-6 Sol / HIGH |

Use GPT-6 Sol / XHIGH only for a specific unresolved architecture/security reason after reducing irrelevant context. GPT-6 Astra requires exceptional manual escalation and is never the governed default. Do not switch models for individual Git, lint, or test substeps.

## Discovery and Plan Review

Codex reads the Issue, GitHub state, repository/worktree state, and relevant contracts before writing. A fully bounded Controlled task proceeds to implementation under Controlled review rules. If a material product, architecture, data/persistence, permission/security, or compatibility boundary remains unresolved, Codex posts a durable plan on the Issue and stops at `PLAN_REVIEW_REQUIRED`. The plan names base/branch state, affected contracts/files, approach, validation, risks, and the actual decision. ChatGPT posts its Plan Review result to the same Issue. Neither PR prose nor an assumed default approves a plan.

## Candidate, CI, and Technical Review

Codex implements only within the Issue and approved boundary, maintains one task branch/PR, and validates the change. A draft PR uses quick CI. The `ready_for_review` transition activates risk-matched deep CI; PR body or status prose never controls CI depth. Preserve `Workflow governance`, `Repository contracts`, `VST Required Gate`, PHP 7.4/8.2/8.4 contexts, localization quality, WordPress translation-runtime checks, strict Plugin Check, product/architecture/address/email/PayPal contract suites, and WordPress.org release checks. Green CI proves only the checks that ran; runtime-sensitive changes need relevant WordPress/WooCommerce evidence.

For each exact Controlled candidate, Codex delegates one fresh independent Technical Reviewer at GPT-6 Sol / HIGH when native per-role delegation is available. The reviewer receives the approved Issue/boundary, admitted base, exact SHA and total diff, relevant contracts, and validation evidence. It has fresh context and read-only source access; it must not rely on implementer scratch reasoning or self-review conclusions and must not recursively delegate. Its PASS or findings are recorded durably in GitHub review/comment evidence with the exact reviewed SHA. Codex must not present its own assessment as independent review. If native fresh delegation is unavailable, preserve the candidate and stop with `TECHNICAL_REVIEW_REQUIRED`, `Next: Review VST-N`; ChatGPT then performs a fresh standalone independent Technical Review and records exact-SHA evidence before its separate Acceptance Review. Do not weaken independence to avoid the fallback. Fast candidates still receive risk-appropriate validation and Acceptance Review; a fresh delegated or manual independent Technical Reviewer is mandatory for Controlled candidates.

Any candidate movement invalidates prior Technical Review and Acceptance. Return corrections to the same implementation role at MEDIUM, validate, and use a new fresh reviewer at HIGH for the new exact SHA. Continue while each cycle makes material progress on current in-scope findings. Use `HUMAN_DECISION_REQUIRED` for no material progress, a repeated/stagnant blocker, oscillation, unsafe continuation, or scope/architecture expansion requiring a Human decision. There is no numeric correction-round cap.

## Acceptance, Merge, and Release

ChatGPT performs external `Acceptance Review VST-N` against the Issue boundary, exact head and total diff, current required CI, runtime evidence where relevant, release boundary, and exact-SHA independent Technical Review for Controlled work. A PASS is durable exact-SHA GitHub evidence and yields `READY_TO_MERGE`; findings return to Codex for correction or to Human for a reserved decision. Acceptance is separate from Technical Review and does not authorize Merge or release.

Human `Merge VST-N` applies only to the unchanged accepted candidate. Recheck head, base, CI, Technical Review, Acceptance, and branch protection immediately before merge. A changed candidate requires fresh applicable review. `Finalize VST-N` cannot collapse Acceptance and Merge for workflow-governance semantic amendments. Merge never implies tag, GitHub Release, deployment, production mutation, Stable tag change, or WordPress.org publication. Those require explicit Human release scope and existing publication validation.

For version work, finish and Human-merge the current release PR, fast-forward local `main` from `origin/main`, then create `release/<next-version>` or an isolated `agent/<version>-<scope>` branch. If work depends on an unmerged release branch, branch from and target that release branch; rebase or retarget only after the prerequisite merges, without rewriting shared history. Keep the WordPress.org Stable tag at the last publicly released version while the next version is marked In development. Release preparation updates the plugin header and fallback version in `yoohw-vietnam-store-tools.php`, block metadata and generated asset metadata, `changelog.txt`, `readme.txt`, and translation catalogs/compiled files when strings change. Finalize the changelog date, change Stable tag, create tags or releases, deploy, or publish to WordPress.org only with explicit Human release scope. Preserve the existing WordPress.org publication validation.

After merge, synchronize local `main` before next-version work. Normal `agent/*` heads are disposable task branches, but automatic cleanup applies only to merged, same-repository `agent/*` heads whose current SHA still matches the merged PR head and which no other open PR uses. Skip protected branches and never reuse completed task branch names. All `release/*` branches are retained because their heads must remain available and match release tags for WordPress.org publication; `main` is never deleted or rewritten. Keep the repository-wide `delete_branch_on_merge` setting disabled because it cannot distinguish these classes.

Closed-but-unmerged or superseded task branches need evidence that their commits/content survive on `main` or a retained `release/*` branch, or an explicit Human cleanup decision. A branch without a usable PR association needs proof that its head is reachable from a retained branch and its purpose is obsolete. Re-audit stale branches and open PRs immediately before one-time cleanup, record evidence on the linked Issue, and use `HUMAN_DECISION_REQUIRED` for any unresolved branch. Historical cleanup for Issue #37 starts only after its policy/workflow PR has been independently reviewed and Human-merged. Cleanup does not delete Actions runs, CI logs, tags, releases, or artifacts and never changes protection, required checks, or publication gates.

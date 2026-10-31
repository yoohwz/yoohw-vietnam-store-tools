# VST AI-assisted delivery workflow

This workflow aligns VST with Thumbnail Manager's Codex-led delivery model (`yoohwz/thumbnail-manager@1533a17e337ffaa92fd85745c4b73bee0cd329d1`, AGENTS.md), while retaining VST commerce, CI, task identity, branch, and release contracts.

## Admission and authority

Every workflow-governance semantic amendment follows the workflow accepted on its admitted base through Human merge. Proposed or unmerged text cannot authorize, waive, downgrade, or redefine its own role separation, review, Acceptance, Merge, or release requirements. In particular, VST-102 remains Controlled under `aa1b95855c5c8dc939fb9894b6232a7eae2d9965`: fresh exact-SHA independent Technical Review, separate external ChatGPT `Acceptance Review VST-102`, then explicit Human `Merge VST-102`. Its new commands and reduced handoff protocol apply only after Human merge. Preserve historical evidence, including VST-47's admitted transition; do not rewrite completed tasks.

Human owns product direction and acceptance, unresolved product/scope/safety trade-offs, merge approval, version/release decisions, production actions, and WordPress.org publication. Codex owns task framing, risk classification, technical architecture and planning, implementation, validation, review, corrections, acceptance evidence, work coordination, and branch/PR upkeep. One lead Codex remains accountable for the final result, including delegated work. Subagents perform bounded work; they do not replace Human authority or add approval stages. GitHub is the durable record. Tool access does not grant another role's authority.

For future work after this amendment merges, ChatGPT is not a required participant. Human can give a goal directly to Codex; Codex completes technical planning, implementation, validation, and proportional review internally through the Human merge gate. Ask Human only for missing information that blocks safe progress or a reserved decision. These repository roles take precedence over generic role descriptions.

## Task identity, recovery, and commands

GitHub Issue `#N` remains the canonical task `VST-N`. Every governed implementation PR links one readable, open Issue with `Task / issue: VST-N` and `Implementation owner: Codex`. Reuse the Issue for the brief, plan, and binding decisions; the PR is the primary execution record. Do not create a separate task registry, state JSON, digest chain, controller, approval parser, governance database, or planning document that merely mirrors GitHub history.

For `Run VST-N`, `Continue VST-N`, or another known task reference, read the Issue first and recover the current linked PR, base/head, Git identity, CI, reviews, and Human decisions. Search repository text by task code only if the Issue is missing/unreadable, repository identity is uncertain, or facts materially conflict. Inspect both top-level PR comments and review submissions by time and exact-head applicability. Prefer a top-level mirror of the same review decision for the actionable handoff; never hide a newer applicable review behind an older comment. Report missing or contradictory facts specifically.

| Command | Meaning |
| --- | --- |
| `Create ...` | Codex frames the Issue and starts authorized work. |
| `Run VST-N` / `Codex - Continue VST-N` | Recover context and perform the next Codex-owned action. |
| `Codex - Review VST-N` | Perform proportional review with required independence, fix blockers, validate, and continue to the Human gate. |
| `Human - Decide VST-N` | Resolve the recorded Human-owned decision. |
| `Human - Merge PR #N` / `Merge VST-N` | Authorize merge of the unchanged verified candidate. |

Resolve `Chạy task này`, `Tiếp tục`, `Review`, and `Sửa tiếp` from the conversation and GitHub when one task is unambiguous. Legacy `Continue VST-N`, `Plan Review VST-N`, `Review VST-N`, and `Acceptance Review VST-N` route outstanding Codex-owned planning/review/correction work internally after migration. Preserve original scope, Human decisions, unresolved findings, and safety contracts. Legacy status records are historical inputs, not active gates for future ordinary work. Any unmerged governance amendment retains its admitted-base gates through Human merge, including VST-102's separate external Acceptance. `Finalize VST-N` cannot bypass those gates or infer release authority.

## Brief, scope, and complexity

Fast briefs need only goal, scope, acceptance criteria, validation, and risk lane. Controlled briefs also name affected invariants, implementation constraints, material risks, and unresolved decisions. Record a Controlled plan in the existing Issue or draft PR before runtime implementation; no separate plan-approval Issue or external technical approval is required.

When product semantics are ambiguous, freeze a compact scenario table in that plan: scenario, expected behavior, and in/out of scope. Record a proportional complexity budget and stop-loss, including expected runtime surfaces and whether DDL, a new lock domain, recovery coordinator, or destructive authority is expected. Prefer existing mechanisms. If implementation unexpectedly needs a persistent schema/migration, lock/transaction/recovery domain, cross-subsystem destructive authority, or repeated blockers make the architecture poor value, stop the affected implementation and revise the plan with proportional independent subagent critique. Use `HUMAN_DECISION_REQUIRED` only when the revision needs a Human-owned product/scope/safety decision; continue independent authorized work where possible. Never silently expand scope or weaken invariants.

Use one task = one draft PR by default, with logical commits as review/rollback checkpoints. Split only at a real independently mergeable boundary: schema/migration, distinct destructive authority, intentional Human decision, dependency on accepted main, or a diff too broad for safe semantic review. The one-PR default does not authorize an unreviewable change.

## Risk lanes and review profiles

Use only Fast and Controlled. Classification is semantic, not filename-based.

Fast covers isolated documentation/governance, translations, presentation, test-only work, CI/tooling, small understood bugs, and local refactors that preserve public, persistence, security, and commerce contracts. A governance amendment that changes role authority, review, merge, CI requirements, or release semantics remains Controlled; editorial-only governance changes may be Fast.

Controlled covers Classic Checkout/Blocks and Store API, VietQR/payment/PayPal, shipping/tracking/carriers, VAT/e-invoice, order/customer data and HPOS, persistence/schema/migrations, REST/AJAX/capability/nonce/upload/security, public hooks/APIs/stored metadata/compatibility, release semantics, and cross-feature architecture. Promote Fast before implementing a discovered Controlled change.

Profiles tune evidence inside those lanes; they are not additional lanes or statuses:

| Profile | Evidence focus |
| --- | --- |
| Structural | Behavior-preserving move/refactor: source/body/API equivalence where practical, dependency boundaries, targeted contracts. |
| Functional | Changed product scenarios and affected WordPress/WooCommerce/browser integration. |
| Safety-critical | Payment integrity, sensitive data mutation, authorization, persistence/schema, destructive work, concurrency, replay or recovery: strongest relevant integration/runtime evidence and fresh independent review. |
| Release | Exact package, version consistency, strict Plugin Check, publication validation and release contracts within Human scope. |

Record the selected profile in PR evidence; the risk lane remains authoritative. For safety-critical changes or new destructive/persistence/locking architecture, have a fresh subagent challenge scenarios, invariants, complexity, and validation before implementation. Record material findings and corrections in the existing plan. This focused technical check adds no Human relay stage.

## Codex execution and independent review

Complete `discovery/plan -> implementation -> tests/runtime evidence -> proportional adversarial review -> fix blockers -> exact-head applicable final CI -> Human handoff`. Codex checks plan/diff alignment, architecture and invariants, safety, compatibility, acceptance criteria, and evidence sufficiency.

Fresh independent Codex review is mandatory for safety-critical Controlled changes materially affecting payment execution or financial integrity, order/customer data integrity or privacy, persistence/schema/migrations, authorization/capabilities/nonces/uploads, destructive operations/path containment, locking/ownership/cancellation/replay, recovery, or similarly irreversible transitions. Human may require it at any time. Other Controlled work may use the lead's adversarial verification plus strong evidence when a fresh reviewer adds disproportionate overhead. Pure mechanical Structural work need not gain independent review solely from its file location, but only when behavior is unchanged, equivalence is mechanically proven where practical, dependency-boundary tests exist, and no public/persisted contract or safety semantics change; otherwise use stronger review.

The independent reviewer uses fresh context, read-only source access, a separate worktree or clean checkout, and the exact PR head. Supply the task/plan, admitted base, total diff, invariants, and validation evidence without implementer private reasoning or self-review conclusions. A reviewer cannot approve its own implementation and must not recursively delegate. Record reviewer identity, exact reviewed SHA, findings, and corrections durably in the PR. Missing mandatory independent review means unfinished work; do not claim merge readiness.

Any candidate movement invalidates prior review/acceptance applicability and the Human handoff. Inspect corrections as a delta from the previously reviewed SHA, verify original blockers and affected invariants, and spot-check the final PR. Record a fresh exact-head result with the required independent reviewer; repeat a full review only if scope materially expanded. Do not reuse stale approval. Only correctness, security, safety, regressions, acceptance failures, required CI failures, and contract violations block progress. Naming, optional cleanup, and future refactors remain advisory. Continue correction cycles while they make material progress; revise a stagnant approach and seek Human only for reserved decisions, with no numeric round cap.

## Subagents, models, and effort

Delegate bounded objectives with clear scope, inputs, checkout, binding decisions, and expected evidence. Use separate worktrees for concurrent implementation and avoid overlapping file ownership. The lead integrates and validates the final head; no fixed roster or agent count is required. Keep plan critique distinct from actual implementation review; do not add serial rounds for advisory findings.

Human authorizes the lead Codex to choose subagent model and supported effort without another approval: GPT-6.1 Sol (`gpt-6.1-sol`) is the default for discovery, planning, implementation, tests, and independent review. Choose low/medium for straightforward bounded work and high or higher supported effort when complexity, ambiguity, risk, evidence quality, or progress justifies it. This authority does not require changing the main agent's model.

Use GPT-6 Astra (`gpt-6-astra`) only for a bounded unresolved technical question. Explain briefly in existing PR evidence why more evidence, decomposition, or increased Sol effort is insufficient or unsuitable, the expected result, and the outcome. A Controlled/safety-critical label, architecture, or reviewer role alone does not justify Astra. Missing tools/permissions and infrastructure failures need their actual cause fixed. Do not require repeated failed Sol attempts when the technical case is clear. Return routine work to Sol afterward; add no quotas, forms, or approval stages.

Apply changes through available agent controls. If an agent cannot be reconfigured, finish/stop its bounded work and reassign the remainder, preserving findings without duplicate implementation. Never claim an unavailable model switch. For fresh-context overrides, provide all task inputs explicitly. Independent review still requires fresh context, separate checkout, exact-head evidence and a separate reviewer, not a different model. Model choices never change scope, validation, invariants, or Human authority.

## Validation and CI

Before commit/push, inspect status, branch, remote and relevant diff; preserve unrelated work, stage explicit files, and inspect the complete staged diff for secrets, local paths, generated junk and scope. Run `git diff --check`. Lint every changed PHP file with a compatible runtime, run email placeholder tests for email changes, validate changed JSON/block metadata, and check version consistency. Use relevant WordPress/WooCommerce admin, checkout, payment, order, email, upload and HPOS runtime checks for affected behavior. Documentation/governance needs repository contracts and diff hygiene, not unrelated runtime work. Record checks as PASS or `NOT RUN — reason`; never imply an unrun check passed.

Preserve existing staged CI, `Workflow governance`, `Repository contracts`, `VST Required Gate`, PHP 7.4/8.2/8.4 contexts, localization quality and translation-runtime checks, strict Plugin Check, product/architecture/address/email/PayPal contracts, and WordPress.org publication validation. Draft PRs use quick iteration CI. Before Human merge handoff, mark ready-for-review and wait for the exact head's risk-matched deep/final CI. PR prose/status never controls depth. This alignment does not replace the VST classifier, add TM check names, or redesign branch protection. Conditional jobs may skip only when classified irrelevant; failed/cancelled or unexpectedly skipped required jobs cannot pass. Green CI proves only checks that ran and cannot replace relevant runtime evidence. Never weaken a gate to bypass failure.

## Durable handoffs and Human merge

Use only `HUMAN_DECISION_REQUIRED` and `READY_FOR_HUMAN_MERGE` as Human handoff statuses for future tasks. Plans, implementation, tests, review findings and CI progress are normal evidence, not relay stages. Status text and `Next:` are navigation, not a state database or authorization parser; Issue/PR facts, Git identity, current CI, applicable exact-SHA evidence and explicit Human commands determine authority.

Keep the PR body current: goal/scope, brief and plan, risk/profile, binding Human decisions, semantic changes/invariants, compact PASS/NOT RUN evidence, blocking findings/corrections, runtime limitations, and release boundary. Before merge readiness, resolve blockers, complete mandatory independent review and applicable final CI, then post one canonical top-level handoff with `STATUS: READY_FOR_HUMAN_MERGE`, task/PR identity, exact head SHA, evidence, and `Next: Human - Merge PR #N`. For a reserved decision, record concrete options and recommendation with `STATUS: HUMAN_DECISION_REQUIRED` and `Next: Human - Decide VST-N`. Supporting subagent findings/review submissions are evidence, not new workflow transitions.

Every final user-visible workflow response with a clear next action ends with exactly one concise copy-ready `Next: <Target> - <short command>`, matching its durable handoff. Unfinished Codex work uses `Next: Codex - Continue VST-N`; do not stop authorized autonomous work merely to emit a hint. No hint is needed when no clear next action remains. The admitted-base protocol still governs unmerged governance amendments, including VST-102's legacy Acceptance handoff.

Only explicit Human merge authorization permits merge. Immediately recheck unchanged head/base, required CI, applicable reviews/acceptance and branch protection. A new commit requires affected checks/review and final CI plus a replacement exact-head handoff. Merge does not imply version change, Stable tag, changelog date, tag, GitHub Release, deployment, production mutation or WordPress.org publication.

Use a same-Issue closing link such as `Closes #N`. After the Human-authorized merge succeeds, verify the canonical Issue is closed as completed; close it explicitly if automatic closure did not occur, including for retained release branches. Never close before successful merge or report completion while it remains open. Historical `FINALIZED` means this verified completion, not an extra approval stage.

## Git, branches, and release

Never push directly to main, force push, rewrite shared history, destructively reset, or weaken protection. Use an isolated checkout for concurrent tasks, and one task branch/PR. Do not replace/close an existing PR until its replacement is pushed and verified complete.

For version work, finish and Human-merge the current release PR, fast-forward local `main` from `origin/main`, then create `release/<next-version>` or an isolated `agent/<version>-<scope>` branch. If work depends on an unmerged release branch, branch from and target that release branch; rebase or retarget only after the prerequisite merges, without rewriting shared history. Keep the WordPress.org Stable tag at the last publicly released version while the next version is marked In development. Release preparation updates the plugin header and fallback version in `yoohw-vietnam-store-tools.php`, block metadata and generated asset metadata, `changelog.txt`, `readme.txt`, and translation catalogs/compiled files when strings change. Finalize the changelog date, change Stable tag, create tags or releases, deploy, or publish to WordPress.org only with explicit Human release scope. Preserve the existing WordPress.org publication validation.

After merge, synchronize local `main` before next-version work. Normal `agent/*` heads are disposable task branches, but automatic cleanup applies only to merged, same-repository `agent/*` heads whose current SHA still matches the merged PR head and which no other open PR uses. Skip protected branches and never reuse completed task branch names. All `release/*` branches are retained because their heads must remain available and match release tags for WordPress.org publication; `main` is never deleted or rewritten. Keep the repository-wide `delete_branch_on_merge` setting disabled because it cannot distinguish these classes.

Closed-but-unmerged or superseded task branches need evidence that their commits/content survive on `main` or a retained `release/*` branch, or an explicit Human cleanup decision. A branch without a usable PR association needs proof that its head is reachable from a retained branch and its purpose is obsolete. Re-audit stale branches and open PRs immediately before one-time cleanup, record evidence on the linked Issue, and use `HUMAN_DECISION_REQUIRED` for any unresolved branch. Historical cleanup for Issue #37 starts only after its policy/workflow PR has been independently reviewed and Human-merged. Cleanup does not delete Actions runs, CI logs, tags, releases, or artifacts and never changes protection, required checks, or publication gates.

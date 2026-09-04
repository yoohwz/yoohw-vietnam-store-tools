# Free-VST Project Instructions

These instructions apply to the entire `yoohw-vietnam-store-tools` repository.

## Project authority

- This repository is the official source of truth for Vietnam Store Toolkit for WooCommerce.
- Plugin code, metadata, translations, tests, changelog, and `readme.txt` must be changed here, not in the Vietnam Store website project.
- The public product website is `https://vietnamstore.org/`.
- The public WordPress.org listing is `https://wordpress.org/plugins/yoohw-vietnam-store-tools/`.
- Publishing to WordPress.org is a separate release action. Never publish there unless the user explicitly requests it.

## Git safety

- Inspect `git status -sb`, the current branch, the remote, and the relevant diff before staging or committing.
- Treat existing modified or untracked files as user work. Preserve them and confirm their scope before including them.
- Never push directly to `main`.
- Never use `git push --force`, `git push --force-with-lease`, destructive resets, or history rewrites on shared branches.
- Do not weaken or remove `main` branch protection.
- Stage explicit files. Use `git add -A` only when the user has explicitly confirmed that the entire working tree belongs to one change.
- Keep commits logically grouped and use concise messages such as `feat:`, `fix:`, `test:`, `docs:`, or `chore:`.
- Push a non-default branch and open a draft pull request into the intended base branch.
- Do not close or replace an existing pull request until the replacement branch is pushed and verified to contain all required changes.

## Branch and worktree model

- `main` contains reviewed, merged code and is protected.
- Use `release/<version>` to integrate an official version, for example `release/1.1.3`.
- Use `agent/<version>-<scope>` for version-bound features, fixes, documentation, or experiments.
- Repo-level CI, tooling, or governance work that is intentionally independent of a product version may use `agent/<scope>`.
- Start a new version from the latest `origin/main` after updating local `main` with a fast-forward-only pull.
- If new work depends on an unmerged release branch, branch from that release branch and target the dependent pull request there. Rebase or retarget onto `main` after the prerequisite release merges.
- Concurrent Codex tasks must use separate Git worktrees or separate clean checkouts. Do not let two tasks modify the same working directory.
- Free-VST remains authoritative. Work from another Codex project must not commit or push this repository directly.

## AI-assisted delivery workflow

Use ChatGPT and Codex for different strengths instead of asking one agent to own the complete development lifecycle.

### Roles

- **Human** owns product direction, unresolved trade-offs, merge approval, version/release decisions, production actions, and WordPress.org publication.
- **ChatGPT** is the product/architecture/review authority. It defines the goal and boundaries, classifies risk, performs external research when useful, reviews plans for higher-risk work, and independently reviews the actual pull-request diff and evidence.
- **Codex** is the repository implementation authority. It performs code discovery, prepares an implementation plan when required, edits code, adds tests, runs validation, commits, pushes the task branch, opens or updates the draft pull request, and corrects review findings.
- **GitHub** is the execution record for code, commits, pull requests, CI, review history, and release history.

Do not duplicate this record into a separate task-management or governance system unless the user explicitly needs one.

### Role lock

- ChatGPT must never implement repository changes directly. It must not create or modify implementation branches, working-tree files, commits, pushes, or implementation pull requests.
- GitHub, shell, browser, connector, or filesystem write capability does not grant implementation authority. Tool capability and workflow authority are separate.
- ChatGPT may write only the durable product/architecture/review artifacts assigned to its role, such as task briefs, plan-review results, technical-review findings, and review status comments.
- Codex is the only AI role authorized to mutate the repository for implementation. It must still satisfy the task brief, risk lane, review gates, Git safety rules, and Human-owned boundaries.
- ChatGPT must not review its own implementation. If a repository change was created through a ChatGPT execution path, treat it as reference material only and reroute the task to Codex before independent technical review.
- If Codex execution is unavailable, ChatGPT must preserve or post the appropriate durable GitHub handoff and stop. It must not take over implementation to keep the task moving.
- Shared GitHub account metadata cannot prove which conversational role created a change. CI validates durable workflow artifacts and transitions; it does not replace the role lock or independent review.

### Pre-write routing gate

Before any implementation branch, file, commit, push, or pull-request mutation, recover the task brief, risk lane, newest durable issue/PR status, and current PR head SHA. Determine the current owner from this table and stop if the active role is not that owner.

| Durable state | Current owner | Permitted next action |
| --- | --- | --- |
| No complete task brief or unresolved product boundary | ChatGPT or Human | Define/decide scope; no repository implementation mutation |
| Fast Lane task brief complete | Codex | Implement, validate, and create/update the draft PR |
| Controlled Lane brief without a plan handoff | Codex | Perform read-only discovery and post `PLAN_REVIEW_REQUIRED`; no runtime implementation yet |
| Controlled Lane has `PLAN_REVIEW_REQUIRED` without a current approval | ChatGPT | Review the persisted plan and post the result; no repository implementation mutation |
| Controlled Lane plan has a current `PLAN REVIEW: APPROVED` result | Codex | Implement, validate, and create/update the draft PR |
| Current head has `TECHNICAL_REVIEW_REQUIRED` | ChatGPT | Independently review that exact head; implementation is frozen |
| Current head has `TECHNICAL_CHANGES_REQUIRED` | Codex | Return the PR to Draft, correct it, validate it, and post a new head-bound handoff |
| Current head has `READY_FOR_HUMAN_MERGE` | Human | Decide whether to merge; AI roles do not add implementation commits |
| `HUMAN_DECISION_REQUIRED` | Human | Resolve the recorded decision before routing resumes |

A head SHA change immediately returns implementation ownership to Codex and invalidates all earlier technical handoffs and results. PR prose, a green CI run, or tool access must never be used to infer that another role owns the next step.

### Human command interface

Keep Human commands short. Complexity belongs to ChatGPT and Codex, not to the Human operator.

Normal commands may be as short as:

- `Chạy task này`
- `Chạy VST-xxxx`
- `Tiếp tục`
- `Tiếp tục VST-xxxx`
- `Review`
- `Review VST-xxxx`
- `Sửa tiếp`
- `Chuẩn bị release`
- `Release`

These are examples, not magic strings. Interpret equivalent short natural-language instructions the same way.

When there is one active task in the current context, `Tiếp tục` means continue the next valid workflow step for that task. The receiving agent should recover the current task, status, latest artifact, open review findings, branch, and pull request from available context or GitHub instead of asking the Human to restate them.

`Review` is a routing command, not a request for one fixed review type. ChatGPT must resolve whether the current task needs plan review, technical review, re-review after corrections, or a Human decision by following the durable handoff and review-routing rules below. The Human should not have to say `Plan Review` versus `Technical Review` when the task state already makes that clear.

Use a task identifier only when it improves traceability, such as parallel work, long-running work, or work that spans conversations. Do not require an ID for every small fix or documentation change.

Ask the Human for clarification only when a real product, safety, release, or mutually exclusive implementation decision cannot be resolved from the task, repository, or review history.

### Task brief

Before implementation, ChatGPT should reduce the request to the minimum useful brief:

- **Goal** — the observable result to achieve.
- **Problem** — why the change is needed.
- **In Scope** — permitted change surface.
- **Out of Scope** — explicit exclusions when useful.
- **Invariants** — behavior or contracts that must remain true.
- **Acceptance Criteria** — conditions for completion.
- **Validation** — tests or runtime checks expected.
- **Risk Lane** — `Fast` or `Controlled`.

Add **Unresolved Decisions** only when a real decision remains. The brief may stay in the working conversation; do not create a repository task document merely to mirror it.

### Risk lanes

Use only two lanes.

#### Fast Lane

Use Fast Lane for low-risk, isolated work such as:

- documentation and translation;
- copy or text-only changes;
- test additions that do not alter runtime behavior;
- isolated UI/CSS/JavaScript fixes with a narrow behavior surface;
- repository tooling or CI changes;
- small bug fixes with an already understood cause;
- local refactors that do not change public or persistence contracts.

Normal flow:

`Human -> ChatGPT task brief -> Codex implementation -> draft PR/evidence -> ChatGPT technical review -> Human merge gate`

Fast Lane does not require a separate plan-review gate unless discovery reveals higher risk.

#### Controlled Lane

Use Controlled Lane when work changes or may materially affect:

- Classic Checkout or Cart/Checkout Blocks;
- Store API behavior;
- VietQR, payment, or bank-transfer behavior;
- shipping calculation, shipment state, tracking, or carrier integration;
- VAT/tax invoice or electronic-invoice workflows;
- order mutation, customer/order data, HPOS, or legacy order storage;
- migration or persistence behavior;
- REST, AJAX, capability, nonce, upload, or other security boundaries;
- database/schema behavior;
- public hooks, filters, APIs, stored metadata, or backward compatibility;
- release/version semantics or cross-feature architecture.

Normal flow:

`Human -> ChatGPT task brief -> Codex discovery/plan -> ChatGPT plan review -> Codex implementation -> draft PR/evidence -> ChatGPT technical review -> Human merge gate`

If a Fast Lane task discovers one of these risks, stop implementation at a safe boundary and promote it to Controlled Lane.

### Status protocol

Use this small status vocabulary at handoff boundaries:

- `PLAN_REVIEW_REQUIRED` — Controlled Lane discovery is complete and the implementation plan needs ChatGPT review before coding continues.
- `TECHNICAL_REVIEW_REQUIRED` — implementation and available validation are complete enough for independent review of the pull request.
- `TECHNICAL_CHANGES_REQUIRED` — the reviewer found blocking technical or acceptance issues that Codex must correct.
- `HUMAN_DECISION_REQUIRED` — progress depends on a product, architecture, compatibility, release, or other decision reserved for the Human.
- `READY_FOR_HUMAN_MERGE` — independent technical review passed; merge remains a Human action.

Do not invent additional statuses unless a future workflow demonstrably needs them.

### Durable handoffs and review routing

Cross-agent handoffs must be recoverable from GitHub before the Human is expected to issue a short follow-up command.

- A session-only handoff is not a durable cross-agent handoff. Before returning control to the Human, the sending agent must persist the gate artifact or result to the linked GitHub issue or pull request.
- Controlled Lane work that requires plan review must have a durable GitHub planning anchor. Reuse an existing issue when one exists. If no issue exists, Codex should create a lightweight issue before returning `PLAN_REVIEW_REQUIRED`; this is the narrow exception to the general rule that GitHub Issues are optional.
- Codex must post the full `PLAN_REVIEW_REQUIRED` handoff as an issue comment before runtime implementation begins. Include the relevant base/branch state, affected architecture/contracts/files, implementation approach, validation strategy, risks, and unresolved decisions.
- ChatGPT must post the plan-review result back to the same issue. Use clear prose such as `PLAN REVIEW: APPROVED — implementation may proceed` or `PLAN REVIEW: CHANGES REQUIRED`, followed by any blocking findings. These are review results, not additional workflow statuses.
- Do not claim in a pull request that plan review was completed unless a durable plan handoff and ChatGPT review result can be located in GitHub history.
- After implementation, Codex must update the draft pull-request body with current scope/evidence and post `STATUS: TECHNICAL_REVIEW_REQUIRED` in the PR conversation with the current head SHA.
- ChatGPT must persist the technical-review result in the same PR conversation as either `STATUS: TECHNICAL_CHANGES_REQUIRED` with blocking findings or `STATUS: READY_FOR_HUMAN_MERGE` with the review evidence. Every technical status comment must include `Head SHA: <full 40-character SHA>`. If GitHub prevents a formal review event because the authenticated user owns the PR, the PR conversation comment remains the authoritative handoff.
- Any commit pushed after a `TECHNICAL_REVIEW_REQUIRED`, `TECHNICAL_CHANGES_REQUIRED`, or `READY_FOR_HUMAN_MERGE` status invalidates that status and every earlier technical-review result. Before corrections, return the pull request to Draft. After corrections and validation, Codex must post a new `TECHNICAL_REVIEW_REQUIRED` handoff for the new head SHA and then mark the pull request ready before ChatGPT re-reviews it.
- A technical handoff or result is current only when it is the newest technical status in the PR conversation and names the exact current head SHA. Never edit an old status to point at a new head; post a new status comment so the transition remains durable.
- When an existing workflow artifact must remain as history but is no longer valid, edit that comment so its first substantive line is `WORKFLOW ARTIFACT: SUPERSEDED` or `WORKFLOW ARTIFACT: INVALIDATED`. The governance validator ignores the entire marked comment, including any historical status or approval text retained below it. The legacy first-line marker `SUPERSEDED / NOT A VALID WORKFLOW GATE` has the same meaning.
- The `Workflow governance` CI check validates the declared implementation owner, exactly one risk lane, trusted-maintainer comments for the approved issue-anchored Controlled Lane plan gate, and a current head-bound technical status at the existing PR workflow transitions. The existing `VST Required Gate` consumes the check so governance failures are merge-blocking wherever that required gate is enforced. PR-body prose or edits never supersede the durable issue/PR comments and head SHA.
- If the Human pastes a newer handoff directly into the current ChatGPT conversation, ChatGPT may use it immediately and verify the linked GitHub artifacts instead of forcing the Human to repost it. Missing persistence should be recorded as a workflow/process finding, not used to make the Human repeat information that is already available.

When the Human says `Review`, ChatGPT must route the request in this order:

1. Recover the active task from an explicit task/issue/PR reference, the current conversation, and linked GitHub artifacts. If there is only one credible active task, do not ask which task to review.
2. Inspect the linked issue and open pull request, including their conversation history, and identify the newest applicable durable handoff. Use the referenced head SHA and timestamps to distinguish current artifacts from stale ones.
3. If the newest handoff is `PLAN_REVIEW_REQUIRED`, review the persisted plan rather than searching for an implementation PR. Persist the plan-review result to the issue before handing control back.
4. If the newest handoff is `TECHNICAL_REVIEW_REQUIRED`, independently review the current PR head, actual diff, CI, tests, runtime evidence, acceptance criteria, and release boundary.
5. If the newest result is `TECHNICAL_CHANGES_REQUIRED`, do not re-review the stale head. First check whether Codex has pushed corrections and posted a newer `TECHNICAL_REVIEW_REQUIRED`. If yes, review that new head; otherwise report that corrections are still pending.
6. If the newest handoff is `HUMAN_DECISION_REQUIRED`, surface the unresolved decision instead of pretending a technical review can resolve it.
7. If the newest result is `READY_FOR_HUMAN_MERGE`, no additional review is required unless the Human explicitly requests revalidation or the PR head/base changed afterward.
8. Never infer that a review gate passed merely from PR prose such as `plan review completed`. The durable handoff/result and the artifact it refers to are authoritative.

### Codex handoff

After implementation, keep the handoff concise and factual:

```text
STATUS: TECHNICAL_REVIEW_REQUIRED

Branch:
PR:
Head SHA:

Implemented:
- ...

Validation:
- PASS ...
- NOT RUN ... because ...

Runtime evidence:
- ...

Known limitations:
- ...

Scope deviations:
- None
```

For Controlled Lane plan review, return `PLAN_REVIEW_REQUIRED` with only the relevant architecture, affected contracts/files, implementation approach, validation strategy, risks, and unresolved decisions. Persist that handoff to the linked GitHub issue before returning control to the Human.

Never claim a check passed when it could not be run.

### Independent technical review

ChatGPT technical review must inspect the actual GitHub pull request, not merely trust the Codex summary.

At minimum, review:

1. intended base and head branches;
2. changed files and actual diff;
3. scope and acceptance criteria;
4. logic and regression surface;
5. WordPress/WooCommerce compatibility;
6. security and data handling where relevant;
7. tests and validation evidence;
8. CI results when available;
9. runtime/manual evidence for runtime-sensitive changes;
10. release-boundary compliance.

If blocking findings exist, use `TECHNICAL_CHANGES_REQUIRED`. Codex fixes them and returns the same pull request for another review. Do not create a replacement branch or pull request unless replacement is actually necessary.

### Pull requests as the execution record

- The pull request is the primary durable record of implementation scope and evidence.
- GitHub Issues are optional and should be used for roadmap items, feature requests, bugs needing long-term tracking, or multi-step/multi-release work; do not create an issue for every small task. Controlled Lane plan review is the exception: it requires a durable GitHub issue anchor so the plan can be reviewed across agents/sessions before implementation.
- Durable architectural decisions may be added to repository documentation when they need to survive beyond a pull request. Do not create planning, review, acceptance, and status documents that simply repeat GitHub history.
- A green CI run proves only the checks that CI actually executes. It does not replace relevant WordPress/WooCommerce runtime verification.

## Version workflow

1. Finish, review, and merge the current release pull request.
2. Update local `main` from `origin/main` using fast-forward-only synchronization.
3. Create `release/<next-version>` or an isolated `agent/<version>-<scope>` branch.
4. Develop and validate changes without altering the published WordPress.org state prematurely.
5. During release preparation, update all applicable version sources together:
   - plugin header and fallback version in `yoohw-vietnam-store-tools.php`
   - block metadata such as `blocks/order-tracking/block.json`
   - generated block asset metadata such as `blocks/order-tracking/index.asset.php`
   - `changelog.txt`
   - `readme.txt`
   - translation catalogs and compiled translation files when strings change
6. Keep the WordPress.org `Stable tag` at the last publicly released version while the next version is marked `In development`.
7. Change the `Stable tag`, finalize the changelog date, create tags, or publish to WordPress.org only as part of an explicitly requested release.

## Required validation before commit or push

- Run PHP syntax checks for every changed PHP file with a compatible project PHP runtime.
- Run `php tests/email-placeholder-contract-tests.php` when email classes, placeholders, subjects, templates, or sending behavior change.
- Validate changed JSON and generated block asset metadata.
- Confirm version values are consistent across the plugin header, blocks, changelog, and README.
- Run `git diff --check`.
- Review the complete staged diff and confirm no private keys, tokens, passwords, local-only paths, generated junk, or unrelated files are included.
- Perform relevant WordPress/WooCommerce runtime or manual checks when the change affects admin screens, checkout, order handling, email sending, uploads, or HPOS behavior.
- If a required check cannot run because of the environment, record the exact limitation in the pull request instead of claiming it passed.

## Pull request and release handoff

- Draft pull requests should explain what changed, why, developer/user impact, compatibility implications, and validation performed.
- Confirm the pull request targets the intended base, contains the expected commits and files, and is mergeable.
- Resolve review conversations before merging.
- After merge, synchronize local `main` before creating the next version branch.
- Do not delete release branches, create release tags, deploy production code, or publish a WordPress.org version unless that action is explicitly in scope.

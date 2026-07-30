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
- Use `agent/<version>-<scope>` for isolated features, fixes, documentation, or experiments.
- Start a new version from the latest `origin/main` after updating local `main` with a fast-forward-only pull.
- If new work depends on an unmerged release branch, branch from that release branch and target the dependent pull request there. Rebase or retarget onto `main` after the prerequisite release merges.
- Concurrent Codex tasks must use separate Git worktrees or separate clean checkouts. Do not let two tasks modify the same working directory.
- Free-VST remains authoritative. Work from another Codex project must not commit or push this repository directly.

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

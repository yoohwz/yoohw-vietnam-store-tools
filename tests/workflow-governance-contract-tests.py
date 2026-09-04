#!/usr/bin/env python3
"""Contracts for role-separated workflow governance."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GOVERNANCE_PATH = ROOT / "scripts/workflow_governance.py"
HEAD = "a" * 40
OLD_HEAD = "b" * 40


def load_governance():
    spec = importlib.util.spec_from_file_location(
        "vst_workflow_governance", GOVERNANCE_PATH
    )
    if spec is None or spec.loader is None:
        raise AssertionError("Unable to load scripts/workflow_governance.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def pr_body(*, fast: bool = False, controlled: bool = False, owner: str = "Codex") -> str:
    return f"""## Risk lane

- [{'x' if fast else ' '}] Fast
- [{'x' if controlled else ' '}] Controlled

## Workflow handoff

- Task / issue: #34
- Implementation owner: {owner}
"""


def pull_request(body: str, *, draft: bool) -> dict[str, object]:
    return {"body": body, "draft": draft, "head": {"sha": HEAD}}


def comment(body: str, association: str = "OWNER") -> dict[str, str]:
    return {"body": body, "author_association": association}


def expect_error(errors: list[str], fragment: str) -> None:
    if not any(fragment in error for error in errors):
        raise AssertionError(f"Expected error containing {fragment!r}; got {errors!r}")


def main() -> int:
    governance = load_governance()

    errors = governance.validate_pull_request(
        pull_request(pr_body(fast=True), draft=True), []
    )
    if errors:
        raise AssertionError(f"Valid Fast Lane draft was rejected: {errors}")

    errors = governance.validate_pull_request(
        pull_request(pr_body(), draft=True), []
    )
    expect_error(errors, "exactly one risk lane")

    errors = governance.validate_pull_request(
        pull_request(pr_body(fast=True, controlled=True), draft=True), []
    )
    expect_error(errors, "exactly one risk lane")

    errors = governance.validate_pull_request(
        pull_request(pr_body(fast=True, owner="ChatGPT"), draft=True), []
    )
    expect_error(errors, "Implementation owner: Codex")

    controlled_pr = pull_request(pr_body(controlled=True), draft=True)
    errors = governance.validate_pull_request(controlled_pr, [])
    expect_error(errors, "Unable to load task issue")

    plan_handoff = comment(
        "STATUS: PLAN_REVIEW_REQUIRED\n\nArchitecture and validation plan."
    )
    plan_approved = comment("PLAN REVIEW: APPROVED — implementation may proceed")
    plan_changes = comment("PLAN REVIEW: CHANGES REQUIRED\n\nRevise the cache design.")
    task_issue = {"number": 34}

    errors = governance.validate_pull_request(
        controlled_pr,
        [],
        task_issue=task_issue,
        task_comments=[plan_handoff, comment(plan_approved["body"], "CONTRIBUTOR")],
    )
    expect_error(errors, "PLAN_REVIEW_REQUIRED")

    issue_32_superseded_approval = comment(
        """**SUPERSEDED / NOT A VALID WORKFLOW GATE**

This review is retained as historical context only.

---

PLAN REVIEW: APPROVED — implementation may proceed.
"""
    )
    errors = governance.validate_pull_request(
        controlled_pr,
        [],
        task_issue=task_issue,
        task_comments=[plan_handoff, issue_32_superseded_approval],
    )
    expect_error(errors, "PLAN_REVIEW_REQUIRED")

    errors = governance.validate_pull_request(
        controlled_pr,
        [],
        task_issue=task_issue,
        task_comments=[plan_handoff, plan_approved, plan_changes],
    )
    expect_error(errors, "PLAN_REVIEW_REQUIRED")

    errors = governance.validate_pull_request(
        controlled_pr,
        [],
        task_issue=task_issue,
        task_comments=[plan_handoff, plan_changes, plan_approved],
    )
    expect_error(errors, "PLAN_REVIEW_REQUIRED")

    errors = governance.validate_pull_request(
        controlled_pr,
        [],
        task_issue=task_issue,
        task_comments=[plan_handoff, plan_changes, plan_handoff, plan_approved],
    )
    if errors:
        raise AssertionError(f"Approved Controlled Lane draft was rejected: {errors}")

    ready_fast = pull_request(pr_body(fast=True), draft=False)
    errors = governance.validate_pull_request(ready_fast, [])
    expect_error(errors, "Ready PR must have")

    forged_handoff = comment(
        f"STATUS: TECHNICAL_REVIEW_REQUIRED\n\nHead SHA: {HEAD}",
        "CONTRIBUTOR",
    )
    errors = governance.validate_pull_request(ready_fast, [forged_handoff])
    expect_error(errors, "Ready PR must have")

    invalidated_handoff = comment(
        f"WORKFLOW ARTIFACT: INVALIDATED\n\n"
        f"STATUS: TECHNICAL_REVIEW_REQUIRED\n\nHead SHA: {HEAD}"
    )
    errors = governance.validate_pull_request(ready_fast, [invalidated_handoff])
    expect_error(errors, "Ready PR must have")

    stale_handoff = comment(
        f"STATUS: TECHNICAL_REVIEW_REQUIRED\n\nHead SHA: {OLD_HEAD}"
    )
    errors = governance.validate_pull_request(ready_fast, [stale_handoff])
    expect_error(errors, "stale")

    changes_required = comment(
        f"STATUS: TECHNICAL_CHANGES_REQUIRED\n\nHead SHA: {HEAD}"
    )
    errors = governance.validate_pull_request(ready_fast, [changes_required])
    expect_error(errors, "requires Codex corrections")

    current_handoff = comment(
        f"STATUS: TECHNICAL_REVIEW_REQUIRED\n\nHead SHA: {HEAD}"
    )
    errors = governance.validate_pull_request(
        ready_fast, [stale_handoff, changes_required, current_handoff]
    )
    if errors:
        raise AssertionError(f"Current technical handoff was rejected: {errors}")

    ready_result = comment(
        f"STATUS: READY_FOR_HUMAN_MERGE\n\nHead SHA: `{HEAD}`"
    )
    errors = governance.validate_pull_request(ready_fast, [ready_result])
    expect_error(errors, "must follow TECHNICAL_REVIEW_REQUIRED")

    errors = governance.validate_pull_request(
        ready_fast, [current_handoff, ready_result]
    )
    if errors:
        raise AssertionError(f"Current ready result was rejected: {errors}")

    errors = governance.validate_pull_request(
        {"body": pr_body(fast=True), "draft": False, "head": {"sha": OLD_HEAD}},
        [current_handoff, ready_result],
    )
    expect_error(errors, "stale")

    print(
        "Workflow governance contracts PASS: role declaration, Controlled Lane plan gate, "
        "and head-bound technical status enforcement."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

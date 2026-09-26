#!/usr/bin/env python3
"""Contracts for mechanical governance and the closed VST-47 transition."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GOVERNANCE_PATH = ROOT / "scripts/workflow_governance.py"
HEAD = "a" * 40
OLD_HEAD = "b" * 40
ADMITTED_BASE = "e54450fd545976f87458affd251551c454cf3aea"


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


def pr_body(
    *,
    task: str = "VST-48",
    fast: bool = False,
    controlled: bool = False,
    owner: str = "Codex",
) -> str:
    return f"""## Risk lane

- [{'x' if fast else ' '}] Fast
- [{'x' if controlled else ' '}] Controlled

## Task identity

- Task / issue: {task}
- Implementation owner: {owner}
"""


def pull_request(body: str, *, draft: bool, base: str = ADMITTED_BASE):
    return {
        "body": body,
        "draft": draft,
        "head": {"sha": HEAD, "ref": "agent/vst-47-workflow"},
        "base": {"sha": base, "ref": "main"},
    }


def comment(body: str, association: str = "OWNER"):
    return {"body": body, "author_association": association}


def expect_error(errors: list[str], fragment: str) -> None:
    if not any(fragment in error for error in errors):
        raise AssertionError(f"Expected {fragment!r}; got {errors!r}")


def expect_pass(errors: list[str], case: str) -> None:
    if errors:
        raise AssertionError(f"{case} was rejected: {errors}")


def main() -> int:
    governance = load_governance()
    issue_48 = {"number": 48, "state": "open"}
    unrelated_comments = [
        comment("STATUS: TECHNICAL_CHANGES_REQUIRED\nHead SHA: " + OLD_HEAD),
        comment("PLAN REVIEW: CHANGES REQUIRED"),
    ]

    for task in ("VST-48", "VST-48 (#48)", "#48"):
        for lane in ("Fast", "Controlled"):
            body = pr_body(task=task, fast=lane == "Fast", controlled=lane == "Controlled")
            for draft in (True, False):
                expect_pass(
                    governance.validate_pull_request(
                        pull_request(body, draft=draft),
                        unrelated_comments,
                        task_issue=issue_48,
                        task_comments=unrelated_comments,
                    ),
                    f"post-cutover {task} {lane} draft={draft}",
                )

    for body in (
        pr_body(fast=True, controlled=True),
        pr_body(),
    ):
        expect_error(
            governance.validate_pull_request(
                pull_request(body, draft=True), task_issue=issue_48
            ),
            "exactly one risk lane",
        )

    expect_error(
        governance.validate_pull_request(
            pull_request(pr_body(fast=True, owner="ChatGPT"), draft=True),
            task_issue=issue_48,
        ),
        "Implementation owner: Codex",
    )
    expect_error(
        governance.validate_pull_request(
            pull_request(pr_body(fast=True) + "- Implementation owner: Codex\n", draft=True),
            task_issue=issue_48,
        ),
        "Implementation owner: Codex",
    )

    for task in ("", "VST-48 (#49)", "other/repo#48", "VST-0", "VST-48 and #49"):
        expect_error(
            governance.validate_pull_request(
                pull_request(pr_body(task=task, fast=True), draft=True),
                task_issue=issue_48,
            ),
            "canonical Task / issue",
        )
    expect_error(
        governance.validate_pull_request(
            pull_request(pr_body(fast=True), draft=True)
        ),
        "Unable to load canonical task Issue #48",
    )
    for invalid_issue in (
        {"number": 49, "state": "open"},
        {"number": 48, "state": "open", "pull_request": {}},
    ):
        expect_error(
            governance.validate_pull_request(
                pull_request(pr_body(fast=True), draft=True),
                task_issue=invalid_issue,
            ),
            "Task identity must resolve",
        )
    for lane in ("Fast", "Controlled"):
        expect_error(
            governance.validate_pull_request(
                pull_request(
                    pr_body(fast=lane == "Fast", controlled=lane == "Controlled"),
                    draft=False,
                ),
                task_issue={"number": 48, "state": "closed"},
            ),
            "must be open",
        )

    entrypoint = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
    workflow = (ROOT / "docs/workflow.md").read_text(encoding="utf-8")
    template = (ROOT / ".github/pull_request_template.md").read_text(encoding="utf-8")
    for name, source in (("AGENTS.md", entrypoint), ("docs/workflow.md", workflow)):
        for clause in ("admitted base", "through Human merge", "cannot authorize, waive, downgrade, or redefine"):
            if clause not in source:
                raise AssertionError(f"{name} lost self-governance contract: {clause}")
    for name, source in (
        ("AGENTS.md", entrypoint),
        ("docs/workflow.md", workflow),
        (".github/pull_request_template.md", template),
    ):
        for clause in ("merge", "Issue", "closed as completed", "FINALIZED"):
            if clause not in source:
                raise AssertionError(f"{name} lost task-completion contract: {clause}")
    if "Closes #N" not in workflow or "Closes #N" not in template:
        raise AssertionError("Governed PRs must be directed to link the same Issue for closure")

    # General governance reads Issue identity but never fetches lifecycle comments.
    fetched = []
    class IssueOnlyClient:
        def __init__(self, repository, token):
            if (repository, token) != ("yoohwz/yoohw-vietnam-store-tools", "token"):
                raise AssertionError("Wrong GitHub client scope")

        def get(self, path):
            fetched.append(path)
            return issue_48

        def get_all(self, path):
            raise AssertionError(f"Post-cutover governance read comments: {path}")

    original_client = governance.GitHubClient
    governance.GitHubClient = IssueOnlyClient
    try:
        event = {
            "repository": {"full_name": "yoohwz/yoohw-vietnam-store-tools"},
            "pull_request": {
                **pull_request(pr_body(controlled=True), draft=False),
                "number": 7,
            },
        }
        expect_pass(governance.validate_event(event, "token"), "Issue-only event validation")
    finally:
        governance.GitHubClient = original_client
    if fetched != ["/issues/48"]:
        raise AssertionError(f"Unexpected GitHub reads: {fetched}")

    # The admitted governance amendment continues under its old gate.
    vst_47 = pull_request(pr_body(task="VST-47", controlled=True), draft=True)
    issue_47 = {"number": 47, "state": "open"}
    plan_handoff = comment("STATUS: PLAN_REVIEW_REQUIRED")
    plan_approved = comment("PLAN REVIEW: APPROVED — implementation may proceed")
    plan_changes = comment("PLAN REVIEW: CHANGES REQUIRED")
    expect_error(
        governance.validate_pull_request(vst_47, task_issue=issue_47),
        "PLAN_REVIEW_REQUIRED",
    )
    expect_error(
        governance.validate_pull_request(
            vst_47, task_issue=issue_47, task_comments=[plan_handoff, plan_changes]
        ),
        "PLAN_REVIEW_REQUIRED",
    )
    expect_error(
        governance.validate_pull_request(
            vst_47,
            task_issue=issue_47,
            task_comments=[plan_handoff, comment(plan_approved["body"], "CONTRIBUTOR")],
        ),
        "PLAN_REVIEW_REQUIRED",
    )
    expect_error(
        governance.validate_pull_request(
            vst_47,
            task_issue=issue_47,
            task_comments=[
                plan_handoff,
                comment("WORKFLOW ARTIFACT: INVALIDATED\n" + plan_approved["body"]),
            ],
        ),
        "PLAN_REVIEW_REQUIRED",
    )
    expect_pass(
        governance.validate_pull_request(
            vst_47, task_issue=issue_47, task_comments=[plan_handoff, plan_approved]
        ),
        "approved VST-47 draft",
    )
    expect_error(
        governance.validate_pull_request(
            pull_request(pr_body(task="VST-47", fast=True), draft=True),
            task_issue=issue_47,
        ),
        "must remain Controlled",
    )
    expect_error(
        governance.validate_pull_request(
            pull_request(pr_body(task="VST-47", controlled=True), draft=True, base=OLD_HEAD),
            task_issue=issue_47,
        ),
        "admitted base",
    )
    different_branch = pull_request(pr_body(task="VST-47", controlled=True), draft=True)
    different_branch["head"]["ref"] = "agent/other-task"
    expect_error(
        governance.validate_pull_request(different_branch, task_issue=issue_47),
        "task branch",
    )

    ready_47 = pull_request(pr_body(task="VST-47", controlled=True), draft=False)
    current_handoff = comment(f"STATUS: TECHNICAL_REVIEW_REQUIRED\nHead SHA: {HEAD}")
    stale_handoff = comment(f"STATUS: TECHNICAL_REVIEW_REQUIRED\nHead SHA: {OLD_HEAD}")
    changes_required = comment(f"STATUS: TECHNICAL_CHANGES_REQUIRED\nHead SHA: {HEAD}")
    ready_result = comment(f"STATUS: READY_FOR_HUMAN_MERGE\nHead SHA: {HEAD}")
    plan = [plan_handoff, plan_approved]
    expect_error(
        governance.validate_pull_request(ready_47, task_issue=issue_47, task_comments=plan),
        "current technical status",
    )
    expect_error(
        governance.validate_pull_request(
            ready_47, [stale_handoff], task_issue=issue_47, task_comments=plan
        ),
        "stale",
    )
    expect_error(
        governance.validate_pull_request(
            ready_47,
            [comment(current_handoff["body"], "CONTRIBUTOR")],
            task_issue=issue_47,
            task_comments=plan,
        ),
        "current technical status",
    )
    expect_error(
        governance.validate_pull_request(
            ready_47, [changes_required], task_issue=issue_47, task_comments=plan
        ),
        "requires Codex corrections",
    )
    expect_pass(
        governance.validate_pull_request(
            ready_47, [current_handoff], task_issue=issue_47, task_comments=plan
        ),
        "current VST-47 handoff",
    )
    expect_error(
        governance.validate_pull_request(
            ready_47, [ready_result], task_issue=issue_47, task_comments=plan
        ),
        "must follow TECHNICAL_REVIEW_REQUIRED",
    )
    expect_pass(
        governance.validate_pull_request(
            ready_47, [current_handoff, ready_result], task_issue=issue_47, task_comments=plan
        ),
        "current VST-47 review result",
    )

    print(
        "Workflow governance contracts PASS: canonical Issue identity, mechanical "
        "post-cutover checks, and closed VST-47 current-workflow gates."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

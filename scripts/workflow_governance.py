#!/usr/bin/env python3
"""Validate mechanical PR identity and preserve VST-47's admitted workflow."""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Mapping, Sequence


ADMITTED_VST_47_BASE = "e54450fd545976f87458affd251551c454cf3aea"
ADMITTED_VST_47_HEAD_REF = "agent/vst-47-workflow"
LANE_PATTERN = re.compile(
    r"^\s*-\s*\[(?P<checked>[ xX])\]\s*(?P<lane>Fast|Controlled)\s*$",
    re.MULTILINE,
)
OWNER_PATTERN = re.compile(
    r"^\s*-\s*Implementation owner:\s*(?P<owner>.+?)\s*$",
    re.IGNORECASE | re.MULTILINE,
)
TASK_PATTERN = re.compile(
    r"^\s*-\s*Task\s*/\s*issue:\s*(?P<task>.+?)\s*$",
    re.IGNORECASE | re.MULTILINE,
)
CANONICAL_TASK_PATTERN = re.compile(
    r"(?:VST-(?P<vst>[1-9][0-9]*)(?:\s*\(#(?P<paired>[1-9][0-9]*)\))?"
    r"|#(?P<issue>[1-9][0-9]*))",
    re.IGNORECASE,
)

# Only the admitted governance amendment uses the old comment-derived gates.
# This clause is closed to every other Issue identity and admitted base.
PLAN_EVENT_PATTERN = re.compile(
    r"^\s*(?:#{1,6}\s*)?(?:STATUS:\s*)?"
    r"(?P<event>PLAN_REVIEW_REQUIRED|PLAN REVIEW:\s*APPROVED|PLAN REVIEW:\s*CHANGES REQUIRED)\b",
    re.IGNORECASE | re.MULTILINE,
)
TECHNICAL_STATUS_PATTERN = re.compile(
    r"^\s*(?:#{1,6}\s*)?STATUS:\s*"
    r"(?P<status>TECHNICAL_REVIEW_REQUIRED|TECHNICAL_CHANGES_REQUIRED|READY_FOR_HUMAN_MERGE)\s*$",
    re.IGNORECASE | re.MULTILINE,
)
HEAD_SHA_PATTERN = re.compile(
    r"^\s*(?:-\s*)?Head SHA:\s*`?(?P<sha>[0-9a-fA-F]{40})`?\s*$",
    re.IGNORECASE | re.MULTILINE,
)
INVALIDATED_COMMENT_PATTERN = re.compile(
    r"\A\s*(?:#{1,6}\s*)?(?:\*{1,2}|_{1,2})?"
    r"(?:(?:WORKFLOW\s+ARTIFACT:\s*)?(?:SUPERSEDED|INVALIDATED)\b|"
    r"NOT\s+A\s+VALID\s+WORKFLOW\s+GATE\b)",
    re.IGNORECASE,
)
TRUSTED_AUTHOR_ASSOCIATIONS = {"OWNER", "MEMBER", "COLLABORATOR"}


@dataclass(frozen=True)
class TechnicalEvent:
    status: str
    head_sha: str | None


def checked_lane(body: str) -> str | None:
    selected = [
        match.group("lane")
        for match in LANE_PATTERN.finditer(body)
        if match.group("checked").lower() == "x"
    ]
    return selected[0] if len(selected) == 1 else None


def implementation_owner(body: str) -> str | None:
    matches = OWNER_PATTERN.findall(body)
    return matches[0].strip() if len(matches) == 1 else None


def task_issue_number(body: str) -> int | None:
    matches = TASK_PATTERN.findall(body)
    if len(matches) != 1:
        return None
    match = CANONICAL_TASK_PATTERN.fullmatch(matches[0].strip())
    if match is None:
        return None
    number = match.group("vst") or match.group("issue")
    if match.group("paired") and match.group("paired") != number:
        return None
    return int(number)


def _trusted_comment_bodies(comments: Iterable[Mapping[str, object]]) -> Iterable[str]:
    for comment in comments:
        if comment.get("author_association", "") not in TRUSTED_AUTHOR_ASSOCIATIONS:
            continue
        body = comment.get("body", "")
        if isinstance(body, str) and not INVALIDATED_COMMENT_PATTERN.search(body):
            yield body


def _vst_47_plan_approved(comments: Iterable[Mapping[str, object]]) -> bool:
    state = "missing"
    for body in _trusted_comment_bodies(comments):
        for match in PLAN_EVENT_PATTERN.finditer(body):
            event = re.sub(r"\s+", " ", match.group("event").upper())
            if event == "PLAN_REVIEW_REQUIRED":
                state = "pending"
            elif event == "PLAN REVIEW: APPROVED" and state in {"pending", "approved"}:
                state = "approved"
            elif event == "PLAN REVIEW: CHANGES REQUIRED":
                state = "changes-required"
    return state == "approved"


def _vst_47_technical_events(
    comments: Iterable[Mapping[str, object]],
) -> list[TechnicalEvent]:
    events: list[TechnicalEvent] = []
    for body in _trusted_comment_bodies(comments):
        sha_match = HEAD_SHA_PATTERN.search(body)
        head_sha = sha_match.group("sha").lower() if sha_match else None
        for match in TECHNICAL_STATUS_PATTERN.finditer(body):
            events.append(TechnicalEvent(match.group("status").upper(), head_sha))
    return events


def _vst_47_current_workflow_errors(
    pull_request: Mapping[str, object],
    pr_comments: Sequence[Mapping[str, object]],
    task_comments: Sequence[Mapping[str, object]],
) -> list[str]:
    errors: list[str] = []
    if not _vst_47_plan_approved(task_comments):
        errors.append(
            "VST-47 requires PLAN_REVIEW_REQUIRED followed by the latest PLAN REVIEW: APPROVED on Issue #47."
        )
    if pull_request.get("draft") is not False:
        return errors

    head = pull_request.get("head", {})
    head_value = head.get("sha", "") if isinstance(head, Mapping) else ""
    head_sha = head_value.lower() if isinstance(head_value, str) else ""
    events = _vst_47_technical_events(pr_comments)
    if not events:
        errors.append("VST-47 ready PR needs a current technical status with Head SHA.")
        return errors
    latest = events[-1]
    if not head_sha or latest.head_sha != head_sha:
        errors.append("VST-47 technical status is missing Head SHA or is stale.")
    elif latest.status == "TECHNICAL_CHANGES_REQUIRED":
        errors.append("VST-47 technical review requires Codex corrections.")
    elif latest.status == "READY_FOR_HUMAN_MERGE" and not any(
        event.status == "TECHNICAL_REVIEW_REQUIRED" and event.head_sha == head_sha
        for event in events[:-1]
    ):
        errors.append("VST-47 READY_FOR_HUMAN_MERGE must follow TECHNICAL_REVIEW_REQUIRED for the same head.")
    return errors


def validate_pull_request(
    pull_request: Mapping[str, object],
    pr_comments: Sequence[Mapping[str, object]] = (),
    task_issue: Mapping[str, object] | None = None,
    task_comments: Sequence[Mapping[str, object]] = (),
) -> list[str]:
    """Validate one PR; historical comments matter only for admitted VST-47."""
    errors: list[str] = []
    body_value = pull_request.get("body", "")
    body = body_value if isinstance(body_value, str) else ""
    lane = checked_lane(body)
    if lane is None:
        errors.append("PR body must select exactly one risk lane: Fast or Controlled.")
    if implementation_owner(body) != "Codex":
        errors.append("PR body must declare exactly one `Implementation owner: Codex`.")

    issue_number = task_issue_number(body)
    if issue_number is None:
        errors.append("PR body must identify one canonical Task / issue: VST-N.")
    elif task_issue is None:
        errors.append(f"Unable to load canonical task Issue #{issue_number}.")
    elif "pull_request" in task_issue or task_issue.get("number") != issue_number:
        errors.append(f"Task identity must resolve to GitHub Issue #{issue_number}.")
    elif task_issue.get("state") != "open":
        errors.append(f"Canonical task Issue #{issue_number} must be open for an implementation PR.")

    if issue_number == 47:
        base = pull_request.get("base", {})
        base_sha = base.get("sha", "") if isinstance(base, Mapping) else ""
        base_ref = base.get("ref", "") if isinstance(base, Mapping) else ""
        head = pull_request.get("head", {})
        head_ref = head.get("ref", "") if isinstance(head, Mapping) else ""
        if (
            lane != "Controlled"
            or base_sha != ADMITTED_VST_47_BASE
            or base_ref != "main"
            or head_ref != ADMITTED_VST_47_HEAD_REF
        ):
            errors.append("VST-47 must remain Controlled on its admitted base and task branch.")
        elif task_issue is not None and not errors:
            errors.extend(
                _vst_47_current_workflow_errors(
                    pull_request, pr_comments, task_comments
                )
            )

    return errors


class GitHubClient:
    def __init__(self, repository: str, token: str) -> None:
        self.api_root = f"https://api.github.com/repos/{repository}"
        self.headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "User-Agent": "vst-workflow-governance",
            "X-GitHub-Api-Version": "2022-11-28",
        }

    def get(self, path: str) -> object:
        request = urllib.request.Request(f"{self.api_root}{path}", headers=self.headers)
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.load(response)

    def get_all(self, path: str) -> list[Mapping[str, object]]:
        items: list[Mapping[str, object]] = []
        page = 1
        while True:
            separator = "&" if "?" in path else "?"
            payload = self.get(f"{path}{separator}per_page=100&page={page}")
            if not isinstance(payload, list):
                raise ValueError(f"GitHub API returned a non-list payload for {path}")
            items.extend(item for item in payload if isinstance(item, Mapping))
            if len(payload) < 100:
                return items
            page += 1


def validate_event(event: Mapping[str, object], token: str) -> list[str]:
    pull_request = event.get("pull_request")
    if not isinstance(pull_request, Mapping):
        print("Workflow governance: non-pull-request event; artifact checks skipped.")
        return []

    repository = event.get("repository", {})
    repository_name = repository.get("full_name", "") if isinstance(repository, Mapping) else ""
    pr_number = pull_request.get("number") or event.get("number")
    if not isinstance(repository_name, str) or not repository_name:
        return ["GitHub event is missing repository.full_name."]
    if not isinstance(pr_number, int):
        return ["GitHub event is missing the pull request number."]
    if not token:
        return ["GITHUB_TOKEN is required for pull-request governance checks."]

    body_value = pull_request.get("body", "")
    body = body_value if isinstance(body_value, str) else ""
    issue_number = task_issue_number(body)
    if issue_number is None:
        return validate_pull_request(pull_request)

    client = GitHubClient(repository_name, token)
    issue_payload = client.get(f"/issues/{issue_number}")
    task_issue = issue_payload if isinstance(issue_payload, Mapping) else None
    pr_comments: Sequence[Mapping[str, object]] = ()
    task_comments: Sequence[Mapping[str, object]] = ()
    if issue_number == 47:
        pr_comments = client.get_all(f"/issues/{pr_number}/comments")
        task_comments = client.get_all("/issues/47/comments")
    return validate_pull_request(pull_request, pr_comments, task_issue, task_comments)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--event-path", required=True)
    args = parser.parse_args()
    try:
        event = json.loads(Path(args.event_path).read_text(encoding="utf-8"))
        errors = validate_event(event, os.environ.get("GITHUB_TOKEN", ""))
    except (OSError, ValueError, json.JSONDecodeError, urllib.error.URLError) as exc:
        print(f"Workflow governance FAILED: {exc}", file=sys.stderr)
        return 1
    if errors:
        for error in errors:
            print(f"Workflow governance FAILED: {error}", file=sys.stderr)
        return 1
    print("Workflow governance PASS: mechanical PR identity and applicable VST-47 transition.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

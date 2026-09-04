#!/usr/bin/env python3
"""Validate durable role-separated workflow evidence for pull requests."""

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
ISSUE_PATTERN = re.compile(r"(?:#|/issues/)(?P<number>[1-9][0-9]*)\b")
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
TRUSTED_AUTHOR_ASSOCIATIONS = {"OWNER", "MEMBER", "COLLABORATOR"}


@dataclass(frozen=True)
class TechnicalEvent:
    status: str
    head_sha: str | None


def checked_lane(body: str) -> str | None:
    """Return the single checked risk lane, or None when the form is invalid."""
    selected = [
        match.group("lane")
        for match in LANE_PATTERN.finditer(body)
        if match.group("checked").lower() == "x"
    ]
    return selected[0] if len(selected) == 1 else None


def implementation_owner(body: str) -> str | None:
    match = OWNER_PATTERN.search(body)
    return match.group("owner").strip() if match else None


def task_issue_number(body: str) -> int | None:
    task_match = TASK_PATTERN.search(body)
    if not task_match:
        return None
    issue_match = ISSUE_PATTERN.search(task_match.group("task"))
    return int(issue_match.group("number")) if issue_match else None


def _trusted_comment_bodies(
    comments: Iterable[Mapping[str, object]],
) -> Iterable[str]:
    for comment in comments:
        association = comment.get("author_association", "")
        if association not in TRUSTED_AUTHOR_ASSOCIATIONS:
            continue
        body = comment.get("body", "")
        if isinstance(body, str):
            yield body


def plan_gate_is_approved(comments: Iterable[Mapping[str, object]]) -> bool:
    """Require the latest plan result after a handoff to be an approval."""
    state = "missing"

    for body in _trusted_comment_bodies(comments):
        for match in PLAN_EVENT_PATTERN.finditer(body):
            event = re.sub(r"\s+", " ", match.group("event").upper())
            if event == "PLAN_REVIEW_REQUIRED":
                state = "pending"
            elif event == "PLAN REVIEW: APPROVED":
                if state in {"pending", "approved"}:
                    state = "approved"
            elif event == "PLAN REVIEW: CHANGES REQUIRED":
                state = "changes-required"

    return state == "approved"


def technical_events(
    comments: Iterable[Mapping[str, object]],
) -> list[TechnicalEvent]:
    events: list[TechnicalEvent] = []
    for body in _trusted_comment_bodies(comments):
        sha_match = HEAD_SHA_PATTERN.search(body)
        head_sha = sha_match.group("sha").lower() if sha_match else None
        for match in TECHNICAL_STATUS_PATTERN.finditer(body):
            events.append(
                TechnicalEvent(
                    status=match.group("status").upper(),
                    head_sha=head_sha,
                )
            )
    return events


def validate_pull_request(
    pull_request: Mapping[str, object],
    pr_comments: Sequence[Mapping[str, object]],
    task_issue: Mapping[str, object] | None = None,
    task_comments: Sequence[Mapping[str, object]] = (),
) -> list[str]:
    """Return blocking workflow-governance errors for one pull request."""
    errors: list[str] = []
    body_value = pull_request.get("body", "")
    body = body_value if isinstance(body_value, str) else ""
    lane = checked_lane(body)

    if lane is None:
        errors.append("PR body must select exactly one risk lane: Fast or Controlled.")

    owner = implementation_owner(body)
    if owner != "Codex":
        errors.append("PR body must declare `Implementation owner: Codex`.")

    if lane == "Controlled":
        issue_number = task_issue_number(body)
        if issue_number is None:
            errors.append("Controlled Lane PR must reference a durable GitHub task issue.")
        elif task_issue is None:
            errors.append(f"Unable to load task issue #{issue_number} for Controlled Lane validation.")
        elif "pull_request" in task_issue:
            errors.append("Controlled Lane planning anchor must be an issue, not another pull request.")
        elif not plan_gate_is_approved(task_comments):
            errors.append(
                "Controlled Lane task issue must contain PLAN_REVIEW_REQUIRED followed by the latest PLAN REVIEW: APPROVED result."
            )

    if pull_request.get("draft") is False:
        head = pull_request.get("head", {})
        head_sha_value = head.get("sha", "") if isinstance(head, Mapping) else ""
        head_sha = head_sha_value.lower() if isinstance(head_sha_value, str) else ""
        events = technical_events(pr_comments)

        if not events:
            errors.append(
                "Ready PR must have a STATUS: TECHNICAL_REVIEW_REQUIRED or READY_FOR_HUMAN_MERGE comment with Head SHA."
            )
        else:
            latest = events[-1]
            if latest.head_sha != head_sha:
                errors.append(
                    "Latest technical workflow status is missing Head SHA or is stale for the current PR head."
                )
            elif latest.status == "TECHNICAL_CHANGES_REQUIRED":
                errors.append(
                    "Latest technical workflow result requires Codex corrections before the PR can be ready."
                )
            elif latest.status == "READY_FOR_HUMAN_MERGE" and not any(
                event.status == "TECHNICAL_REVIEW_REQUIRED"
                and event.head_sha == head_sha
                for event in events[:-1]
            ):
                errors.append(
                    "READY_FOR_HUMAN_MERGE must follow TECHNICAL_REVIEW_REQUIRED for the same head SHA."
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
        request = urllib.request.Request(
            f"{self.api_root}{path}", headers=self.headers
        )
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
            page_items = [item for item in payload if isinstance(item, Mapping)]
            items.extend(page_items)
            if len(payload) < 100:
                return items
            page += 1


def validate_event(event: Mapping[str, object], token: str) -> list[str]:
    pull_request = event.get("pull_request")
    if not isinstance(pull_request, Mapping):
        print("Workflow governance: non-pull-request event; artifact checks skipped.")
        return []

    repository = event.get("repository", {})
    repository_name = (
        repository.get("full_name", "") if isinstance(repository, Mapping) else ""
    )
    pr_number = pull_request.get("number") or event.get("number")
    if not isinstance(repository_name, str) or not repository_name:
        return ["GitHub event is missing repository.full_name."]
    if not isinstance(pr_number, int):
        return ["GitHub event is missing the pull request number."]
    if not token:
        return ["GITHUB_TOKEN is required for pull-request governance checks."]

    client = GitHubClient(repository_name, token)
    pr_comments = client.get_all(f"/issues/{pr_number}/comments")
    body_value = pull_request.get("body", "")
    body = body_value if isinstance(body_value, str) else ""
    issue_number = task_issue_number(body)
    task_issue: Mapping[str, object] | None = None
    task_comments: Sequence[Mapping[str, object]] = ()

    if checked_lane(body) == "Controlled" and issue_number is not None:
        issue_payload = client.get(f"/issues/{issue_number}")
        if isinstance(issue_payload, Mapping):
            task_issue = issue_payload
        task_comments = client.get_all(f"/issues/{issue_number}/comments")

    return validate_pull_request(
        pull_request,
        pr_comments,
        task_issue=task_issue,
        task_comments=task_comments,
    )


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

    print("Workflow governance PASS: durable workflow artifacts match the current PR state.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

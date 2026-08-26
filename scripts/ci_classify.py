#!/usr/bin/env python3
"""Risk-aware CI change classifier for VST pull requests."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
from typing import Iterable


@dataclass(frozen=True)
class Classification:
    mode: str
    run_php: bool
    run_localization_quality: bool
    run_localization_runtime: bool
    run_plugin_check: bool

    def github_outputs(self) -> str:
        values = {
            "mode": self.mode,
            "run_php": self.run_php,
            "run_localization_quality": self.run_localization_quality,
            "run_localization_runtime": self.run_localization_runtime,
            "run_plugin_check": self.run_plugin_check,
        }
        return "\n".join(
            f"{key}={str(value).lower() if isinstance(value, bool) else value}"
            for key, value in values.items()
        )


FULL = Classification(
    mode="full",
    run_php=True,
    run_localization_quality=True,
    run_localization_runtime=True,
    run_plugin_check=True,
)


def _full(mode: str) -> Classification:
    return Classification(
        mode=mode,
        run_php=True,
        run_localization_quality=True,
        run_localization_runtime=True,
        run_plugin_check=True,
    )


def classify(
    event_name: str,
    pr_draft: bool,
    changed_paths: Iterable[str],
) -> Classification:
    """Return the minimum safe CI surface for the current change."""
    if event_name != "pull_request":
        return FULL

    paths = [path.strip() for path in changed_paths if path.strip()]
    if not paths:
        return _full("fail-safe-full")

    run_php = False
    run_localization_quality = False
    run_localization_runtime = False
    run_plugin_check = False
    force_deep = False

    for path in paths:
        if (
            path.startswith(".github/workflows/")
            or path.startswith("scripts/")
            or path == ".distignore"
        ):
            # CI, release tooling, and distribution-policy changes receive the
            # full deep suite once the PR moves to ready-for-review.
            force_deep = True

        if path == "docs/localization.md":
            run_localization_quality = True
            continue

        if (
            path == "AGENTS.md"
            or path == ".github/pull_request_template.md"
            or path.startswith("docs/")
            or path == ".gitignore"
        ):
            continue

        if path == "tests/localization-contract-tests.py":
            run_localization_quality = True
            continue

        if path in {
            "tests/runtime-localization-smoke.php",
            "tests/fixtures/vst-language-pack-sentinel.po",
            "tests/fixtures/vst-translation-monitor.php",
        }:
            run_localization_runtime = True
            continue

        if path.startswith("tests/"):
            # Contract/test-only changes are exercised by Repository contracts.
            continue

        if path.startswith("languages/"):
            run_localization_quality = True
            run_localization_runtime = True
            run_plugin_check = True
            continue

        if (
            path.startswith("includes/")
            or path.startswith("templates/")
            or path in {"yoohw-vietnam-store-tools.php", "index.php"}
        ):
            run_localization_quality = True
            run_plugin_check = True
            if path.endswith(".php"):
                run_php = True
            if path == "yoohw-vietnam-store-tools.php":
                run_localization_runtime = True
            continue

        if path.startswith("blocks/"):
            run_localization_quality = True
            run_plugin_check = True
            if path.endswith(".php"):
                run_php = True
            continue

        if path.startswith("assets/"):
            run_plugin_check = True
            if path.endswith(".js"):
                run_localization_quality = True
            continue

        if path.startswith("data/"):
            run_plugin_check = True
            continue

        if path in {"readme.txt", "changelog.txt", "changelog-vi.txt"}:
            run_plugin_check = True
            continue

        if (
            path.startswith(".github/workflows/")
            or path.startswith("scripts/")
            or path == ".distignore"
        ):
            continue

        # Unknown paths deliberately fail safe instead of guessing that a
        # quality gate is irrelevant once the PR is ready for review.
        force_deep = True

    # Draft is the implementation lane. Body/status edits do not alter CI
    # depth; ready_for_review is the single canonical transition to deep CI.
    if pr_draft:
        return Classification(
            mode="quick-draft",
            run_php=False,
            run_localization_quality=False,
            run_localization_runtime=False,
            run_plugin_check=False,
        )

    if force_deep:
        return _full("fail-safe-full")

    flags = (
        run_php,
        run_localization_quality,
        run_localization_runtime,
        run_plugin_check,
    )
    mode = "risk-matched" if any(flags) else "quick-safe"
    return Classification(
        mode=mode,
        run_php=run_php,
        run_localization_quality=run_localization_quality,
        run_localization_runtime=run_localization_runtime,
        run_plugin_check=run_plugin_check,
    )


def parse_bool(value: str) -> bool:
    return value.strip().lower() == "true"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--event-name", required=True)
    parser.add_argument("--pr-draft", default="false")
    parser.add_argument("paths", nargs="*")
    args = parser.parse_args()

    result = classify(
        event_name=args.event_name,
        pr_draft=parse_bool(args.pr_draft),
        changed_paths=args.paths,
    )
    print(result.github_outputs())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

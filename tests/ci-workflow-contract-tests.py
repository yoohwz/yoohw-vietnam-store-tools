#!/usr/bin/env python3
"""Contracts for the risk-aware VST CI workflow."""

from __future__ import annotations

import importlib.util
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASSIFIER_PATH = ROOT / "scripts/ci_classify.py"
WORKFLOW_PATH = ROOT / ".github/workflows/ci.yml"


def load_classifier():
    spec = importlib.util.spec_from_file_location("vst_ci_classify", CLASSIFIER_PATH)
    if spec is None or spec.loader is None:
        raise AssertionError("Unable to load scripts/ci_classify.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def assert_flags(result, *, php, quality, runtime, plugin, mode):
    actual = (
        result.run_php,
        result.run_localization_quality,
        result.run_localization_runtime,
        result.run_plugin_check,
        result.mode,
    )
    expected = (php, quality, runtime, plugin, mode)
    if actual != expected:
        raise AssertionError(f"Unexpected classification: actual={actual}, expected={expected}")


def main() -> int:
    classifier = load_classifier()
    workflow = WORKFLOW_PATH.read_text(encoding="utf-8")

    assert_flags(
        classifier.classify("push", False, "", []),
        php=True,
        quality=True,
        runtime=True,
        plugin=True,
        mode="full",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            True,
            "Implementation in progress",
            ["AGENTS.md", "docs/extension-contracts-1.1.5.md"],
        ),
        php=False,
        quality=False,
        runtime=False,
        plugin=False,
        mode="quick-draft",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            True,
            "Implementation in progress",
            ["includes/class-yoohw-address.php"],
        ),
        php=False,
        quality=False,
        runtime=False,
        plugin=False,
        mode="quick-draft",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            "",
            ["includes/class-yoohw-address.php"],
        ),
        php=True,
        quality=True,
        runtime=False,
        plugin=True,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            True,
            "STATUS: TECHNICAL_REVIEW_REQUIRED",
            ["includes/class-yoohw-address.php"],
        ),
        php=True,
        quality=True,
        runtime=False,
        plugin=True,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            "",
            ["languages/yoohw-vietnam-store-tools-vi.po"],
        ),
        php=False,
        quality=True,
        runtime=True,
        plugin=True,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            "",
            ["docs/localization.md"],
        ),
        php=False,
        quality=True,
        runtime=False,
        plugin=False,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            "",
            ["tests/localization-contract-tests.py"],
        ),
        php=False,
        quality=True,
        runtime=False,
        plugin=False,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            "",
            ["readme.txt", "changelog.txt"],
        ),
        php=False,
        quality=False,
        runtime=False,
        plugin=True,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            True,
            "Implementation in progress",
            [".github/workflows/ci.yml"],
        ),
        php=True,
        quality=True,
        runtime=True,
        plugin=True,
        mode="fail-safe-full",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            True,
            "Implementation in progress",
            ["unexpected-root-surface.txt"],
        ),
        php=True,
        quality=True,
        runtime=True,
        plugin=True,
        mode="fail-safe-full",
    )

    required_literals = (
        "ready_for_review",
        "converted_to_draft",
        "edited",
        "python3 scripts/ci_classify.py",
        "python3 tests/ci-workflow-contract-tests.py",
        "name: VST Required Gate",
        "scripts/localization-quality.sh check",
        "strict: true",
    )
    for literal in required_literals:
        if literal not in workflow:
            raise AssertionError(f"CI workflow is missing required contract: {literal}")

    for job_id in (
        "php-syntax-74",
        "php-syntax-82",
        "php-syntax-84",
        "localization-quality",
        "localization-runtime-63",
        "localization-runtime-67",
        "localization-runtime-latest",
        "plugin-check",
    ):
        match = re.search(
            rf"^  {re.escape(job_id)}:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
            workflow,
            re.MULTILINE | re.DOTALL,
        )
        if match is None:
            raise AssertionError(f"CI workflow is missing deep job {job_id}")
        body = match.group("body")
        if "repository-contracts" not in body or "changes" not in body:
            raise AssertionError(f"Deep job {job_id} must wait for the quick gate")

    for expected_name in (
        "PHP 7.4 syntax",
        "PHP 8.2 syntax",
        "PHP 8.4 syntax",
        "WordPress 6.3 translation runtime",
        "WordPress 6.7 translation runtime",
        "WordPress latest translation runtime",
        "Localization quality",
        "WordPress Plugin Check",
    ):
        if f"name: {expected_name}" not in workflow:
            raise AssertionError(
                f"Existing check context must remain stable for branch protection: {expected_name}"
            )

    print("CI workflow contracts PASS: risk-aware staging, fail-safe routing, and stable check contexts.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

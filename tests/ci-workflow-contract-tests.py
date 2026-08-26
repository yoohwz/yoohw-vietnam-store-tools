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
    classifier_source = CLASSIFIER_PATH.read_text(encoding="utf-8")
    workflow = WORKFLOW_PATH.read_text(encoding="utf-8")

    assert_flags(
        classifier.classify("push", False, []),
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
            ["includes/class-yoohw-address.php"],
        ),
        php=False,
        quality=False,
        runtime=False,
        plugin=False,
        mode="quick-draft",
    )
    # Even high-risk/fail-safe surfaces remain quick while implementation is
    # draft; ready_for_review is the only normal transition to deep CI.
    for draft_path in (".github/workflows/ci.yml", "unexpected-root-surface.txt"):
        assert_flags(
            classifier.classify("pull_request", True, [draft_path]),
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
            ["languages/yoohw-vietnam-store-tools-vi.po"],
        ),
        php=False,
        quality=True,
        runtime=True,
        plugin=True,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify("pull_request", False, ["docs/localization.md"]),
        php=False,
        quality=True,
        runtime=False,
        plugin=False,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify(
            "pull_request", False, ["tests/localization-contract-tests.py"]
        ),
        php=False,
        quality=True,
        runtime=False,
        plugin=False,
        mode="risk-matched",
    )
    assert_flags(
        classifier.classify("pull_request", False, ["readme.txt", "changelog.txt"]),
        php=False,
        quality=False,
        runtime=False,
        plugin=True,
        mode="risk-matched",
    )
    for full_path in (".github/workflows/ci.yml", "unexpected-root-surface.txt"):
        assert_flags(
            classifier.classify("pull_request", False, [full_path]),
            php=True,
            quality=True,
            runtime=True,
            plugin=True,
            mode="fail-safe-full",
        )

    required_literals = (
        "ready_for_review",
        "converted_to_draft",
        "python3 scripts/ci_classify.py",
        "python3 tests/ci-workflow-contract-tests.py",
        "name: VST Required Gate",
        "scripts/localization-quality.sh check",
        "strict: true",
    )
    for literal in required_literals:
        if literal not in workflow:
            raise AssertionError(f"CI workflow is missing required contract: {literal}")

    forbidden_literals = (
        "      - edited\n",
        "PR_BODY:",
        "--pr-body",
    )
    for literal in forbidden_literals:
        if literal in workflow:
            raise AssertionError(f"CI workflow contains duplicate-trigger contract: {literal!r}")
    if "TECHNICAL_REVIEW_REQUIRED" in classifier_source or "pr_body" in classifier_source:
        raise AssertionError("Classifier must not derive CI depth from PR body/status text")

    for job_id in (
        "php-syntax",
        "localization-quality",
        "localization-runtime",
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
        "PHP ${{ matrix.php }} syntax",
        "WordPress ${{ matrix.label }} translation runtime",
        "Localization quality",
        "WordPress Plugin Check",
    ):
        if f"name: {expected_name}" not in workflow:
            raise AssertionError(
                f"Existing check context template must remain stable: {expected_name}"
            )

    print(
        "CI workflow contracts PASS: single ready-for-review deep transition, "
        "risk-aware staging, fail-safe routing, and stable check contexts."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

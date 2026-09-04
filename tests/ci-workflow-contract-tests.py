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

    assert_flags(classifier.classify("push", False, []), php=True, quality=True, runtime=True, plugin=True, mode="full")
    assert_flags(classifier.classify("pull_request", True, ["AGENTS.md"]), php=False, quality=False, runtime=False, plugin=False, mode="quick-draft")
    assert_flags(classifier.classify("pull_request", True, ["includes/class-yoohw-address.php"]), php=False, quality=False, runtime=False, plugin=False, mode="quick-draft")
    for draft_path in (".github/workflows/ci.yml", "unexpected-root-surface.txt"):
        assert_flags(classifier.classify("pull_request", True, [draft_path]), php=False, quality=False, runtime=False, plugin=False, mode="quick-draft")
    assert_flags(classifier.classify("pull_request", False, ["includes/class-yoohw-address.php"]), php=True, quality=True, runtime=False, plugin=True, mode="risk-matched")
    assert_flags(classifier.classify("pull_request", False, ["languages/yoohw-vietnam-store-tools-vi.po"]), php=False, quality=True, runtime=True, plugin=True, mode="risk-matched")
    assert_flags(classifier.classify("pull_request", False, ["docs/localization.md"]), php=False, quality=True, runtime=False, plugin=False, mode="risk-matched")
    assert_flags(classifier.classify("pull_request", False, ["tests/localization-contract-tests.py"]), php=False, quality=True, runtime=False, plugin=False, mode="risk-matched")
    assert_flags(classifier.classify("pull_request", False, ["readme.txt", "changelog.txt"]), php=False, quality=False, runtime=False, plugin=True, mode="risk-matched")
    for full_path in (".github/workflows/ci.yml", "unexpected-root-surface.txt"):
        assert_flags(classifier.classify("pull_request", False, [full_path]), php=True, quality=True, runtime=True, plugin=True, mode="fail-safe-full")
    assert_flags(
        classifier.classify("pull_request", False, [".github/workflows/ci.yml"], "edited"),
        php=False,
        quality=False,
        runtime=False,
        plugin=False,
        mode="governance-metadata",
    )
    assert_flags(
        classifier.classify(
            "pull_request",
            False,
            [".github/workflows/ci.yml"],
            "edited",
            True,
        ),
        php=True,
        quality=True,
        runtime=True,
        plugin=True,
        mode="fail-safe-full",
    )

    for literal in (
        "      - edited\n",
        "ready_for_review",
        "converted_to_draft",
        "python3 scripts/ci_classify.py",
        "--event-action",
        "--base-changed",
        "name: Workflow governance",
        "python3 scripts/workflow_governance.py",
        "python3 tests/workflow-governance-contract-tests.py",
        "python3 tests/ci-workflow-contract-tests.py",
        "name: VST Required Gate",
        "scripts/localization-quality.sh check",
        "strict: true",
    ):
        if literal not in workflow:
            raise AssertionError(f"CI workflow is missing required contract: {literal}")

    for literal in ("PR_BODY:", "--pr-body"):
        if literal in workflow:
            raise AssertionError(f"CI workflow contains duplicate-trigger contract: {literal!r}")
    if "TECHNICAL_REVIEW_REQUIRED" in classifier_source or "pr_body" in classifier_source:
        raise AssertionError("Classifier must not derive CI depth from PR body/status text")

    governance_job = re.search(
        r"^  governance:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        workflow,
        re.MULTILINE | re.DOTALL,
    )
    if governance_job is None:
        raise AssertionError("CI workflow is missing the governance job")
    if "issues: read" not in workflow or "pull-requests: read" not in workflow:
        raise AssertionError("Workflow governance must have read-only issue and PR access")

    repository_job = re.search(
        r"^  repository-contracts:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        workflow,
        re.MULTILINE | re.DOTALL,
    )
    if repository_job is None or "      - governance\n" not in repository_job.group("body"):
        raise AssertionError("Repository contracts must wait for workflow governance")

    required_job = re.search(
        r"^  required-gate:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        workflow,
        re.MULTILINE | re.DOTALL,
    )
    if required_job is None or '[[ "$GOVERNANCE_RESULT" == "success" ]]' not in required_job.group("body"):
        raise AssertionError("VST Required Gate must enforce workflow governance")

    deep_jobs = (
        "php-syntax-74",
        "php-syntax-82",
        "php-syntax-84",
        "localization-quality",
        "localization-runtime-63",
        "localization-runtime-67",
        "localization-runtime-latest",
        "plugin-check",
    )
    for job_id in deep_jobs:
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
            raise AssertionError(f"Stable check context is missing: {expected_name}")

    if "name: PHP ${{ matrix.php }} syntax" in workflow or "name: WordPress ${{ matrix.label }} translation runtime" in workflow:
        raise AssertionError("Conditional deep checks must use explicit job names so skipped runs preserve legacy contexts")

    print("CI workflow contracts PASS: head-bound governance, single ready-for-review transition, explicit skipped check contexts, fail-safe routing, and quick-before-deep staging.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

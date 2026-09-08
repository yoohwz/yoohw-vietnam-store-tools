#!/usr/bin/env python3
"""Repository-level metadata and JSON contracts for Vietnam Store Toolkit."""

from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
# Update with readme.txt only on a reviewed release branch or during an
# explicitly Human-authorized GA reconciliation.
PUBLISHED_STABLE_VERSION = "1.1.6"
CHANGELOG_HISTORY_BASELINE = (
    "1.1.6", "1.1.5", "1.1.4", "1.1.3", "1.1.2", "1.1.1", "1.1.0", "1.0.2", "1.0.1", "1.0.0",
)
# SHA-256 of each stripped release section, including its heading/date. The
# 1.1.5 values come from published tag 1.1.5; the 1.1.6 values lock the exact
# reviewed release candidate and must be re-reviewed if its date/content moves.
RELEASE_SECTION_DIGESTS = {
    "1.1.6": {
        "changelog.txt": "1e8b2092abc9ac77c0e1de0097e14956f73a4c47c4577a75aca6775973f6d199",
        "changelog-vi.txt": "19f0f30cd57c0baa8d8392af9398ebf0e98c690fe17786a2f6879b480b6f3a63",
    },
    "1.1.5": {
        "changelog.txt": "26f262ac32031ec742bbf07c792f3dd8989db191d9d2593149d0a172d790c0ed",
        "changelog-vi.txt": "72bc8a636cfe980e4a35329e8529e820754a8557fc370de2d7b6108281f68c2e",
    },
}


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require_match(pattern: str, text: str, label: str, flags: int = 0) -> str:
    match = re.search(pattern, text, flags)
    if not match:
        raise AssertionError(f"Unable to determine {label}")
    return match.group(1).strip()


def parse_semver(version: str, label: str) -> tuple[int, int, int]:
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise AssertionError(f"{label} must be a semantic x.y.z version: {version}")
    return tuple(int(part) for part in version.split("."))


def validate_stable_tag(
    stable_tag: str, development_version: str, expected_stable: str
) -> None:
    stable_parts = parse_semver(stable_tag, "readme stable tag")
    development_parts = parse_semver(development_version, "development version")
    parse_semver(expected_stable, "expected published stable version")

    if stable_parts > development_parts:
        raise AssertionError(
            "Published stable tag cannot be newer than the development version: "
            f"stable={stable_tag}, development={development_version}"
        )
    if stable_tag != expected_stable:
        raise AssertionError(
            "Published stable tag does not match the release-stage expectation: "
            f"stable={stable_tag}, expected={expected_stable}"
        )


def require_stable_tag_rejection(
    stable_tag: str, development_version: str, expected_stable: str
) -> None:
    try:
        validate_stable_tag(stable_tag, development_version, expected_stable)
    except AssertionError:
        return
    raise AssertionError(f"Stable-tag contract unexpectedly accepted {stable_tag}")


def changelog_sections(text: str) -> list[tuple[str, str, str]]:
    """Return ordered (version, date/marker, complete section) entries."""
    headings = list(re.finditer(
        r"^= ([0-9]+\.[0-9]+\.[0-9]+) \(([^\n]+)\) =$", text, re.MULTILINE
    ))
    return [
        (heading[1], heading[2], text[heading.start():end].strip())
        for heading, end in zip(
            headings, [match.start() for match in headings[1:]] + [len(text)]
        )
    ]


def validate_changelog_history(
    readme: str, changelog: str, changelog_vi: str,
    development_version: str, stable_version: str,
) -> None:
    readme_parts = readme.split("== Changelog ==", 1)
    if len(readme_parts) != 2:
        raise AssertionError("readme.txt has no Changelog section")
    readme_sections = changelog_sections(readme_parts[1])
    if [section[0] for section in readme_sections] != [development_version]:
        raise AssertionError("readme.txt must expose exactly the current changelog version")
    if "See `changelog.txt` for the complete change history." not in readme_parts[1]:
        raise AssertionError("readme.txt must link the complete changelog history")

    english = changelog_sections(changelog)
    vietnamese = changelog_sections(changelog_vi)
    versions = [section[0] for section in english]
    if versions != [section[0] for section in vietnamese]:
        raise AssertionError("English and Vietnamese changelog version sequences differ")
    if not versions or versions[0] != development_version:
        raise AssertionError("Standalone changelogs must begin with the current version")
    if len(versions) != len(set(versions)) or any(
        parse_semver(earlier, "changelog version") <= parse_semver(later, "changelog version")
        for earlier, later in zip(versions, versions[1:])
    ):
        raise AssertionError("Changelog versions must be unique and descending")
    if any(version not in versions for version in (*CHANGELOG_HISTORY_BASELINE, stable_version)):
        raise AssertionError("Standalone changelogs lost required published history")

    developing = parse_semver(development_version, "development version") > parse_semver(
        stable_version, "published stable version"
    )
    for sections, marker, label in (
        (readme_sections, "In development", "readme.txt"),
        (english, "In development", "changelog.txt"),
        (vietnamese, "Đang phát triển", "changelog-vi.txt"),
    ):
        for index, (_, date, _) in enumerate(sections):
            if index == 0 and developing:
                if date != marker:
                    raise AssertionError(f"{label} must mark the current version as {marker}")
            elif not re.fullmatch(
                r"[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}" if label == "changelog-vi.txt"
                else r"[A-Z][a-z]+ [0-9]{1,2}, [0-9]{4}", date
            ):
                raise AssertionError(f"{label} published history must have a finalized date")

    for version, expected_digests in RELEASE_SECTION_DIGESTS.items():
        for label, sections in (("changelog.txt", english), ("changelog-vi.txt", vietnamese)):
            release_section = next(section[2] for section in sections if section[0] == version)
            actual_digest = hashlib.sha256(release_section.encode("utf-8")).hexdigest()
            if actual_digest != expected_digests[label]:
                raise AssertionError(f"{label} changed the locked {version} release section")


def exercise_history_contracts() -> None:
    """Reject representative release-boundary regressions without changing files."""
    readme = read("readme.txt")
    english = read("changelog.txt")
    vietnamese = read("changelog-vi.txt")
    validate_changelog_history(readme, english, vietnamese, "1.1.6", "1.1.6")

    release_en = next(section[2] for section in changelog_sections(english) if section[0] == "1.1.6")
    release_vi = next(section[2] for section in changelog_sections(vietnamese) if section[0] == "1.1.6")
    ga_en = next(section[2] for section in changelog_sections(english) if section[0] == "1.1.5")

    development_readme = (
        "== Changelog ==\n\n= 1.2.0 (In development) =\n\n* Future work.\n\n"
        "See `changelog.txt` for the complete change history."
    )
    validate_changelog_history(
        development_readme,
        "= 1.2.0 (In development) =\n\n* Future work.\n\n" + english,
        "= 1.2.0 (Đang phát triển) =\n\n* Công việc tương lai.\n\n" + vietnamese,
        "1.2.0",
        "1.1.6",
    )

    def reorder(text: str) -> str:
        return text.replace("= 1.1.4", "= SWAP").replace(
            "= 1.1.3", "= 1.1.4"
        ).replace("= SWAP", "= 1.1.3")

    def drop_oldest(text: str) -> str:
        return text.split("= 1.0.0", 1)[0]

    mutations = [
        ("older README entry", readme + "\n\n" + ga_en, english, vietnamese),
        ("missing history link", readme.replace("See `changelog.txt`", "See history"), english, vietnamese),
        ("lost release", readme, english.replace(release_en, ""), vietnamese.replace(release_vi, "")),
        ("duplicate release", readme, english + "\n\n" + release_en, vietnamese + "\n\n" + release_vi),
        ("lost older history", readme, drop_oldest(english), drop_oldest(vietnamese)),
        ("reordered history", readme, reorder(english), reorder(vietnamese)),
        ("locale mismatch", readme, english, vietnamese.replace("= 1.1.4", "= 1.1.7")),
        ("redated release", readme, english.replace("September 8, 2026", "September 9, 2026"), vietnamese),
        ("unfinalized release", readme, english.replace("September 8, 2026", "In development"), vietnamese),
        ("contaminated release", readme, english.replace(release_en, release_en + "\n* Extra change."), vietnamese),
        ("contaminated Vietnamese release", readme, english, vietnamese.replace(release_vi, release_vi + "\n* Thay đổi thêm.")),
    ]
    for label, candidate_readme, candidate_en, candidate_vi in mutations:
        try:
            validate_changelog_history(candidate_readme, candidate_en, candidate_vi, "1.1.6", "1.1.6")
        except AssertionError:
            continue
        raise AssertionError(f"History contract unexpectedly accepted {label}")
    validate_stable_tag("1.1.6", "1.1.6", "1.1.6")
    require_stable_tag_rejection("1.1.5", "1.1.6", "1.1.6")
    require_stable_tag_rejection("1.2.0", "1.1.6", "1.1.6")
    require_stable_tag_rejection("1.1.7", "1.1.6", "1.1.7")


def main() -> int:
    plugin = read("yoohw-vietnam-store-tools.php")
    readme = read("readme.txt")
    changelog = read("changelog.txt")
    changelog_vi = read("changelog-vi.txt")
    asset = read("blocks/order-tracking/index.asset.php")
    ci_workflow = read(".github/workflows/ci.yml")
    publish_workflow = read(".github/workflows/publish-wordpress-org.yml")

    plugin_version = require_match(
        r"^\s*\*\s*Version:\s*(\S+)\s*$",
        plugin,
        "plugin header version",
        re.MULTILINE,
    )
    fallback_version = require_match(
        r"\$plugin_version\s*=\s*isset\(\s*\$plugin_data\['Version'\]\s*\)\s*\?\s*\$plugin_data\['Version'\]\s*:\s*'([^']+)'\s*;",
        plugin,
        "plugin fallback version",
    )
    stable_tag = require_match(
        r"^Stable tag:\s*(\S+)\s*$", readme, "readme stable tag", re.MULTILINE
    )
    changelog_version = require_match(
        r"^=\s*([0-9]+(?:\.[0-9]+)+)\s*\(",
        changelog,
        "latest changelog version",
        re.MULTILINE,
    )
    changelog_vi_version = require_match(
        r"^=\s*([0-9]+(?:\.[0-9]+)+)\s*\(",
        changelog_vi,
        "latest Vietnamese changelog version",
        re.MULTILINE,
    )
    readme_changelog_version = require_match(
        r"^== Changelog ==\s*\n\s*=\s*([0-9]+(?:\.[0-9]+)+)\s*\(",
        readme,
        "latest readme changelog version",
        re.MULTILINE,
    )

    block_path = ROOT / "blocks/order-tracking/block.json"
    block = json.loads(block_path.read_text(encoding="utf-8"))
    block_version = str(block.get("version", "")).strip()
    if not block_version:
        raise AssertionError("blocks/order-tracking/block.json has no version")

    asset_version = require_match(
        r"['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]",
        asset,
        "block asset version",
    )

    translation_catalogs = (
        "languages/yoohw-vietnam-store-tools.pot",
        "languages/yoohw-vietnam-store-tools-vi.po",
        "languages/yoohw-vietnam-store-tools-vi_VN.po",
    )
    translation_versions = {
        path: require_match(
            r"Project-Id-Version:\s*Vietnam Store Toolkit for WooCommerce\s+(\S+)\\n",
            read(path),
            f"{path} project version",
        )
        for path in translation_catalogs
    }
    for locale in ("vi", "vi_VN"):
        path = f"languages/yoohw-vietnam-store-tools-{locale}.l10n.php"
        translation_versions[path] = require_match(
            r"['\"]project-id-version['\"]\s*=>\s*['\"]Vietnam Store Toolkit for WooCommerce\s+([0-9]+(?:\.[0-9]+)+)['\"]",
            read(path),
            f"{locale} PHP translation catalog project version",
        )

    development_versions = {
        "plugin header": plugin_version,
        "plugin fallback": fallback_version,
        "latest changelog": changelog_version,
        "latest Vietnamese changelog": changelog_vi_version,
        "latest readme changelog": readme_changelog_version,
        "block.json": block_version,
        "index.asset.php": asset_version,
        **translation_versions,
    }
    if len(set(development_versions.values())) != 1:
        details = ", ".join(
            f"{name}={value}" for name, value in development_versions.items()
        )
        raise AssertionError(f"Development version sources are inconsistent: {details}")

    validate_stable_tag(stable_tag, plugin_version, PUBLISHED_STABLE_VERSION)
    validate_changelog_history(readme, changelog, changelog_vi, plugin_version, stable_tag)
    exercise_history_contracts()
    require_stable_tag_rejection("1.1", plugin_version, "1.1")
    plugin_parts = parse_semver(plugin_version, "development version")
    future_version = ".".join(
        str(part) for part in (*plugin_parts[:2], plugin_parts[2] + 1)
    )
    require_stable_tag_rejection(future_version, plugin_version, future_version)

    project_header = (
        f"Project-Id-Version: Vietnam Store Toolkit for WooCommerce {plugin_version}"
    ).encode()
    for path in (
        "languages/yoohw-vietnam-store-tools-vi.mo",
        "languages/yoohw-vietnam-store-tools-vi_VN.mo",
    ):
        if project_header not in (ROOT / path).read_bytes():
            raise AssertionError(f"{path} has an inconsistent project version")

    metadata_pairs = {
        "Requires at least": "Requires at least",
        "Requires PHP": "Requires PHP",
        "WC requires at least": "WC requires at least",
        "WC tested up to": "WC tested up to",
    }
    for plugin_label, readme_label in metadata_pairs.items():
        plugin_value = require_match(
            rf"^\s*\*\s*{re.escape(plugin_label)}:\s*(\S+)\s*$",
            plugin,
            f"plugin {plugin_label}",
            re.MULTILINE,
        )
        readme_value = require_match(
            rf"^{re.escape(readme_label)}:\s*(\S+)\s*$",
            readme,
            f"readme {readme_label}",
            re.MULTILINE,
        )
        if plugin_value != readme_value:
            raise AssertionError(
                f"Metadata mismatch for {plugin_label}: plugin={plugin_value}, readme={readme_value}"
            )

    ignored_parts = {".git", "vendor", "node_modules"}
    json_files = [
        path
        for path in ROOT.rglob("*.json")
        if not ignored_parts.intersection(path.parts)
    ]
    for path in json_files:
        with path.open("r", encoding="utf-8") as handle:
            json.load(handle)

    for workflow_name, workflow in (
        ("normal CI", ci_workflow),
        ("WordPress.org publish validation", publish_workflow),
    ):
        if "scripts/localization-quality.sh check" not in workflow:
            raise AssertionError(f"{workflow_name} does not run the localization gate")
        if not re.search(r"^\s+strict:\s*true\s*$", workflow, re.MULTILINE):
            raise AssertionError(f"{workflow_name} does not run strict Plugin Check")
        for forbidden in ("ignore-codes:", "ignore-warnings:", "ignore-errors:"):
            if forbidden in workflow:
                raise AssertionError(f"{workflow_name} weakens Plugin Check with {forbidden}")

    for runtime_version in ("6.3", "6.7", "latest"):
        if runtime_version not in ci_workflow:
            raise AssertionError(
                f"Localization runtime matrix is missing WordPress {runtime_version}"
            )

    localization_position = publish_workflow.find("scripts/localization-quality.sh check")
    package_position = publish_workflow.find("Build exact WordPress.org ZIP")
    if localization_position < 0 or package_position < 0 or localization_position > package_position:
        raise AssertionError("Publish localization validation must run before package build")
    if not re.search(r"deploy:\n(?:.|\n)*?needs:\n\s+- validate-package", publish_workflow):
        raise AssertionError("WordPress.org deploy must require validate-package")

    print(
        f"Repository contracts PASS: development version {plugin_version}; "
        f"published stable {stable_tag}; "
        f"metadata synchronized; {len(json_files)} JSON file(s) valid."
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, json.JSONDecodeError, OSError) as exc:
        print(f"Repository contracts FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)

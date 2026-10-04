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
PUBLISHED_STABLE_VERSION = "1.2.1"
CHANGELOG_HISTORY_BASELINE = (
    "1.2.1",
    "1.2.0", "1.1.6", "1.1.5", "1.1.4", "1.1.3", "1.1.2", "1.1.1", "1.1.0", "1.0.2", "1.0.1", "1.0.0",
)
# SHA-256 of each stripped release section, including its heading/date.
# Values lock reviewed release sections, including the Human-authorized 1.2.1
# GA candidate; existing published history must remain unchanged.
RELEASE_SECTION_DIGESTS = {
    "1.2.1": {
        "changelog.txt": "b1dadca6f38b106236a1a3704c33a262a4c54c51c3688e69bd49bf664d88f0d7",
        "changelog-vi.txt": "ff482f3ef4457e37be3748256059ce59abf68cda2dc986c2ea9782b5c9e98c7c",
    },
    "1.2.0": {
        "changelog.txt": "e20fe57b5f3e989809085fa6fe016c97ce48e288ba971fed187cc77713d3e277",
        "changelog-vi.txt": "5f4accd5fa332e5ca62d4d637a02aca192865935e9a0063a1a6b5ade4f211f1d",
    },
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

    if readme_sections[0][1] != english[0][1]:
        raise AssertionError("readme.txt current changelog date must match changelog.txt")

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
    validate_changelog_history(readme, english, vietnamese, "1.2.1", "1.2.1")

    release_en = next(section[2] for section in changelog_sections(english) if section[0] == "1.2.1")
    release_vi = next(section[2] for section in changelog_sections(vietnamese) if section[0] == "1.2.1")

    published_english = english[english.index("= 1.2.1"):]
    published_vietnamese = vietnamese[vietnamese.index("= 1.2.1"):]

    development_readme = (
        "== Changelog ==\n\n= 1.2.2 (In development) =\n\n* Future work.\n\n"
        "See `changelog.txt` for the complete change history."
    )
    validate_changelog_history(
        development_readme,
        "= 1.2.2 (In development) =\n\n* Future work.\n\n" + published_english,
        "= 1.2.2 (Đang phát triển) =\n\n* Công việc tương lai.\n\n" + published_vietnamese,
        "1.2.2",
        "1.2.1",
    )

    def reorder(text: str) -> str:
        return text.replace("= 1.1.4", "= SWAP").replace(
            "= 1.1.3", "= 1.1.4"
        ).replace("= SWAP", "= 1.1.3")

    def drop_oldest(text: str) -> str:
        return text.split("= 1.0.0", 1)[0]

    mutations = [
        ("older README entry", readme + "\n\n" + release_en, english, vietnamese),
        ("missing history link", readme.replace("See `changelog.txt`", "See history"), english, vietnamese),
        ("lost release", readme, english.replace(release_en, ""), vietnamese.replace(release_vi, "")),
        ("duplicate release", readme, english + "\n\n" + release_en, vietnamese + "\n\n" + release_vi),
        ("lost older history", readme, drop_oldest(english), drop_oldest(vietnamese)),
        ("reordered history", readme, reorder(english), reorder(vietnamese)),
        ("locale mismatch", readme, english, vietnamese.replace("= 1.1.4", "= 1.1.7")),
        ("redated readme release", readme.replace("October 4, 2026", "October 5, 2026"), english, vietnamese),
        ("redated release", readme, english.replace("October 4, 2026", "October 5, 2026"), vietnamese),
        ("redated Vietnamese release", readme, english, vietnamese.replace("04/10/2026", "05/10/2026")),
        ("unfinalized readme release", readme.replace("October 4, 2026", "In development"), english, vietnamese),
        ("unfinalized release", readme, english.replace("October 4, 2026", "In development"), vietnamese),
        ("unfinalized Vietnamese release", readme, english, vietnamese.replace("04/10/2026", "Đang phát triển")),
        ("redated prior release", readme, english.replace("September 27, 2026", "September 28, 2026"), vietnamese),
        ("redated prior Vietnamese release", readme, english, vietnamese.replace("27/09/2026", "28/09/2026")),
        ("contaminated release", readme, english.replace(release_en, release_en + "\n* Extra change."), vietnamese),
        ("contaminated Vietnamese release", readme, english, vietnamese.replace(release_vi, release_vi + "\n* Thay đổi thêm.")),
    ]
    expected_failures = {
        "older README entry": "exactly the current changelog version",
        "missing history link": "link the complete changelog history",
        "lost release": "must begin with the current version",
        "duplicate release": "unique and descending",
        "lost older history": "lost required published history",
        "reordered history": "unique and descending",
        "locale mismatch": "version sequences differ",
        "redated readme release": "current changelog date must match",
        "redated release": "current changelog date must match",
        "redated Vietnamese release": "changed the locked 1.2.1 release section",
        "unfinalized readme release": "published history must have a finalized date",
        "unfinalized release": "published history must have a finalized date",
        "unfinalized Vietnamese release": "published history must have a finalized date",
        "redated prior release": "changed the locked 1.2.0 release section",
        "redated prior Vietnamese release": "changed the locked 1.2.0 release section",
        "contaminated release": "changed the locked 1.2.1 release section",
        "contaminated Vietnamese release": "changed the locked 1.2.1 release section",
    }
    for label, candidate_readme, candidate_en, candidate_vi in mutations:
        try:
            validate_changelog_history(candidate_readme, candidate_en, candidate_vi, "1.2.1", "1.2.1")
        except AssertionError as error:
            if expected_failures[label] not in str(error):
                raise AssertionError(f"History contract rejected {label} for the wrong reason: {error}") from error
            continue
        raise AssertionError(f"History contract unexpectedly accepted {label}")
    validate_stable_tag("1.2.1", "1.2.1", "1.2.1")
    require_stable_tag_rejection("1.1.6", "1.2.1", "1.2.1")
    require_stable_tag_rejection("1.2.0", "1.2.1", "1.2.1")
    require_stable_tag_rejection("1.2.2", "1.2.1", "1.2.1")
    require_stable_tag_rejection("1.1.7", "1.1.6", "1.1.7")


def external_asset_calls(source: str) -> list[list[str]]:
    """Read positional enqueue/register arguments, preserving nested PHP expressions."""
    tokens = re.findall(
        r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*[\s\S]*?\*/|//[^\n]*|\#[^\n]*|[A-Za-z_]\w*|[^\s]",
        source,
    )
    tokens = [token for token in tokens if not token.startswith(("/*", "//", "#"))]
    calls = []
    for index, token in enumerate(tokens[:-1]):
        if not re.fullmatch(r"wp_(enqueue|register)_(script|style)", token) or tokens[index + 1] != "(":
            continue
        arguments, current, depth = [], [], 0
        for part in tokens[index + 2:]:
            if part == ")" and depth == 0:
                arguments.append("".join(current))
                break
            if part == "," and depth == 0:
                arguments.append("".join(current))
                current = []
                continue
            if part in ("(", "[", "{"):
                depth += 1
            elif part in (")", "]", "}"):
                depth -= 1
            current.append(part)
        if len(arguments) > 1 and arguments[1] not in ("false", "null", "''", '""'):
            calls.append(arguments)
    return calls


def validate_asset_versions(source: str) -> list[str]:
    owned_paths = []
    for arguments in external_asset_calls(source):
        url = arguments[1]
        # Discover ownership from the source URL, including future handles/files.
        if "YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL" not in url and not re.search(r"assets/[^'\"]+\.(js|css)", url):
            continue
        if len(arguments) < 4 or arguments[3] != "YOOHW_VIETNAM_STORE_TOOLS_VERSION":
            raise AssertionError(f"Plugin asset version must use the runtime plugin version: {url}")
        owned_paths.extend(re.findall(r"assets/[^'\"]+\.(?:js|css)", url))
    return owned_paths


def exercise_asset_version_contracts() -> None:
    """Cover distributed PHP loaders and reject timestamp, literal and absent versions."""
    paths = []
    for name in ("includes", "templates", "blocks", "assets", "data", "languages"):
        for path in (ROOT / name).rglob("*.php"):
            paths.extend(validate_asset_versions(path.read_text(encoding="utf-8")))
    paths.extend(validate_asset_versions(read("yoohw-vietnam-store-tools.php")))
    corrected = {
        "assets/css/admin/shipping-rules.css", "assets/js/admin/shipping-rules.js",
        "assets/css/blocks-address-fields.css", "assets/js/frontend/blocks-address-fields.js",
        "assets/js/admin/bacs-vietqr.js", "assets/css/bacs-vietqr.css", "assets/js/bacs-vietqr-copy.js",
        "assets/css/admin/shipping-zones.css", "assets/js/admin/shipping-zones.js",
        "assets/css/admin/shipment-tracking.css", "assets/js/admin/shipment-tracking.js",
        "assets/css/shipment-tracking.css", "assets/js/frontend/paypal-vnd-usd.js",
    }
    if not corrected.issubset(paths):
        raise AssertionError(f"Corrected asset loaders missing from coverage: {corrected.difference(paths)}")
    for version in ("filemtime( $path )", "'1.2.0'", "false", "null"):
        for function in ("wp_enqueue_script", "wp_register_script", "wp_enqueue_style", "wp_register_style"):
            source = f"<?php {function}( 'future-handle', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/future.js', array( 'jquery', 'wp-data' ), {version} );"
            try:
                validate_asset_versions(source)
            except AssertionError:
                continue
            raise AssertionError(f"Asset version contract unexpectedly accepted {function}: {version}")
    for source in (
        "<?php wp_enqueue_script( 'future', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/future.js' );",
        "<?php wp_register_style( 'future', plugins_url( 'assets/future.css', __FILE__ ), [], filemtime( $path ) );",
    ):
        try:
            validate_asset_versions(source)
        except AssertionError:
            continue
        raise AssertionError("Asset contract accepted an unversioned or timestamped future loader")
    controls = """<?php
        // wp_enqueue_script( 'ignored', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/comment.js', [], filemtime( $path ) );
        wp_enqueue_style( 'dashicons' );
        wp_register_style( 'inline', false, [], 'unrelated' );
        wp_register_script( 'third-party', 'https://example.test/sdk.js', [], 'vendor-version' );
        wp_enqueue_script( 'future', YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/future.js', array( 'jquery', 'wp-data' ), YOOHW_VIETNAM_STORE_TOOLS_VERSION, true );
    """
    if validate_asset_versions(controls) != ["assets/future.js"]:
        raise AssertionError("Asset contract must preserve core/third-party/inline assets and ignore comments")


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
    exercise_asset_version_contracts()
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

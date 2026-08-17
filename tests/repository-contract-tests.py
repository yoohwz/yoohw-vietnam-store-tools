#!/usr/bin/env python3
"""Repository-level metadata and JSON contracts for Vietnam Store Toolkit."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require_match(pattern: str, text: str, label: str, flags: int = 0) -> str:
    match = re.search(pattern, text, flags)
    if not match:
        raise AssertionError(f"Unable to determine {label}")
    return match.group(1).strip()


def main() -> int:
    plugin = read("yoohw-vietnam-store-tools.php")
    readme = read("readme.txt")
    changelog = read("changelog.txt")
    asset = read("blocks/order-tracking/index.asset.php")

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

    versions = {
        "plugin header": plugin_version,
        "plugin fallback": fallback_version,
        "readme stable tag": stable_tag,
        "latest changelog": changelog_version,
        "block.json": block_version,
        "index.asset.php": asset_version,
    }
    if len(set(versions.values())) != 1:
        details = ", ".join(f"{name}={value}" for name, value in versions.items())
        raise AssertionError(f"Version sources are inconsistent: {details}")

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

    print(
        f"Repository contracts PASS: version {plugin_version}; "
        f"metadata synchronized; {len(json_files)} JSON file(s) valid."
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, json.JSONDecodeError, OSError) as exc:
        print(f"Repository contracts FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)

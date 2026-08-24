#!/usr/bin/env python3
"""Release-quality contracts for VST translation sources and UI providers."""

from __future__ import annotations

import ast
import hashlib
import json
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "yoohw-vietnam-store-tools"
LOCALES = ("vi", "vi_VN")
BLOCK_SOURCE = "blocks/order-tracking/index.js"

# These values are intentionally invariant in Vietnamese. Keep this list exact
# and reviewed; prose, sentences, and wildcard exemptions do not belong here.
UNCHANGED_ALLOWLIST = {
    "%1$s: %2$s → %3$s",
    "VietQR",
    "Vietnam Store Toolkit for WooCommerce",
    "YoOhw Studio",
    "https://vietnamstore.org/",
    "https://yoohw.com",
}


@dataclass
class PoEntry:
    context: str = ""
    msgid: str = ""
    msgid_plural: str = ""
    msgstr: dict[int, str] = field(default_factory=dict)
    flags: set[str] = field(default_factory=set)
    references: set[str] = field(default_factory=set)
    obsolete: bool = False

    @property
    def key(self) -> tuple[str, str, str]:
        return (self.context, self.msgid, self.msgid_plural)


def fail(message: str) -> None:
    raise AssertionError(message)


def parse_po(path: Path) -> list[PoEntry]:
    entries: list[PoEntry] = []
    entry = PoEntry()
    current: tuple[str, int] | None = None
    touched = False

    def finish() -> None:
        nonlocal entry, current, touched
        if touched:
            entries.append(entry)
        entry = PoEntry()
        current = None
        touched = False

    def decoded(value: str) -> str:
        return ast.literal_eval(value)

    for raw_line in path.read_text(encoding="utf-8").splitlines() + [""]:
        line = raw_line
        if line.startswith("#~"):
            entry.obsolete = True
            line = line[2:].lstrip()
        if not line:
            finish()
            continue
        touched = True
        if line.startswith("#,"):
            entry.flags.update(flag.strip() for flag in line[2:].split(","))
            continue
        if line.startswith("#:"):
            entry.references.update(line[2:].strip().split())
            continue
        match = re.match(r"^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(\".*\")$", line)
        if match:
            name = match.group(1)
            index = int(match.group(2) or 0)
            value = decoded(match.group(3))
            current = (name, index)
            if name == "msgctxt":
                entry.context = value
            elif name == "msgid":
                entry.msgid = value
            elif name == "msgid_plural":
                entry.msgid_plural = value
            else:
                entry.msgstr[index] = value
            continue
        if line.startswith('"') and current:
            value = decoded(line)
            name, index = current
            if name == "msgctxt":
                entry.context += value
            elif name == "msgid":
                entry.msgid += value
            elif name == "msgid_plural":
                entry.msgid_plural += value
            else:
                entry.msgstr[index] = entry.msgstr.get(index, "") + value
    return entries


def active_messages(path: Path) -> dict[tuple[str, str, str], PoEntry]:
    return {
        entry.key: entry
        for entry in parse_po(path)
        if not entry.obsolete and entry.msgid
    }


def po_header(path: Path) -> dict[str, str]:
    header = next(entry for entry in parse_po(path) if not entry.obsolete and not entry.msgid)
    lines = header.msgstr.get(0, "").splitlines()
    return {
        key: value.strip()
        for key, value in (line.split(":", 1) for line in lines if ":" in line)
    }


def validate_catalogs() -> None:
    pot_path = ROOT / "languages" / f"{DOMAIN}.pot"
    pot = active_messages(pot_path)
    if not pot:
        fail("POT has no active messages")

    catalogs: dict[str, dict[tuple[str, str, str], PoEntry]] = {}
    for locale in LOCALES:
        po_path = ROOT / "languages" / f"{DOMAIN}-{locale}.po"
        header = po_header(po_path)
        if header.get("Language") != locale:
            fail(f"{po_path.name} Language header must be {locale}")
        catalog = active_messages(po_path)
        obsolete = [entry.key for entry in parse_po(po_path) if entry.obsolete and entry.msgid]
        if obsolete:
            fail(f"{locale} has obsolete translations: {obsolete[:3]}")
        catalogs[locale] = catalog
        if set(catalog) != set(pot):
            missing = sorted(set(pot) - set(catalog))
            extra = sorted(set(catalog) - set(pot))
            fail(f"{locale} active set differs from POT; missing={missing[:3]} extra={extra[:3]}")
        for key, entry in catalog.items():
            if "fuzzy" in entry.flags:
                fail(f"{locale} has fuzzy translation: {key}")
            if not entry.msgstr or any(not value.strip() for value in entry.msgstr.values()):
                fail(f"{locale} has untranslated value: {key}")
            sources = {key[1], key[2]} - {""}
            for value in entry.msgstr.values():
                if value in sources and value not in UNCHANGED_ALLOWLIST:
                    fail(f"{locale} has unreviewed unchanged translation: {value!r}")

    vi_values = {key: entry.msgstr for key, entry in catalogs["vi"].items()}
    vi_vn_values = {key: entry.msgstr for key, entry in catalogs["vi_VN"].items()}
    if vi_values != vi_vn_values:
        drift = [key for key in vi_values if vi_values[key] != vi_vn_values.get(key)]
        fail(f"vi and vi_VN translations drift at: {drift[:5]}")


def provider_keys(path: Path) -> set[str]:
    source = path.read_text(encoding="utf-8")
    return set(
        re.findall(
            rf"['\"]([A-Za-z][A-Za-z0-9_]*)['\"]\s*=>\s*(?:esc_html__|esc_attr__|__)\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]{re.escape(DOMAIN)}['\"]",
            source,
        )
    )


def script_keys(path: Path, pattern: str) -> set[str]:
    return set(re.findall(pattern, path.read_text(encoding="utf-8")))


def validate_visible_javascript_contracts() -> None:
    contracts = (
        (
            "assets/js/admin/bacs-vietqr.js",
            "includes/class-vietnam-commerce-kit-bacs-vietqr.php",
            r"\bi18n\.([A-Za-z][A-Za-z0-9_]*)",
        ),
        (
            "assets/js/admin/devvn-migration-tools.js",
            "includes/class-vietnam-commerce-kit-devvn-migration-tools.php",
            r"getString\(\s*['\"]([A-Za-z][A-Za-z0-9_]*)['\"]",
        ),
        (
            "assets/js/admin/shipping-rules.js",
            "includes/class-vietnam-commerce-kit-shipping-rules.php",
            r"getString\(\s*['\"]([A-Za-z][A-Za-z0-9_]*)['\"]",
        ),
        (
            "assets/js/frontend/blocks-address-fields.js",
            "includes/class-vietnam-commerce-kit-blocks-integration.php",
            r"params\.i18n\.([A-Za-z][A-Za-z0-9_]*)",
        ),
    )
    for js_name, php_name, pattern in contracts:
        required = script_keys(ROOT / js_name, pattern)
        provided = provider_keys(ROOT / php_name)
        missing = sorted(required - provided)
        if not required:
            fail(f"No localized UI keys discovered in {js_name}")
        if missing:
            fail(f"{php_name} does not localize {js_name} keys: {missing}")


def validate_compiled_artifact_shape() -> None:
    source_hash = hashlib.md5(BLOCK_SOURCE.encode("utf-8")).hexdigest()
    pot = active_messages(ROOT / "languages" / f"{DOMAIN}.pot")
    js_ids = {
        entry.msgid
        for entry in pot.values()
        if any(reference.split(":", 1)[0] == BLOCK_SOURCE for reference in entry.references)
    }
    if len(js_ids) != 2:
        fail(f"Expected 2 block JavaScript messages, found {len(js_ids)}")

    for locale in LOCALES:
        for suffix in ("mo", "l10n.php"):
            path = ROOT / "languages" / f"{DOMAIN}-{locale}.{suffix}"
            if not path.is_file():
                fail(f"Missing compiled catalog: {path.name}")
        json_path = ROOT / "languages" / f"{DOMAIN}-{locale}-{source_hash}.json"
        if not json_path.is_file():
            fail(f"Missing block catalog: {json_path.name}")
        payload = json.loads(json_path.read_text(encoding="utf-8"))
        if payload.get("source") != BLOCK_SOURCE:
            fail(f"Incorrect JSON source in {json_path.name}")
        messages = payload.get("locale_data", {}).get("messages", {})
        metadata = messages.get("", {})
        if metadata.get("lang") != locale:
            fail(f"Incorrect JSON locale in {json_path.name}")
        if metadata.get("plural-forms") != "nplurals=1; plural=0;":
            fail(f"Incorrect JSON plural form in {json_path.name}")
        if set(messages) - {""} != js_ids:
            fail(f"JSON messages differ from active block messages in {json_path.name}")


def validate_bootstrap_contract() -> None:
    bootstrap = (ROOT / "yoohw-vietnam-store-tools.php").read_text(encoding="utf-8")
    if "load_plugin_textdomain(" in bootstrap:
        fail("Discouraged load_plugin_textdomain() call remains")
    required = (
        "$wp_textdomain_registry",
        "set_custom_path",
        "YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages'",
        "version_compare( (string) $wp_version, '6.5', '<' )",
        "prefer_wordpress_language_pack",
        "WP_LANG_DIR . '/plugins'",
    )
    for token in required:
        if token not in bootstrap:
            fail(f"Missing translation registry contract: {token}")


def main() -> int:
    try:
        validate_catalogs()
        validate_visible_javascript_contracts()
        validate_compiled_artifact_shape()
        validate_bootstrap_contract()
    except (AssertionError, ValueError, SyntaxError, StopIteration) as error:
        print(f"FAIL: {error}", file=sys.stderr)
        return 1
    print("PASS: localization source, parity, compiled-artifact, and visible-JS contracts.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

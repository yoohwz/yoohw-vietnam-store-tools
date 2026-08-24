#!/usr/bin/env bash
set -euo pipefail

mode="${1:-check}"
if [[ "$mode" != "check" && "$mode" != "update" ]]; then
	echo "Usage: $0 [check|update]" >&2
	exit 2
fi

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

wp_cli="${WP_CLI_BIN:-wp}"
domain="yoohw-vietnam-store-tools"
languages_dir="$root_dir/languages"
temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/vst-localization.XXXXXX")"
trap 'rm -rf "$temporary_dir"' EXIT

for command_name in "$wp_cli" msgattrib msgcmp msgfmt msgmerge python3; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Required localization tool is unavailable: $command_name" >&2
		exit 1
	fi
done

normalize_pot() {
	sed -E \
		-e '/^"POT-Creation-Date:/d' \
		-e '/^"X-Generator:/d' \
		"$1"
}

generated_pot="$temporary_dir/$domain.pot"
"$wp_cli" i18n make-pot . "$generated_pot" \
	--domain="$domain" \
	--exclude=.github,tests \
	--headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/yoohw-vietnam-store-tools"}' \
	--quiet

normalize_pot "$languages_dir/$domain.pot" > "$temporary_dir/committed.pot.normalized"
normalize_pot "$generated_pot" > "$temporary_dir/generated.pot.normalized"

if [[ "$mode" == "check" ]]; then
	diff -u "$temporary_dir/committed.pot.normalized" "$temporary_dir/generated.pot.normalized"
else
	cp "$generated_pot" "$languages_dir/$domain.pot"
	for locale in vi vi_VN; do
		msgmerge --update --backup=none --no-fuzzy-matching \
			"$languages_dir/$domain-$locale.po" \
			"$languages_dir/$domain.pot"
	done
fi

mkdir -p "$temporary_dir/php" "$temporary_dir/json"
for locale in vi vi_VN; do
	po_file="$languages_dir/$domain-$locale.po"
	base_name="$domain-$locale"

	msgcmp "$po_file" "$languages_dir/$domain.pot"
	msgattrib --translated --no-fuzzy --no-obsolete "$po_file" -o "$temporary_dir/$base_name.complete.po"
	msgcmp "$temporary_dir/$base_name.complete.po" "$languages_dir/$domain.pot"
	msgfmt --check --check-format -o "$temporary_dir/$base_name.mo" "$po_file"
	"$wp_cli" i18n make-php "$po_file" "$temporary_dir/php" --quiet
	"$wp_cli" i18n make-json "$po_file" "$temporary_dir/json" --no-purge --pretty-print --quiet

	if [[ "$mode" == "check" ]]; then
		cmp "$temporary_dir/$base_name.mo" "$languages_dir/$base_name.mo"
		cmp "$temporary_dir/php/$base_name.l10n.php" "$languages_dir/$base_name.l10n.php"
	else
		cp "$temporary_dir/$base_name.mo" "$languages_dir/$base_name.mo"
		cp "$temporary_dir/php/$base_name.l10n.php" "$languages_dir/$base_name.l10n.php"
	fi
done

expected_hash="$(python3 -c "import hashlib; print(hashlib.md5(b'blocks/order-tracking/index.js').hexdigest())")"
for locale in vi vi_VN; do
	json_name="$domain-$locale-$expected_hash.json"
	if [[ ! -f "$temporary_dir/json/$json_name" ]]; then
		echo "Expected generated block catalog is missing: $json_name" >&2
		exit 1
	fi
	if [[ "$mode" == "check" ]]; then
		cmp "$temporary_dir/json/$json_name" "$languages_dir/$json_name"
	else
		cp "$temporary_dir/json/$json_name" "$languages_dir/$json_name"
	fi
done

if find "$temporary_dir/json" -maxdepth 1 -type f ! -name "$domain-vi-$expected_hash.json" ! -name "$domain-vi_VN-$expected_hash.json" -print -quit | grep -q .; then
	echo "Unexpected JavaScript translation catalog was generated." >&2
	exit 1
fi

PYTHONDONTWRITEBYTECODE=1 python3 tests/localization-contract-tests.py
echo "PASS: deterministic localization quality gate ($mode)."

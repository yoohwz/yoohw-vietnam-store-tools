# Localization policy

Vietnamese localization must preserve product identity while translating the
ordinary interface clearly and consistently.

## Protected product and brand names

Product and brand names must retain their canonical spelling in every locale.
Do not translate labels such as `Vietnam store`,
`Vietnam Store Toolkit for WooCommerce`, or `YoOhw Studio` merely to increase
translation coverage. A brand change requires an explicit Human branding decision.

The release-quality contract in
`tests/localization-contract-tests.py` lists active canonical labels in
`PROTECTED_BRAND_LABELS`. Each listed label must exist in the POT and have an
identical `msgstr` in both `vi` and `vi_VN`. Add or change a label there only as
part of an approved branding change.

## Translation categories

- Translate ordinary user-facing prose into Vietnamese.
- Preserve protected product and brand names exactly.
- Preserve technical identifiers, standards, acronyms, provider names,
  placeholders, URLs, and WordPress/WooCommerce terms only when their exact
  wording is useful in context. Each unchanged source value must be explicitly
  reviewed and listed; wildcard exemptions are not allowed.

Embedded English technical terms are also context-bound. The
`REVIEWED_EMBEDDED_TECHNICAL_TERMS` map records the exact source sentences in
which terms such as `checkout`, `city`, `state`, `admin/frontend`, `metabox`,
and `Sort code` have been reviewed as WordPress/WooCommerce field, interface,
or banking terminology. New contexts must be reviewed explicitly.

## Workflow

Update source catalogs and generated MO, PHP, and JSON artifacts with the
pinned toolchain:

```sh
scripts/localization-quality.sh update
```

Before committing, run the read-only quality gate:

```sh
scripts/localization-quality.sh check
```

The gate enforces POT/PO completeness, zero fuzzy/untranslated/obsolete
entries, `vi`/`vi_VN` parity, protected brands, reviewed unchanged values,
compiled-artifact reproducibility, visible JavaScript providers, and this
policy contract.

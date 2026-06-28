# Vietnam Administrative Unit Data

This plugin bundles Vietnam province and ward/commune data so checkout forms do
not depend on a runtime network request.

## Source

Primary source:

- National Statistics Office of Viet Nam administrative units portal:
  https://danhmuchanhchinh.nso.gov.vn/

Bundled distribution used to generate this file:

- Vietnam Provinces API v2:
  https://provinces.open-api.vn/api/v2/?depth=2
- `vietnam-provinces` project:
  https://pypi.org/project/vietnam-provinces/

The `vietnam-provinces` project states that province and ward names/codes are
sourced from the National Statistics Office of Viet Nam.

The bundled file contains factual administrative unit identifiers and names
only. It does not redistribute executable source code from the upstream project.

## Bundle

- Data file: `vietnam-administrative-units.php`
- Legacy alias file: `vietnam-legacy-ward-aliases.php`
- Retrieved: 2026-06-21
- Format: 2026-07 two-level administrative units
- Province count: 34
- Ward/commune/special-zone count: 3,321

The legacy alias file was generated from `vietnam-provinces` 2026.3.0 ward
conversion data for the 2025 administrative changes. It contains exact,
non-partial pre-2025 ward-to-post-2025 ward mappings for migration tools and
marks ambiguous or partly merged pre-2025 wards as non-exact so they remain in
manual review.

Province codes are stored as zero-padded 2-character strings, such as `01`.
Ward/commune/special-zone codes are stored as zero-padded 5-character strings,
such as `00070`.

These values are administrative identifiers, not numeric quantities. Keeping
them as strings preserves leading zeroes, avoids duplicate-name ambiguity, and
gives future features a stable key for validation, imports, shipping rules, and
address normalization.

# VietQR Bank Data

This plugin bundles a VietQR bank list so the Bank Transfer settings can show a
bank selector and fill the VietQR bank BIN automatically.

## Source

- VietQR bank list API:
  https://api.vietqr.io/v2/banks
- VietQR API documentation:
  https://vietqr.io/danh-sach-api/api-danh-sach-ma-ngan-hang/

The VietQR documentation describes `bin` as the bank code used in VietQR Quick
Link generation.

The bundled file contains factual bank names, short names, bank codes, and
VietQR/NAPAS BIN identifiers only. It does not call the bank list API at runtime.

## Bundle

- Data file: `vietqr-banks.php`
- Retrieved: 2026-06-22
- Bank count: 65

Bank BIN values are stored as strings, such as `970436`, to preserve leading
zeroes if VietQR/NAPAS ever publishes identifiers that need them.

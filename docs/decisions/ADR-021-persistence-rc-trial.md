# ADR-021 — Shared Persistence RC trial

- **Decision ID:** ADR-021
- **Status:** ACTIVE
- **Date:** 2026-11-14
- **Decision Authority:** Package Owner.
- **Canonical Contract / Current Owner:** [Package Reference](../../I18N_PACKAGE_REFERENCE.md).

## Decision

Use the verified Packagist release `maatify/php-persistence:1.0.0-rc.1` at source revision `34c3b874525d36ae064c162cdd553bc75d18772b` for checkout trials of Ordering, Pagination, and Transactions. Keep stable minimum stability and pin the trial dependency to this exact release. Stable package qualification requires an eligible stable Persistence release.

The checkout uses the new public Pagination and Transaction types. Repository ordering distinguishes a missing target from a failed move against a row that remains present. Existing host-owned transactions remain caller-owned.

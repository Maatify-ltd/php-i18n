# ADR-023 — Pin the verified Persistence RC.2

- **Decision ID:** ADR-023
- **Status:** ACTIVE
- **Date:** 2026-11-18
- **Decision Authority:** Package Owner approved the exact Persistence RC.2 dependency and the I18n RC.3 target.
- **Scope:** Ordering, Pagination, and Transactions in `maatify/php-i18n`.
- **Canonical Contract / Current Owner:** [Package Reference](../../I18N_PACKAGE_REFERENCE.md).
- **Supersedes:** [ADR-021](ADR-021-persistence-rc-trial.md).

## Context

I18n RC.2 uses the first Persistence release candidate. Persistence RC.2 corrects statement failure handling in Ordering while preserving the host-selected PDO mode and transaction ownership.

## Decision

Pin `maatify/php-persistence:1.0.0-rc.2` exactly for I18n `1.0.0-rc.3`. Use the [published Packagist artifact](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2) at `71dc61548e48fd053376db69149a00bc87069b70`. Keep Composer minimum stability at `stable` and allow the exact RC in the consumer root. Stable package qualification still requires an eligible Stable Persistence release.

## Consequences

Scope and domain Ordering wrap the dependency's `OrderingExecutionException` in `I18nStorageException` with the original cause. Thrown PDO errors propagate unchanged. Host-owned transactions stay under Host control. Published I18n releases keep their immutable dependency constraints.

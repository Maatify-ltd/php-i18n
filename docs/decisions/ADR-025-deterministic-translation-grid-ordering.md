# ADR-025 — Deterministic translation grid ordering

- **Decision ID:** ADR-025
- **Status:** ACTIVE
- **Date:** 2026-11-20
- **Decision Authority:** [Package Owner approval in Issue #25](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7045693049)
- **Scope:** Translation grid pagination within a scope and domain.
- **Canonical Contract / Current Owner:** [Package Reference — Translation grid ordering](../../I18N_PACKAGE_REFERENCE.md#translation-grid-ordering).

## Context

I18n configured the grid to use `language_code` as a tie-breaker. When a caller also chose `language_code` as the primary sort key, Persistence RC.2 emitted that SQL identifier only once. On MySQL 8.4.6, independently requested pages over a static fixture returned duplicate cells and omitted others. [R4 reproduction](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7044443191)

## Decision

Configure the I18n grid to break ties with `key_part ASC` when the effective primary sort key is `language_code`, in either primary direction. The grid keeps `language_code ASC` as the tie-breaker for `key_part` sorting. Persistence handles request normalization, primary direction, fallback, and page bounds.

## Rationale

Within a scope and domain, the unique key constraint compares `key_part` using the same collation as the grid order. Exact deduplication of the requested codes and the unique translation identity yield one cell per key and requested code, including cells with a missing translation. The two existing sort keys can therefore provide a total order over a static grid. A nullable translation ID cannot distinguish ties among missing cells. Ordering by numeric key ID would select a different secondary order and need separate sort configuration.

## Consequences

A language-sorted grid can return tied cells in a different order and on different pages. The existing sort whitelist, primary directions, default sort, request and result types, JSON shape, and exact language identity continue to apply. The change requires no schema migration or dependency update. Offset pagination can still shift under concurrent changes.

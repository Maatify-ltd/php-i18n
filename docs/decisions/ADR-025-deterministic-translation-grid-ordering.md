# ADR-025 — Deterministic translation grid ordering

- **Decision ID:** ADR-025
- **Status:** ACTIVE
- **Date:** 2026-11-20
- **Decision Authority:** [Original Package Owner approval](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7045693049) and [P2 Package Owner approval](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7048018825)
- **Scope:** Translation grid pagination within a scope and domain.
- **Canonical Contract / Current Owner:** [Package Reference — Translation grid ordering](../../I18N_PACKAGE_REFERENCE.md#translation-grid-ordering).

## Context

I18n configured the grid to use `language_code` as a tie-breaker. When a caller also chose `language_code` as the primary sort key, Persistence RC.2 emitted that SQL identifier only once. On MySQL 8.4.6, independently requested pages over a static fixture returned duplicate cells and omitted others. [R4 reproduction](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7044443191)

The second sort key resolved language ties for short keys. On the default MySQL 8.4.6 configuration, `filesort` could still truncate the collation weights of long valid key parts at 1024 bytes. Different keys then compared equal during sorting, including when `key_part` was the primary sort key. [P2 reproduction and alternatives](https://github.com/Maatify-ltd/php-i18n/issues/25#issuecomment-7047038699)

## Decision

Configure the I18n grid to break ties with `key_part ASC` when the effective primary sort key is `language_code`, in either primary direction. The grid keeps `language_code ASC` as the tie-breaker for `key_part` sorting. Persistence handles request normalization, primary direction, fallback, and page bounds.

Set `max_sort_length=2048` with a fixed `SET_VAR` hint on the top-level grid data `SELECT` for all sort modes. Persistence appends the same ordering and page bounds. Count queries use the existing session settings.

## Rationale

Within a scope and domain, the unique key constraint compares `key_part` under `utf8mb4_unicode_ci`. Under the MySQL 8.4.6 UCA 4.0 implementation of that collation, at most eight two-byte weights are emitted per character before padding. The 128-character column therefore needs up to 2048 bytes of content weights. The hint prevents the configured sort limit from truncating those weights. Exact language-code deduplication and the unique translation identity yield one cell per key and requested code, including cells with a missing translation. [MySQL weight table](https://github.com/mysql/mysql-server/blob/mysql-8.4.6/strings/uca_data.h#L6430-L6447) · [MySQL sort length](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html#sysvar_max_sort_length)

A numeric tie-breaker can disambiguate the truncated weights but would order those keys by ID. A 4096-byte limit also restored the correct order in the study and required more sort-merge passes in one larger fixture. The 2048-byte limit covers the verified bound with less sort work in that fixture.

## Consequences

Page membership and row order can change where the old query compared distinct cells as equal, including in default and fallback sorts. The sort whitelist, directions, default sort, request and result types, JSON shape, and exact language identity continue to apply. The hint temporarily applies 2048 even if the Host selected a higher or lower setting. MySQL restores the Host setting when the statement ends. The Host retains ownership of its connection and transaction. Offset pagination can still shift under concurrent changes.

The bound and hint behavior are qualified for MySQL 8.4.6 and the current schema. Recheck both if the engine, collation or key-part length changes. [MySQL statement hints](https://dev.mysql.com/doc/refman/8.4/en/optimizer-hints.html#optimizer-hints-set-var)

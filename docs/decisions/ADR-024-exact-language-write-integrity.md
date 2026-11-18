# ADR-024 — Preserve exact language identity and serialize language writes

- **Decision ID:** ADR-024
- **Status:** ACTIVE
- **Date:** 2026-11-18
- **Decision Authority:** Package Owner approved the internal write-lock table and the RC.3 schema upgrade.
- **Scope:** Exact language storage and MySQL translation mutation boundaries.
- **Canonical Contract / Current Owner:** [Package Reference](../../I18N_PACKAGE_REFERENCE.md).
- **Related:** [ADR-019](ADR-019-host-owned-exact-language-code-in-i18n.md).

## Context

The prior binary collation ignored trailing spaces. A plain destination read could miss a committed translation under an older Host snapshot. Even a current read cannot lock an absent destination at READ COMMITTED through the translation table alone.

## Decision

Use MySQL `utf8mb4_0900_bin` for persisted language-code columns and their generated identities. Preserve valid UTF-8 codes exactly and reject codes that consist only of Unicode whitespace in both validation and storage.

Use an internal InnoDB table with at most 256 language write slots. Native upserts, deletes, and re-keys acquire the relevant row locks in slot order. The re-key checks the destination with a current locking read after acquiring both locks. Locks last for the owning transaction, including a Host-owned transaction. The native upsert distinguishes an insert from an update by the existing exact row under the write lock.

## Rationale and consequences

A fixed slot count bounds storage without a lock-row cleanup race. Slot collisions serialize some unrelated language codes. Writes through the package share the same locking boundary, including when the destination has no rows. The Host still owns language semantics and its transaction. Existing installations apply the additive lock table and collation changes before running RC.3.

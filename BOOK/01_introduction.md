# 01. Introduction

This chapter is conceptual. The canonical contract is the [Package Reference](../I18N_PACKAGE_REFERENCE.md).

## Library Identity

`maatify/php-i18n` is a kernel-grade internationalization subsystem for enterprise applications requiring strict governance, structured data, and high-performance runtime reads.

The library operates exclusively as a **database-driven** solution. It treats translation keys and values as relational entities with referential integrity. It does not support filesystem arrays (PHP/JSON) or key-value storage.

## Design Philosophy

The library implements four mandatory design pillars:

### 1. Governance-First
Key creation, renames and domain reads follow the configured scope and domain policy. The default `STRICT` mode requires an existing, active scope, an existing, active domain and an assignment. `PERMISSIVE` can allow a missing scope or domain during migration or development. See the [governance model](03_governance_model.md) for activity and assignment rules.

### 2. Structured Keys
Keys are structured hierarchical tuples: `scope.domain.key_part`. This structure is enforced by the database schema (unique constraint) and by the `TranslationWriteService`. Flat keys (e.g., `error_message`) are prohibited.

### 3. Fail-Hard Writes / Fail-Soft Reads
*   **Writes:** Key creation and renames throw typed exceptions on policy violations. Translation value mutations operate on a stored key ID.
*   **Runtime reads:** Expected misses return `null` or an empty DTO. A policy denial yields an empty domain result. Single-key reads look up the stored translation directly. Storage failures propagate.

### 4. Zero Implicit Magic
The library performs no auto-discovery or implicit loading. All state exists explicitly in the database. If a record is not in the database, it does not exist.

## Architectural Boundaries

*   **Repositories:** contracts under `Repository/`, implemented via `PDO` MySQL repositories (`Repository/Mysql/`).
*   **Services:** Strictly separated into `Read`, `Write`, and `Governance` responsibilities.
*   **Data Transport:** All data transfer occurs via strict DTOs. Arrays are not used for internal data passing.

## Non-Goals

*   **Filesystem Loading:** The library does not read `.php` or `.json` files.
*   **Frontend Asset Generation:** The library provides APIs to fetch translations but does not bundle them for clients.
*   **Framework Integration:** The Core uses independent services and contracts and needs no container or framework. The only adapter is the optional PHP-DI wiring (`Adapter\PhpDi\I18nBindings`), which is never required.
*   **Language Management:** Creating languages, text direction, active flags and fallback configuration are Host concerns. I18n only stores an exact, Host-owned `language_code` and never validates it semantically (see the [Package Reference](../I18N_PACKAGE_REFERENCE.md#4-language-code-contract)).

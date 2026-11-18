# 03. Governance Model

## Overview

`I18nGovernancePolicyService` defines which scope and domain pairs are allowed when creating or renaming a key and when reading a domain. The [Package Reference](../I18N_PACKAGE_REFERENCE.md#56-governance-policy-and-derived-state-services) defines the contract.

A policy denial throws a typed exception on key creation or rename and yields an empty result on a domain read. Single-key reads look up the stored translation independently of governance. Deactivating a scope or domain preserves existing keys and translations.

## 1. Scopes (`maa_i18n_scopes`)

Scopes define the top-level application boundaries.

In `STRICT` mode, the scope must exist and have `is_active=1`. The scope code is also part of the structured key identity.

## 2. Domains (`maa_i18n_domains`)

Domains represent functional areas within a scope.

In `STRICT` mode, the domain must exist and have `is_active=1`. A domain groups related translations for bulk loading.

## 3. Domain-Scope Mapping (`maa_i18n_domain_scopes`)

This table defines the permitted relationships between Scopes and Domains.

A domain may be assigned to multiple scopes. If `web` and `dashboard` both exist and are active, creating `web.dashboard.title` without their assignment throws `DomainScopeViolationException` in either mode. A domain read for that pair returns an empty result.

## 4. The Policy Service

`TranslationWriteService` uses the policy for key creation and renames. `TranslationDomainReadService` applies it for bulk reads.

### Modes
The service operates in two modes, controlled via `I18nPolicyModeEnum`:

- **`STRICT`** is the default production mode. The scope and domain must both exist and be active, and the domain must be assigned to the scope.
- **`PERMISSIVE`** is for migration and development. It allows a missing scope or domain, rejects any existing inactive scope or domain, and requires an assignment when both records exist.

### Usage in Code

```php
// Creating the service (via Dependency Injection)
$governance = new I18nGovernancePolicyService(
    $scopeRepo,
    $domainRepo,
    $domainScopeRepo,
    I18nPolicyModeEnum::STRICT
);

// Check the assignment for an existing, active scope and domain.
try {
    $governance->assertScopeAndDomainAllowed('admin', 'billing');
} catch (DomainScopeViolationException $e) {
    // Handle violation: "Domain 'billing' is not allowed for scope 'admin'"
}
```

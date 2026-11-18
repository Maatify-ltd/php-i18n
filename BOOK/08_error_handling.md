# 08. Error Handling

This chapter catalogs the main exceptions and error scenarios enforced by the library. The complete catalog is in the [Package Reference](../I18N_PACKAGE_REFERENCE.md#62-exception-catalog).

## 1. Fail-Hard Writes (Exceptions)

All write operations (Admin APIs, Setup Scripts) **must** enforce strict rules and data integrity. They throw explicitly typed exceptions that **must** be handled.

| Exception Class                        | Description                            | Typically Thrown By           |
|:---------------------------------------|:---------------------------------------|:------------------------------|
| `InvalidLanguageCodeException`         | Language code empty, whitespace or > 16 characters. | Commands, `TranslationWriteService` |
| `LanguageCodeAlreadyInUseException`    | Re-key target code already owns translations. | `TranslationWriteService` |
| `TranslationKeyNotFoundException`      | Key ID does not exist.                 | `TranslationWriteService`     |
| `TranslationKeyAlreadyExistsException` | Key `scope.domain.key` already exists. | `TranslationWriteService`     |
| `TranslationKeyCreateFailedException`  | The repository returned no valid key ID.            | `TranslationWriteService`     |
| `ScopeNotAllowedException`             | Scope is invalid or inactive.          | `I18nGovernancePolicyService` |
| `DomainNotAllowedException`            | Domain is invalid or inactive.         | `I18nGovernancePolicyService` |
| `DomainScopeViolationException`        | Domain is not mapped to Scope.         | `I18nGovernancePolicyService` |
| `TranslationUpsertFailedException`     | The repository returned no valid translation ID.     | `TranslationWriteService`     |

### Handling Example

```php
try {
    $writeService->createKey(new CreateKeyCommand('admin', 'billing', 'invoice.title'));
} catch (DomainScopeViolationException $e) {
    // Log: "Domain billing not allowed for admin scope"
    // Return 403 Forbidden
} catch (TranslationKeyAlreadyExistsException $e) {
    // Log: "Key invoice.title already exists"
    // Return 409 Conflict
} catch (Exception $e) {
    // Generic error (500)
}
```

## 2. Fail-Soft Reads (Nulls)

Runtime reads return `null` or an empty DTO for expected missing data. Storage failures propagate as described in the [Package Reference](../I18N_PACKAGE_REFERENCE.md#63-storage-failure-contract).

*   **Missing Key:** Returns `null`.
*   **Missing Translation:** Returns `null` (exact scope only, no fallback).
*   **Invalid Domain/Scope:** Returns empty `TranslationDomainValuesDTO` (`[]`).

**Handling Nulls:**
Application code **must** be robust to `null` returns.

```php
$text = $readService->getValue('en', 'web', 'home', 'welcome');
echo htmlspecialchars($text ?? 'Welcome', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
```

**Language codes:**
Reads never throw for a language code. An unknown but syntactically valid code simply owns no rows; an invalid code (empty / whitespace / > 16 chars) yields `null` / an empty DTO. Writes throw `InvalidLanguageCodeException` for an invalid code. I18n never throws a "language not found" error: language existence is Host policy.

# 08. Error Handling

This chapter catalogs the main exceptions and error scenarios enforced by the library. The complete catalog is in the [Package Reference](../I18N_PACKAGE_REFERENCE.md#62-exception-catalog).

## 1. Fail-Hard Writes (Exceptions)

Writes surface failures to the caller. Key creation and renames also report violations of the configured governance policy as typed exceptions.

| Exception Class                        | Description                            | Typically Thrown By           |
|:---------------------------------------|:---------------------------------------|:------------------------------|
| `InvalidLanguageCodeException`         | Language code empty, whitespace or > 16 characters. | Commands, `TranslationWriteService` |
| `LanguageCodeAlreadyInUseException`    | Re-key target code already owns translations. | `TranslationWriteService` |
| `TranslationKeyNotFoundException`      | Key ID does not exist.                 | `TranslationWriteService`     |
| `TranslationKeyAlreadyExistsException` | Key `scope.domain.key` already exists. | `TranslationWriteService`     |
| `TranslationKeyCreateFailedException`  | The repository returned no valid key ID.            | `TranslationWriteService`     |
| `ScopeNotAllowedException`             | Scope is inactive, or missing under `STRICT`.          | `I18nGovernancePolicyService` |
| `DomainNotAllowedException`            | Domain is inactive, or missing under `STRICT`.         | `I18nGovernancePolicyService` |
| `DomainScopeViolationException`        | Both records are active and the domain lacks its assignment to the scope.         | `I18nGovernancePolicyService` |
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
*   **Domain read denied by governance:** Returns an empty DTO. A single-key read can still return an exact stored translation.

**Handling Nulls:**
Application code **must** be robust to `null` returns.

```php
$text = $readService->getValue('en', 'web', 'home', 'welcome');
echo htmlspecialchars($text ?? 'Welcome', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
```

**Language codes:**
Reads never throw for a language code. An unknown but syntactically valid code simply owns no rows; an invalid code (empty / whitespace / > 16 chars) yields `null` / an empty DTO. Writes throw `InvalidLanguageCodeException` for an invalid code. I18n never throws a "language not found" error: language existence is Host policy.

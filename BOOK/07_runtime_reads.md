# 07. Runtime Reads

This chapter details the fail-soft behavior of runtime translation reads.

## 1. Fail-Soft Philosophy

Runtime translation reads treat missing data and invalid language codes as expected outcomes.

*   **Storage failures:** a repository error propagates to the caller. See the [storage failure contract](../I18N_PACKAGE_REFERENCE.md#63-storage-failure-contract).
*   **Missing Data:**
    *   Missing Key → Returns `null`.
    *   Missing Translation in the requested exact scope → Returns `null` (there is **no fallback** inside I18n).
    *   Domain denied by the configured policy → Returns an empty DTO.
    *   Invalid language code (empty / whitespace / > 16 chars) → `null` / empty DTO.

**Exact scope only:** `getValue('ar', …)` reads `ar` only; `getValue(null, …)` reads the unlocalized (`NULL`) scope only. A miss never retries on another language or on `NULL`. Fallback is a Host policy: the Host wraps `getValue` with its own chain of codes.

**Rationale:**
A missing translation must not cause a fatal application error.

## 2. Single Value Read (`TranslationReadService`)

Fetches a specific translation string for one exact language scope.

```php
$value = $readService->getValue(
    languageCode: 'en-US',
    scope: 'client',
    domain: 'auth',
    key: 'login.title'
);

// Returns "Log In" OR null
if ($value === null) {
    // Key or translation missing
}

$translation = $readService->getTranslation(
    languageCode: 'en-US',
    scope: 'client',
    domain: 'auth',
    key: 'login.title'
);
// TranslationValueDTO: ['value' => 'Log In', 'type' => null] or null on an exact miss.
```

`getValue()` remains a value-only compatibility read. `getTranslation()` returns the value and optional type together. Both use the same exact-scope and fail-soft rules. Deactivation preserves the stored translation, so single-key reads can still return it. The configured governance policy applies to bulk domain reads.

The built-in MySQL repository reads the structured key identity and its exact translation in one statement. Under `READ COMMITTED` and `REPEATABLE READ`, this keeps the key identity and translation consistent during a concurrent move.

## 3. Bulk Domain Read (`TranslationDomainReadService`)

Fetches all translations for a specific `Scope` + `Domain`.

```php
$dto = $domainReadService->getDomainValues(
    languageCode: 'en-US',
    scope: 'client',
    domain: 'auth'
);

// $dto is strictly typed: TranslationDomainValuesDTO
$translations = $dto->all();

// Result: ['login.title' => 'Log In', 'register.btn' => 'Sign Up']
```

For typed rows, use the rich domain read:

```php
$typed = $domainReadService->getDomainTranslations('en-US', 'client', 'auth');
$login = $typed->get('login.title'); // TranslationValueDTO: value + nullable type
```

**Behavior:**
*   Returns strictly typed `TranslationDomainValuesDTO`.
*   Contains only values of the requested exact scope (no fallback values).
*   Returns an empty result if there are no matching translations or the configured policy denies the scope and domain.
*   `getDomainValues()` remains value-only. `getDomainTranslations()` returns `key_part => TranslationValueDTO`; absent rows are omitted, while an existing row with an empty value remains present.
*   Every non-null type is an opaque consumer-defined token. I18n does not define its vocabulary or assign behavior to it, and does not trust, render or sanitize value content.

With the built-in repositories on one PDO connection, MySQL reads the governance state, keys, and exact translations in one statement. Under `READ COMMITTED` and `REPEATABLE READ`, the single statement keeps the governance decision and the translations consistent during a concurrent deactivation.

The package does not cache reads. A Host that repeatedly reads the same domain may choose to cache bulk reads when appropriate. If the Host caches these results, it is responsible for invalidating them after writes through `TranslationWriteService`.

## 4. Caching Strategy

The library implementation does **not** cache data. It queries the database directly.

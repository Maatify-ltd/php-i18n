<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository;

use Maatify\I18n\Consumer\DTO\TranslationDomainTranslationsDTO;
use Maatify\I18n\Consumer\DTO\TranslationValueDTO;

/** Reads key identities and their exact-language values from the same database snapshot. */
interface ExactTranslationReadRepositoryInterface
{
    /** Whether joined reads preserve the supplied key repository's source and contract. */
    public function canReadKeysFrom(TranslationKeyRepositoryInterface $keyRepository): bool;

    /** Return the exact value and type, or null when the key or translation is absent. */
    public function readStructuredTranslation(
        ?string $languageCode,
        string $scope,
        string $domain,
        string $key,
    ): ?TranslationValueDTO;

    /** Read exact translations for the current domain keys; untranslated keys are absent. */
    public function readDomainTranslations(
        ?string $languageCode,
        string $scope,
        string $domain,
    ): TranslationDomainTranslationsDTO;
}

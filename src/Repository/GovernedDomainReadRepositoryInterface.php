<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository;

use Maatify\I18n\Consumer\DTO\TranslationDomainTranslationsDTO;
use Maatify\I18n\Enum\I18nPolicyModeEnum;

/** Reads domain visibility and exact translations from one snapshot. */
interface GovernedDomainReadRepositoryInterface extends ExactTranslationReadRepositoryInterface
{
    /** Whether the read can apply the supplied governance sources without bypassing them. */
    public function canReadGovernanceFrom(
        ScopeRepositoryInterface $scopes,
        DomainRepositoryInterface $domains,
        DomainScopeRepositoryInterface $assignments,
    ): bool;

    /** Return exact domain translations visible under the supplied governance mode. */
    public function readGovernedDomainTranslations(
        ?string $languageCode,
        string $scope,
        string $domain,
        I18nPolicyModeEnum $mode,
    ): TranslationDomainTranslationsDTO;
}

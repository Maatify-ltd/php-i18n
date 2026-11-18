<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository;

use Maatify\I18n\Exception\LanguageCodeAlreadyInUseException;

/**
 * Translation repository that checks and protects the destination inside the
 * same transaction that changes the language identity.
 */
interface GuardedLanguageRekeyRepositoryInterface extends TranslationRepositoryInterface
{
    /** @throws LanguageCodeAlreadyInUseException when the exact destination is occupied */
    public function rekeyLanguageCode(string $oldCode, string $newCode): int;
}

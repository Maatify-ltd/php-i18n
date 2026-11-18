<?php

declare(strict_types=1);

namespace Maatify\I18n\Management\Criteria;

use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\ValueObject\LanguageCode;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;

/**
 * Keys x exact-language-codes grid of one (scope, domain). The Host supplies the
 * exact language codes (and, for free-text search, the codes whose Host
 * metadata matched); I18n never reads a Host language table.
 */
final readonly class DomainTranslationGridCriteria
{
    /** @var list<string> */
    public array $languageCodes;

    /** @var list<string> */
    public array $globalSearchLanguageCodes;

    /**
     * @param array<array-key, mixed> $languageCodes Validated as a list of exact codes.
     * @param array<array-key, mixed> $globalSearchLanguageCodes Validated as a list of exact codes.
     * @throws I18nInvalidArgumentException when scope/domain is blank, keyId
     *     is not positive, or a supplied language code violates the technical
     *     contract
     */
    public function __construct(
        public string $scopeCode,
        public string $domainCode,
        array $languageCodes,
        public ?string $globalSearch = null,
        array $globalSearchLanguageCodes = [],
        public ?int $keyId = null,
        public ?string $keyPartLike = null,
        public ?string $valueLike = null,
        public PageRequest $page = new PageRequest(),
    ) {
        if (trim($scopeCode) === '') {
            throw I18nInvalidArgumentException::emptyField('scopeCode');
        }

        if (trim($domainCode) === '') {
            throw I18nInvalidArgumentException::emptyField('domainCode');
        }

        if ($keyId !== null && $keyId <= 0) {
            throw I18nInvalidArgumentException::notPositive('keyId');
        }

        LanguageCode::requireFilterList($languageCodes, 'languageCodes');
        $this->languageCodes = $languageCodes;
        LanguageCode::requireFilterList($globalSearchLanguageCodes, 'globalSearchLanguageCodes');
        $this->globalSearchLanguageCodes = $globalSearchLanguageCodes;
    }
}

<?php

declare(strict_types=1);

namespace Maatify\I18n\Management\Criteria;

use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\ValueObject\LanguageCode;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;

/**
 * Per-key translation summary of one (scope, domain) over a Host-supplied set
 * of exact language codes. I18n does not know the language universe: the Host
 * passes the codes it wants measured and I18n counts exact matches only.

 */
final readonly class DomainKeySummaryCriteria
{
    /** @var list<string> */
    public array $languageCodes;

    /**
     * @param array<array-key, mixed> $languageCodes Validated as a list of exact codes.
     * @throws I18nInvalidArgumentException when scope/domain is blank, keyId
     *     is not positive, or a language code violates the technical contract
     */
    public function __construct(
        public string $scopeCode,
        public string $domainCode,
        array $languageCodes,
        public ?string $globalSearch = null,
        public ?int $keyId = null,
        public ?string $keyPart = null,
        public bool $onlyMissing = false,
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
    }
}

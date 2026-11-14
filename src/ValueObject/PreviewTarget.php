<?php

declare(strict_types=1);

namespace Maatify\I18n\ValueObject;

use Maatify\I18n\Exception\I18nInvalidArgumentException;

/** Selects a whole domain or one exact key in a scope. */
final readonly class PreviewTarget
{
    /** A null key selects the entire domain. */
    public function __construct(
        public string $scope,
        public string $domain,
        public ?string $key = null,
    ) {
        self::validate($scope, 'scope', 32);
        self::validate($domain, 'domain', 64);

        if ($key !== null) {
            self::validate($key, 'key', 128);
        }
    }

    private static function validate(string $value, string $field, int $max): void
    {
        if (trim($value) === '') {
            throw I18nInvalidArgumentException::emptyField($field);
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new I18nInvalidArgumentException('Preview targets must be valid UTF-8.');
        }

        if (mb_strlen($value, 'UTF-8') > $max) {
            throw I18nInvalidArgumentException::tooLong($field, $max);
        }
    }
}

<?php

declare(strict_types=1);

namespace Maatify\I18n\ValueObject;

use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Exception\InvalidLanguageCodeException;

/**
 * Immutable exact language scope of an I18n translation (ADR-019).
 *
 * Holds either NULL (the exact unlocalized scope) or a technically valid exact
 * code. Construction validates the storage contract:
 *
 * - a non-NULL code must not be empty or whitespace-only and must be at most
 *   MAX_LENGTH characters;
 * - the code is never trimmed, lowercased or otherwise normalized;
 * - whether the code is a known / active / supported language is Host policy
 *   and is NOT checked here.
 */
final readonly class LanguageCode
{
    public const MAX_LENGTH = 16;

    private function __construct(
        private ?string $code,
    ) {}

    /**
     * @throws InvalidLanguageCodeException
     */
    public static function fromNullable(?string $code): self
    {
        $languageCode = self::tryFromNullable($code);

        if ($languageCode !== null) {
            return $languageCode;
        }

        if ($code === null) {
            return new self(null);
        }

        throw new InvalidLanguageCodeException($code);
    }

    /**
     * Package-internal non-throwing factory for boundaries that need
     * fail-soft technical validation.
     *
     * Creates an exact language scope without throwing for an invalid code.
     * A null input is valid and represents the unlocalized scope; a null
     * result means a non-null input violated the technical code contract.
     *
     * @internal This is not part of the supported consumer API.
     */
    public static function tryFromNullable(?string $code): ?self
    {
        if ($code !== null && (preg_match('/^[\p{Z}\x{0009}-\x{000D}\x{0085}]*$/uD', $code) !== 0
            || mb_strlen($code, 'UTF-8') > self::MAX_LENGTH
        )) {
            return null;
        }

        return new self($code);
    }

    /**
     * Require a non-null, technically valid code for a management filter.
     *
     * @throws I18nInvalidArgumentException
     * @internal
     */
    public static function requireFilterCode(string $code, string $field): void
    {
        if (self::tryFromNullable($code) !== null) {
            return;
        }

        if (!mb_check_encoding($code, 'UTF-8')) {
            throw new I18nInvalidArgumentException(sprintf('I18n input "%s" must be valid UTF-8.', $field));
        }

        if (mb_strlen($code, 'UTF-8') > self::MAX_LENGTH) {
            throw I18nInvalidArgumentException::tooLong($field, self::MAX_LENGTH);
        }

        throw I18nInvalidArgumentException::emptyField($field);
    }

    /**
     * Require a list containing only named language scopes. An empty list is valid.
     *
     * @param array<array-key, mixed> $codes
     * @phpstan-assert list<string> $codes
     * @throws I18nInvalidArgumentException
     * @internal
     */
    public static function requireFilterList(array $codes, string $field): void
    {
        if (!array_is_list($codes)) {
            throw new I18nInvalidArgumentException(sprintf('I18n input "%s" must be a list.', $field));
        }

        foreach ($codes as $code) {
            if (!is_string($code)) {
                throw new I18nInvalidArgumentException(sprintf('I18n input "%s" must contain only language code strings.', $field));
            }

            self::requireFilterCode($code, $field);
        }
    }

    /**
     * The exact nullable code (`null` = unlocalized scope).
     */
    public function value(): ?string
    {
        return $this->code;
    }

    public function isUnlocalized(): bool
    {
        return $this->code === null;
    }

    /**
     * NULL-safe identity value used by the generated `language_code_identity`
     * column: NULL maps to '' (a non-NULL code is never empty).
     */
    public function identity(): string
    {
        return $this->code ?? '';
    }
}

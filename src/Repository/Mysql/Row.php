<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository\Mysql;

use Maatify\I18n\Exception\I18nStorageException;

/**
 * Strict hydration of a PDO row. A missing / mistyped column of a row that the
 * schema guarantees is a storage failure, never a silent default.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        return self::parseInt($row[$column] ?? null, 'hydrate:' . $column);
    }

    /** Reject unrepresentable database integers before PHP can clamp them. */
    public static function parseInt(mixed $value, string $operation): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new I18nStorageException($operation);
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            throw new I18nStorageException($operation);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        throw new I18nStorageException('hydrate:' . $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $column): bool
    {
        return self::int($row, $column) === 1;
    }

    /**
     * Escape a user supplied LIKE fragment (binary-safe, backslash escape).
     */
    public static function like(string $value): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
    }
}

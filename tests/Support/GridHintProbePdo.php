<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Exercises a server-side branch in the actual grid data statement.
 * The failing branch parses a deliberately invalid JSON value from the fixture.
 */
final class GridHintProbePdo extends PDO
{
    /** @param array{dsn: string, user: string, pass: string} $credentials */
    public function __construct(
        array $credentials,
        private readonly bool $failWhenHintApplies,
    ) {
        parent::__construct($credentials['dsn'], $credentials['user'], $credentials['pass'], [
            self::ATTR_ERRMODE => self::ERRMODE_EXCEPTION,
            self::ATTR_DEFAULT_FETCH_MODE => self::FETCH_ASSOC,
            self::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $condition = $this->failWhenHintApplies ? '=' : '<>';
        $marker = 't.id AS translation_id, k.id AS key_id';
        // The extra projection is evaluated by MySQL and ignored by the grid row mapper.
        $probe = "IF(@@SESSION.max_sort_length $condition 2048, JSON_EXTRACT(t.value, '$'), NULL) AS grid_hint_probe";
        $query = str_replace($marker, "$probe, $marker", $query);

        return parent::prepare($query, $options);
    }
}

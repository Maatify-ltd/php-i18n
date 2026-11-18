<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Support;

use Closure;
use PDO;
use PDOStatement;

/** Runs a competing transaction after MySQL returns key rows to the reader. */
final class AfterKeyReadStatement extends PDOStatement
{
    /** @param Closure():void $afterKeyRead */
    protected function __construct(private readonly Closure $afterKeyRead) {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        if ($row !== false && str_contains($this->queryString, 'FROM maa_i18n_keys')) {
            ($this->afterKeyRead)();
        }

        return $row;
    }

    /** @return array<array-key, mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);

        if ($rows !== [] && str_contains($this->queryString, 'FROM maa_i18n_keys')) {
            ($this->afterKeyRead)();
        }

        return $rows;
    }
}

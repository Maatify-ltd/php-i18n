<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Support;

use Closure;
use PDO;
use PDOStatement;

/** Runs a competing transaction after fetching rows for the selected MySQL statement. */
final class AfterSelectedReadStatement extends PDOStatement
{
    /** @param Closure():void $afterRead */
    protected function __construct(
        private readonly Closure $afterRead,
        private readonly string $table = 'maa_i18n_keys',
    ) {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);

        if ($row !== false && str_contains($this->queryString, $this->table)) {
            ($this->afterRead)();
        }

        return $row;
    }

    /** @return array<array-key, mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);

        if ($rows !== [] && str_contains($this->queryString, $this->table)) {
            ($this->afterRead)();
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Support;

use Closure;
use PDOStatement;

/** Records the SQL for statements executed through a real PDO connection. */
final class RecordingQueryStatement extends PDOStatement
{
    /** @param Closure(string):void $record */
    protected function __construct(
        private readonly Closure $record,
    ) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $executed = parent::execute($params);
        ($this->record)($this->queryString);

        return $executed;
    }
}

<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository\Mysql;

use LogicException;
use Maatify\I18n\Exception\I18nStorageException;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\I18n\Enum\LockModeEnum;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

/**
 * Persistence mechanics shared by the two governance tables (scopes, domains).
 *
 * Ordering and pagination are delegated to maatify/php-persistence; this class only
 * supplies the trusted table identifiers and the domain query (filters, search,
 * selected columns, row mapping). The table name is a constant of the calling
 * repository, never user input.
 */
final readonly class MysqlGovernanceTableSupport
{
    private ScopedOrderingConfig $orderingConfig;

    /**
     * @param non-empty-string $table trusted constant of the calling repository
     */
    public function __construct(
        private PdoGateway $gateway,
        private string $table,
        private ScopedOrderingManager $ordering = new ScopedOrderingManager(),
        private PdoPaginator $paginator = new PdoPaginator(),
    ) {
        $this->orderingConfig = new ScopedOrderingConfig(
            table: $table,
            scopeColumn: null,
            idColumn: 'id',
            orderColumn: 'sort_order',
            deletedAtColumn: null,
        );
    }

    /**
     * Next display position (max + 1). The caller owns the transaction and
     * must hold {@see self::lockOrderingScope()} for concurrent creates.
     *
     * @throws I18nStorageException if Ordering reports an execution failure
     * @throws \PDOException if PDO throws while reading
     */
    public function nextPosition(): int
    {
        try {
            return $this->ordering->getNextPositionForUpdate($this->gateway->pdo(), $this->orderingConfig);
        } catch (OrderingExecutionException $failure) {
            throw new I18nStorageException($this->table . '.nextPosition', $failure);
        }
    }

    /**
     * Caller-owned lock that serializes concurrent position allocation. It
     * takes the same locks, in the same index order (display position, then id),
     * as maatify/php-persistence takes for a move, so creates and moves contend on
     * the first row instead of deadlocking each other. Requires an active
     * transaction.
     *
     * An EMPTY ordering has no row to lock: concurrent first creates may make
     * InnoDB choose a deadlock victim (SQLSTATE 40001, propagated unchanged).
     */
    public function lockOrderingScope(): void
    {
        $this->assertInTransaction();

        $this->gateway->run(
            'SELECT id FROM ' . $this->table . ' ORDER BY sort_order ASC, id ASC FOR UPDATE',
            [],
            $this->table . '.lockOrdering',
        );
    }

    /**
     * @return bool false only when the target is missing
     * @throws I18nStorageException if Ordering reports an execution failure, the follow-up read fails without
     *                              throwing, or the existing target did not move
     * @throws \PDOException if PDO throws during the move or follow-up read
     */
    public function moveToPosition(int $id, int $position): bool
    {
        $pdo = $this->gateway->pdo();

        return (new PdoTransactionRunner($pdo))->run(function () use ($pdo, $id, $position): bool {
            try {
                $moved = $this->ordering->moveWithinScope($pdo, $this->orderingConfig, null, $id, $position);
            } catch (OrderingExecutionException $failure) {
                throw new I18nStorageException($this->table . '.moveToPosition', $failure);
            }

            if ($moved) {
                return true;
            }

            // The ordering scope is still locked. Use a current read even if the Host
            // opened a repeatable-read snapshot before the target became visible.
            $target = $this->gateway->fetchOne(
                'SELECT id FROM ' . $this->table . ' WHERE id = :id FOR UPDATE',
                ['id' => $id],
                $this->table . '.moveTarget',
            );

            if ($target !== null) {
                throw new I18nStorageException($this->table . '.moveToPosition');
            }

            return false;
        });
    }

    /** Raise LogicException when a locking read has no active transaction. */
    public function assertInTransaction(): void
    {
        if (!$this->gateway->pdo()->inTransaction()) {
            throw new LogicException('A locking read requires an active transaction.');
        }
    }

    /**
     * Return the SQL suffix from LockModeEnum; NONE needs no transaction,
     * while SHARE/UPDATE require one and otherwise raise LogicException.
     */
    public function lockSuffix(LockModeEnum $lock): string
    {
        if ($lock !== LockModeEnum::NONE) {
            $this->assertInTransaction();
        }

        return $lock->sqlSuffix();
    }

    /**
     * @template TItem of array<array-key, mixed>|object
     *
     * @param list<string>                         $where   AND-ed trusted predicates (alias `s`)
     * @param array<string, string|int|bool|null>  $params  values bound by name
     * @param callable(array<string, mixed>): TItem $mapper
     *
     * @return PageResultDTO<TItem>
     */
    public function page(
        string $selectColumns,
        string $from,
        array $where,
        array $params,
        PageRequest $request,
        callable $mapper,
        string $countFrom = '',
    ): PageResultDTO {
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $countFrom = $countFrom === '' ? $from : $countFrom;

        $descriptor = new PdoPaginationQueryDescriptor(
            totalSql: 'SELECT COUNT(*) FROM ' . $this->table,
            totalParams: [],
            filteredCountSql: 'SELECT COUNT(*) ' . $countFrom . $whereSql,
            filteredCountParams: $params,
            dataSql: 'SELECT ' . $selectColumns . ' ' . $from . $whereSql,
            dataParams: $params,
        );

        $config = new PaginationConfig(
            sortWhitelist: new SortWhitelist([
                'id' => 's.id',
                'code' => 's.code',
                'name' => 's.name',
                'is_active' => 's.is_active',
                'sort_order' => 's.sort_order',
                'created_at' => 's.created_at',
            ]),
            defaultSortBy: 'sort_order',
            defaultSortDirection: SortDirectionEnum::ASC,
            tieBreakerSortBy: 'id',
            tieBreakerDirection: SortDirectionEnum::ASC,
        );

        return $this->paginator->paginate($this->gateway->pdo(), $descriptor, $request, $config, $mapper);
    }
}

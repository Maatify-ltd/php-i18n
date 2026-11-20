<?php

/**
 * @copyright   ©2026 Maatify.dev
 * @Library     maatify/php-i18n
 * @Project     maatify:php-i18n
 * @author      Mohamed Abdulalim (megyptm) <mohamed@maatify.dev>
 * @since       2026-10-01 00:00
 * @see         https://www.maatify.dev Maatify.dev
 * @link        https://github.com/Maatify-ltd/php-i18n view Project on GitHub
 * @note        Distributed in the hope that it will be useful - WITHOUT WARRANTY.
 */

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\DTO\TranslationGridRowDTO;
use Maatify\I18n\Exception\I18nExceptionInterface;
use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Exception\InvalidLanguageCodeException;
use Maatify\I18n\Exception\TranslationKeyNotFoundException;
use Maatify\I18n\Management\Command\CreateKeyCommand;
use Maatify\I18n\Management\Command\RenameKeyCommand;
use Maatify\I18n\Management\Command\UpsertTranslationCommand;
use Maatify\I18n\Management\Criteria\DomainKeySummaryCriteria;
use Maatify\I18n\Management\Criteria\DomainTranslationGridCriteria;
use Maatify\I18n\Management\Criteria\KeyListCriteria;
use Maatify\I18n\Management\Criteria\LanguageTranslationValuesCriteria;
use Maatify\I18n\Repository\Mysql\MysqlTranslationQueryRepository;
use Maatify\I18n\Tests\Support\GridHintProbePdo;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use Maatify\I18n\Tests\Support\MysqlTestEnvironment;
use Maatify\I18n\Tests\Support\RecordingQueryStatement;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

final class KeyAndTranslationManagementTest extends MysqlIntegrationTestCase
{
    /** @var list<string> */
    private array $recordedGridStatements = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec("INSERT INTO maa_i18n_scopes (code, name) VALUES ('ad', 'Admin')");
        $this->pdo()->exec("INSERT INTO maa_i18n_domains (code, name) VALUES ('auth', 'Auth')");
        $this->pdo()->exec("INSERT INTO maa_i18n_domain_scopes (scope_code, domain_code) VALUES ('ct', 'auth'), ('ad', 'auth')");
    }

    /**
     * @param list<string> $codes
     */
    private function summaryCriteria(
        array $codes,
        bool $onlyMissing = false,
        ?string $search = null,
        ?PageRequest $page = null,
    ): DomainKeySummaryCriteria {
        return new DomainKeySummaryCriteria(
            'ct',
            'home',
            $codes,
            globalSearch: $search,
            onlyMissing: $onlyMissing,
            page: $page ?? new PageRequest(),
        );
    }

    /**
     * @param list<string> $codes
     * @param list<string> $matched
     */
    private function gridCriteria(
        array $codes,
        ?string $search = null,
        array $matched = [],
        ?string $value = null,
    ): DomainTranslationGridCriteria {
        return new DomainTranslationGridCriteria(
            'ct',
            'home',
            $codes,
            globalSearch: $search,
            globalSearchLanguageCodes: $matched,
            valueLike: $value,
        );
    }

    public function testCommandsValidateTheirOwnContract(): void
    {
        foreach ([
            static fn() => new CreateKeyCommand('', 'home', 'k'),
            static fn() => new CreateKeyCommand('ct', ' ', 'k'),
            static fn() => new CreateKeyCommand('ct', 'home', ''),
            static fn() => new CreateKeyCommand(str_repeat('s', 33), 'home', 'k'),
            static fn() => new CreateKeyCommand('ct', str_repeat('d', 65), 'k'),
            static fn() => new CreateKeyCommand('ct', 'home', str_repeat('k', 129)),
            static fn() => new CreateKeyCommand('ct', 'home', 'k', str_repeat('d', 256)),
            static fn() => new RenameKeyCommand(0, 'ct', 'home', 'k'),
            static fn() => new RenameKeyCommand(1, 'ct', 'home', ' '),
            static fn() => new UpsertTranslationCommand(
                languageCode: 'ar',
                keyId: 0,
                value: 'v',
                type: null,
            ),
        ] as $build) {
            try {
                $build();
                self::fail('Expected I18nInvalidArgumentException');
            } catch (I18nInvalidArgumentException $e) {
                self::assertInstanceOf(I18nExceptionInterface::class, $e);
            }
        }

        $this->expectException(InvalidLanguageCodeException::class);
        new UpsertTranslationCommand(
            languageCode: '   ',
            keyId: 1,
            value: 'v',
            type: null,
        );
    }

    public function testAnEmptyValueIsAValidAuthoritativeTranslation(): void
    {
        $key = $this->createKey('ct', 'home', 'k');

        $this->upsert('ar', $key, '', null);

        $row = $this->translations->getByLanguageAndKey('ar', $key);
        self::assertNotNull($row);
        self::assertSame('', $row->value);
    }

    public function testKeySearchIsScopedPaginatedAndFiltered(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $k) {
            $this->createKey('ct', 'home', $k);
        }
        $this->createKey('ct', 'auth', 'login');
        $this->createKey('ad', 'auth', 'other-scope');

        $page = $this->managementRead->searchKeys(new KeyListCriteria('ct', page: new PageRequest(1, 3)));
        // `total` = global unfiltered key population (5 keys exist); `filtered` = scope + optional filters (4)
        self::assertSame(5, $page->total);
        self::assertSame(4, $page->filtered);
        self::assertSame(2, $page->totalPages);
        self::assertSame(['alpha', 'beta', 'gamma'], array_map(static fn($k) => $k->key, $page->data));

        $byDomain = $this->managementRead->searchKeys(new KeyListCriteria('ct', domainLike: 'auth'));
        self::assertSame(['login'], array_map(static fn($k) => $k->key, $byDomain->data));

        $search = $this->managementRead->searchKeys(new KeyListCriteria('ct', globalSearch: 'amm'));
        self::assertSame(['gamma'], array_map(static fn($k) => $k->key, $search->data));

        $filteredOut = $this->managementRead->searchKeys(new KeyListCriteria('ct', domainLike: 'auth'));
        self::assertSame([5, 1], [$filteredOut->total, $filteredOut->filtered]);

        $none = $this->managementRead->searchKeys(new KeyListCriteria('unknown-scope'));
        self::assertSame([5, 0], [$none->total, $none->filtered]);
        self::assertSame([], $none->data);

        $detail = $this->managementRead->getKey($page->data[0]->id);
        self::assertSame('alpha', $detail->key);

        $this->expectException(TranslationKeyNotFoundException::class);
        $this->managementRead->getKey(999999);
    }

    public function testRenameMovesTheKeyAndItsDerivedSummaryFollows(): void
    {
        $a = $this->createKey('ct', 'home', 'a');
        $this->upsert('ar', $a, 'x', null);

        $this->renameKey($a, 'ct', 'auth', 'a2');

        $row = $this->keys->getById($a);
        self::assertNotNull($row);
        self::assertSame(['ct', 'auth', 'a2'], [$row->scope, $row->domain, $row->key]);
        self::assertSame(['total_keys' => 1, 'translated_count' => 1, 'missing_count' => 0], $this->summary->getRow('ct', 'auth', 'ar'));
        self::assertNull($this->summary->getRow('ct', 'home', 'ar'));
    }

    public function testDomainKeySummaryCountsOnlyTheSuppliedExactCodes(): void
    {
        $a = $this->createKey('ct', 'home', 'a');
        $b = $this->createKey('ct', 'home', 'b');
        $c = $this->createKey('ct', 'home', 'c');
        $this->upsert('ar', $a, 'x', null);
        $this->upsert('en', $a, 'x', null);
        $this->upsert('ar', $b, 'x', null);
        $this->upsert('AR', $c, 'x', null); // different exact code: never matches 'ar'
        $this->upsert(null, $c, 'x', null);

        $all = $this->managementRead->pageDomainKeySummaries($this->summaryCriteria(['ar', 'en']));
        $byKey = [];
        foreach ($all->data as $row) {
            $byKey[$row->keyPart] = [$row->totalLanguages, $row->missingCount];
        }
        self::assertSame(['a' => [2, 0], 'b' => [2, 1], 'c' => [2, 2]], $byKey);
        self::assertSame(3, $all->total);

        $missing = $this->managementRead->pageDomainKeySummaries($this->summaryCriteria(['ar', 'en'], true));
        self::assertSame(['b', 'c'], array_map(static fn($r) => $r->keyPart, $missing->data));
        self::assertSame(2, $missing->filtered);
        self::assertSame(3, $missing->total);

        $noCodes = $this->managementRead->pageDomainKeySummaries($this->summaryCriteria([]));
        self::assertSame([0, 0], [$noCodes->data[0]->totalLanguages, $noCodes->data[0]->missingCount]);

        $search = $this->managementRead->pageDomainKeySummaries($this->summaryCriteria(['ar'], false, 'b'));
        self::assertSame(['b'], array_map(static fn($r) => $r->keyPart, $search->data));

        $paged = $this->managementRead->pageDomainKeySummaries($this->summaryCriteria(['ar'], false, null, new PageRequest(2, 2)));
        self::assertSame(['c'], array_map(static fn($r) => $r->keyPart, $paged->data));
        self::assertTrue($paged->hasPrevious);
    }

    public function testDomainTranslationGridIsKeysTimesSuppliedCodes(): void
    {
        $a = $this->createKey('ct', 'home', 'a');
        $b = $this->createKey('ct', 'home', 'b');
        $this->upsert('ar', $a, 'مرحبا', null);
        $this->upsert('en', $b, 'Hello', null);

        $page = $this->managementRead->pageDomainTranslationGrid($this->gridCriteria(['ar', 'en']));
        self::assertSame(4, $page->total);
        self::assertSame(4, $page->filtered);
        $cells = [];
        foreach ($page->data as $row) {
            $cells[] = [$row->keyPart, $row->languageCode, $row->value];
        }
        self::assertSame([
            ['a', 'ar', 'مرحبا'],
            ['a', 'en', null],
            ['b', 'ar', null],
            ['b', 'en', 'Hello'],
        ], $cells);
        self::assertNotNull($page->data[0]->translationId);
        self::assertNull($page->data[1]->translationId);

        $byValue = $this->managementRead->pageDomainTranslationGrid($this->gridCriteria(['ar', 'en'], null, [], 'ell'));
        self::assertSame([['b', 'en']], array_map(static fn($r) => [$r->keyPart, $r->languageCode], $byValue->data));

        // free text: key part, value, the exact code, or Host-matched codes
        $bySearch = $this->managementRead->pageDomainTranslationGrid($this->gridCriteria(['ar', 'en'], 'zzz', ['en']));
        self::assertSame([['a', 'en'], ['b', 'en']], array_map(static fn($r) => [$r->keyPart, $r->languageCode], $bySearch->data));

        $empty = $this->managementRead->pageDomainTranslationGrid($this->gridCriteria([]));
        self::assertSame([0, 0, []], [$empty->total, $empty->filtered, $empty->data]);
    }

    /** @return iterable<string, array{int, ?string, ?string, string, string, bool, ?string, bool}> */
    public static function gridPageOrders(): iterable
    {
        $orders = [
            'default' => [null, null, 'key_part', 'ASC', false, null],
            'explicit key ascending' => ['key_part', 'ASC', 'key_part', 'ASC', false, null],
            'explicit key descending' => ['key_part', 'DESC', 'key_part', 'DESC', false, null],
            'language ascending' => ['language_code', 'ASC', 'language_code', 'ASC', false, null],
            'language descending' => ['language_code', 'DESC', 'language_code', 'DESC', false, null],
            'padded language and direction' => [' language_code ', ' desc ', 'language_code', 'DESC', false, null],
            'invalid direction with language' => ['language_code', 'sideways', 'language_code', 'ASC', false, null],
            'unknown key keeps direction' => ['unknown_grid_sort', ' desc ', 'key_part', 'DESC', false, null],
            'uppercase language falls back' => ['LANGUAGE_CODE', null, 'key_part', 'ASC', false, null],
            'invalid key falls back' => ['language_code,key_part', null, 'key_part', 'ASC', false, null],
            'single language ascending' => ['language_code', 'ASC', 'language_code', 'ASC', true, null],
            'single language descending' => ['language_code', 'DESC', 'language_code', 'DESC', true, null],
            'value filter with language' => ['language_code', 'DESC', 'language_code', 'DESC', false, 'keep'],
        ];

        foreach ([1, 2] as $perPage) {
            foreach ($orders as $name => [$sortBy, $sortDirection, $expectedSortBy, $expectedDirection, $singleLanguage, $valueLike]) {
                foreach ([false, true] as $longKeys) {
                    $fixture = $longKeys ? 'Unicode ' : '';
                    yield $fixture . $name . ' / ' . $perPage => [
                        $perPage,
                        $sortBy,
                        $sortDirection,
                        $expectedSortBy,
                        $expectedDirection,
                        $singleLanguage,
                        $valueLike,
                        $longKeys,
                    ];
                }
            }
        }
    }

    /** @param 1|2 $perPage */
    #[DataProvider('gridPageOrders')]
    public function testGridPageOrderReturnsEachExpectedCellExactlyOnce(
        int $perPage,
        ?string $sortBy,
        ?string $sortDirection,
        string $expectedSortBy,
        string $expectedDirection,
        bool $singleLanguage,
        ?string $valueLike,
        bool $longKeys,
    ): void {
        $parts = ['delta' => 'delta', 'alpha' => 'alpha', 'charlie' => 'charlie', 'bravo' => 'bravo', 'echo' => 'echo'];
        if ($longKeys) {
            // The collation expands 128 occurrences of U+337F into 2048 weight bytes.
            $prefix = str_repeat("\u{337F}", 127);
            $parts = [
                'delta' => $prefix . "\u{337F}",
                'alpha' => $prefix . 'a',
                'charlie' => $prefix . 'C',
                'bravo' => $prefix . 'b',
                'echo' => $prefix . "\u{FDFB}",
            ];
        }
        $keyIds = [];
        foreach ($parts as $label => $keyPart) {
            $keyIds[$label] = $this->createKey('ct', 'home', $keyPart);
        }

        $pdo = $this->pdo();
        self::assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        if ($longKeys) {
            $weights = $pdo->prepare('SELECT CHAR_LENGTH(key_part), OCTET_LENGTH(WEIGHT_STRING(key_part)) FROM maa_i18n_keys WHERE id = :id');
            self::assertNotFalse($weights);
            $weights->execute(['id' => $keyIds['delta']]);
            self::assertSame([128, 2048], $weights->fetch(PDO::FETCH_NUM));
        }

        $translations = [
            'delta' => ['ar' => [$this->upsert('ar', $keyIds['delta'], 'keep delta', 'fixture'), 'keep delta', 'fixture']],
            'alpha' => ['AR' => [$this->upsert('AR', $keyIds['alpha'], 'keep alpha', null), 'keep alpha', null]],
            'charlie' => [
                'ar' => [$this->upsert('ar', $keyIds['charlie'], 'other charlie', null), 'other charlie', null],
                'AR' => [$this->upsert('AR', $keyIds['charlie'], '', null), '', null],
            ],
            'echo' => ['ar' => [$this->upsert('ar', $keyIds['echo'], 'keep echo', null), 'keep echo', null]],
        ];
        $this->upsert(null, $keyIds['bravo'], 'unlocalized', null);

        // The reference order comes from the fixture and the documented direction of each sort key.
        $keyParts = $longKeys ? ['alpha', 'bravo', 'charlie', 'echo', 'delta'] : ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        $requestedCodes = $longKeys ? ['ar ', 'ar', 'AR', 'ar '] : ['ar', 'AR', 'ar'];
        $codes = $longKeys ? ['AR', 'ar', 'ar '] : ['AR', 'ar'];
        if ($singleLanguage) {
            $requestedCodes = $codes = ['ar'];
        }
        $sortByLanguage = $expectedSortBy === 'language_code';
        if ($expectedDirection === 'DESC') {
            if ($sortByLanguage) {
                $codes = array_reverse($codes);
            } else {
                $keyParts = array_reverse($keyParts);
            }
        }

        $expectedByPrimary = array_fill_keys($sortByLanguage ? $codes : $keyParts, []);
        foreach ($keyParts as $keyPart) {
            foreach ($codes as $code) {
                [$translationId, $value, $type] = $translations[$keyPart][$code] ?? [null, null, null];
                if ($valueLike !== null && ($value === null || ! str_contains($value, $valueLike))) {
                    continue;
                }

                $expectedByPrimary[$sortByLanguage ? $code : $keyPart][] = [
                    'translation_id' => $translationId,
                    'key_id' => $keyIds[$keyPart],
                    'key_part' => $parts[$keyPart],
                    'description' => null,
                    'language_code' => $code,
                    'value' => $value,
                    'type' => $type,
                ];
            }
        }

        $expectedRows = array_merge(...array_values($expectedByPrimary));
        $expectedPages = array_chunk($expectedRows, $perPage);
        $statementsByPage = [];
        $results = [];
        $actualCells = [];
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            RecordingQueryStatement::class,
            [function (string $sql): void {
                $this->recordedGridStatements[] = $sql;
            }],
        ]);

        try {
            foreach (array_keys($expectedPages) as $offset) {
                $page = $this->managementRead->pageDomainTranslationGrid(new DomainTranslationGridCriteria(
                    'ct',
                    'home',
                    $requestedCodes,
                    valueLike: $valueLike,
                    page: new PageRequest($offset + 1, $perPage, $sortBy, $sortDirection),
                ));
                $statementsByPage[] = $this->recordedGridStatements;
                $this->recordedGridStatements = [];
                self::assertNoGridWarnings($pdo);
                self::assertInstanceOf(PageResultDTO::class, $page);
                foreach ($page->data as $row) {
                    self::assertInstanceOf(TranslationGridRowDTO::class, $row);
                    $actualCells[] = [$row->keyId, $row->languageCode, $row->translationId];
                }
                $results[] = $page;
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        // Check cell coverage before order so a duplicate or missing cell yields its own failure.
        $expectedCells = array_map(
            static fn(array $row): array => [$row['key_id'], $row['language_code'], $row['translation_id']],
            $expectedRows,
        );
        sort($expectedCells);
        sort($actualCells);
        self::assertSame($expectedCells, $actualCells, 'The pages must contain every grid cell exactly once.');

        $order = $sortByLanguage
            ? "ORDER BY `lc`.`language_code` $expectedDirection, `k`.`key_part` ASC"
            : "ORDER BY `k`.`key_part` $expectedDirection, `lc`.`language_code` ASC";
        $order .= "\nLIMIT :__pagination_limit\nOFFSET :__pagination_offset";
        foreach ($statementsByPage as $statements) {
            self::assertCount(3, $statements);
            [$totalSql, $filteredSql, $dataSql] = $statements;
            foreach ([$totalSql, $filteredSql] as $countSql) {
                self::assertStringStartsWith('SELECT COUNT(*) ', $countSql);
                self::assertStringNotContainsString('SET_VAR', $countSql);
            }
            self::assertStringStartsWith('SELECT /*+ SET_VAR(max_sort_length=2048) */ t.id AS translation_id', $dataSql);
            self::assertStringEndsWith($order, $dataSql);
            self::assertSame(1, substr_count($dataSql, 'SET_VAR'));
        }

        foreach ($expectedPages as $offset => $expectedPage) {
            $expectedPagination = [
                'page' => $offset + 1,
                'per_page' => $perPage,
                'total' => count($keyParts) * count($codes),
                'filtered' => count($expectedRows),
                'total_pages' => count($expectedPages),
                'has_next' => $offset + 1 < count($expectedPages),
                'has_previous' => $offset > 0,
                'sort_by' => $expectedSortBy,
                'sort_direction' => $expectedDirection,
            ];
            $result = $results[$offset];
            self::assertSame($expectedPagination, $result->toArray()['pagination']);
            self::assertSame(
                ['data' => $expectedPage, 'pagination' => $expectedPagination],
                json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @return iterable<string, array{int, ?string, bool}> */
    public static function gridHostSessions(): iterable
    {
        foreach ([64, 8192] as $sortLength) {
            foreach ([null, 'READ WRITE', 'READ ONLY'] as $mode) {
                foreach ([false, true] as $fail) {
                    $name = ($mode ?? 'no transaction') . ' / ' . $sortLength . ' / ' . ($fail ? 'server error' : 'success');
                    yield $name => [$sortLength, $mode, $fail];
                }
            }
        }
    }

    #[DataProvider('gridHostSessions')]
    public function testGridHintPreservesTheHostSession(int $sortLength, ?string $transactionMode, bool $fail): void
    {
        $keyId = $this->createKey('ct', 'home', 'hint-probe');
        $translationId = $this->upsert('ar', $keyId, 'not JSON', null);
        $credentials = MysqlTestEnvironment::credentials();
        self::assertNotNull($credentials);
        $pdo = new GridHintProbePdo($credentials, $fail);
        $pdo->exec('USE `' . self::schemaName() . '`');
        $pdo->exec('SET SESSION max_sort_length = ' . $sortLength);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_bin');
        self::assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        if ($transactionMode !== null) {
            $pdo->exec('SET TRANSACTION ' . $transactionMode);
            $pdo->beginTransaction();
        }

        try {
            $queries = new MysqlTranslationQueryRepository($pdo);
            $criteria = new DomainTranslationGridCriteria('ct', 'home', ['ar'], keyId: $keyId);
            $sessionSql = 'SELECT @@SESSION.max_sort_length, @@SESSION.collation_connection, @@SESSION.transaction_isolation';
            $before = $pdo->query($sessionSql);
            self::assertNotFalse($before);
            $expectedSession = [$sortLength, 'utf8mb4_bin', 'READ-COMMITTED'];
            self::assertSame($expectedSession, $before->fetch(PDO::FETCH_NUM));

            if ($fail) {
                try {
                    $queries->pageDomainTranslationGrid($criteria);
                    self::fail('Expected MySQL to evaluate the failing probe with the hint in effect.');
                } catch (PDOException $error) {
                    // 3141 requires MySQL to reach JSON_EXTRACT on the invalid fixture.
                    self::assertSame(3141, $error->errorInfo[1] ?? null);
                }
            } else {
                $page = $queries->pageDomainTranslationGrid($criteria);
                self::assertCount(1, $page->data);
                self::assertSame($translationId, $page->data[0]->translationId);
                self::assertSame('not JSON', $page->data[0]->value);
                self::assertNoGridWarnings($pdo);
            }

            $after = $pdo->query($sessionSql);
            self::assertNotFalse($after);
            self::assertSame($expectedSession, $after->fetch(PDO::FETCH_NUM));
            self::assertSame($transactionMode !== null, $pdo->inTransaction());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private static function assertNoGridWarnings(PDO $pdo): void
    {
        // Inspect the previous native statement without preparing SHOW WARNINGS itself.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $warningQuery = $pdo->query('SHOW WARNINGS');
            self::assertNotFalse($warningQuery);
            self::assertSame([], $warningQuery->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    public function testLanguageTranslationValuesListEveryKeyForOneExactCode(): void
    {
        $a = $this->createKey('ct', 'home', 'a');
        $b = $this->createKey('ct', 'auth', 'b');
        $this->upsert('ar', $a, 'A-ar', null);
        $this->upsert('AR', $b, 'B-AR', null);

        $page = $this->managementRead->pageLanguageTranslationValues(new LanguageTranslationValuesCriteria('ar'));
        self::assertSame(2, $page->total);
        self::assertSame([['a', 'A-ar'], ['b', null]], array_map(static fn($r) => [$r->keyPart, $r->value], $page->data));
        self::assertNotNull($page->data[0]->translationId);
        self::assertNull($page->data[1]->translationId);

        $unknown = $this->managementRead->pageLanguageTranslationValues(new LanguageTranslationValuesCriteria('xx'));
        self::assertSame([null, null], array_map(static fn($r) => $r->value, $unknown->data));

        $filtered = $this->managementRead->pageLanguageTranslationValues(
            new LanguageTranslationValuesCriteria('ar', valueLike: 'A-'),
        );
        self::assertSame(['a'], array_map(static fn($r) => $r->keyPart, $filtered->data));
        self::assertSame(1, $filtered->filtered);
        self::assertSame(2, $filtered->total);
    }
}

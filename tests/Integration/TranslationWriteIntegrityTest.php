<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\I18n\Exception\InvalidLanguageCodeException;
use Maatify\I18n\Exception\LanguageCodeAlreadyInUseException;
use Maatify\I18n\Management\Command\UpsertTranslationCommand;
use Maatify\I18n\Management\Criteria\DomainTranslationGridCriteria;
use Maatify\I18n\Management\Service\TranslationWriteService;
use Maatify\I18n\Repository\Mysql\MysqlDomainLanguageSummaryRepository;
use Maatify\I18n\Repository\Mysql\MysqlDomainRepository;
use Maatify\I18n\Repository\Mysql\MysqlDomainScopeRepository;
use Maatify\I18n\Repository\Mysql\MysqlKeyStatsRepository;
use Maatify\I18n\Repository\Mysql\MysqlScopeRepository;
use Maatify\I18n\Repository\Mysql\MysqlTranslationKeyRepository;
use Maatify\I18n\Repository\Mysql\MysqlTranslationRepository;
use Maatify\I18n\Service\I18nGovernancePolicyService;
use Maatify\I18n\Service\MissingCounterService;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;
use PDOException;

final class TranslationWriteIntegrityTest extends MysqlIntegrationTestCase
{
    public function testTrailingSpaceIdentitiesRemainDistinctInReadsWritesAndSummaries(): void
    {
        $key = $this->createKey('ct', 'home', 'padded-code');
        $first = $this->upsert('ar', $key, 'unspaced', null);
        self::assertNull($this->reader->getValue('ar ', 'ct', 'home', 'padded-code'));

        $second = $this->upsert('ar ', $key, 'spaced', null);
        self::assertNotSame($first, $second);
        self::assertSame('unspaced', $this->reader->getValue('ar', 'ct', 'home', 'padded-code'));
        self::assertSame('spaced', $this->reader->getValue('ar ', 'ct', 'home', 'padded-code'));
        self::assertSame(1, $this->summary->getRow('ct', 'home', 'ar')['translated_count'] ?? null);
        self::assertSame(1, $this->summary->getRow('ct', 'home', 'ar ')['translated_count'] ?? null);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_domain_language_summary'));

        $grid = $this->managementRead->pageDomainTranslationGrid(new DomainTranslationGridCriteria('ct', 'home', ['ar', 'ar ']));
        $byCode = [];
        foreach ($grid->data as $row) {
            $byCode[$row->languageCode] = $row->value;
        }
        self::assertSame('unspaced', $byCode['ar'] ?? null);
        self::assertSame('spaced', $byCode['ar '] ?? null);

        $this->writer->deleteTranslation('ar ', $key);
        self::assertSame('unspaced', $this->reader->getValue('ar', 'ct', 'home', 'padded-code'));
        self::assertNull($this->summary->getRow('ct', 'home', 'ar '));
    }

    public function testUnicodeWhitespaceOnlyCodeIsRejectedInPhpAndMysql(): void
    {
        $key = $this->createKey('ct', 'home', 'white-code');
        $code = "\u{00A0}\u{2003}\t";
        try {
            $this->upsert($code, $key, 'invalid', null);
            self::fail('Expected an invalid language code.');
        } catch (InvalidLanguageCodeException) {
            self::assertNull($this->reader->getValue($code, 'ct', 'home', 'white-code'));
        }

        $statement = $this->pdo()->prepare(
            'INSERT INTO maa_i18n_translations (key_id, language_code, value) VALUES (:key_id, :code, :value)',
        );
        self::assertNotFalse($statement);
        try {
            $statement->execute(['key_id' => $key, 'code' => $code, 'value' => 'invalid']);
            self::fail('Expected the storage constraint to reject the code.');
        } catch (PDOException $failure) {
            self::assertSame(3819, $failure->errorInfo[1] ?? null);
        }

        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_translations'));
    }

    public function testRepeatedUpsertWithFoundRowsKeepsTheAuthoritativeAndDerivedCountsAligned(): void
    {
        $key = $this->createKey('ct', 'home', 'repeat');
        $pdo = self::newConnection(foundRows: true);
        $writer = $this->writerOn($pdo);
        $command = new UpsertTranslationCommand('ar', $key, 'same value', null);
        $id = $writer->upsertTranslation($command);
        self::assertSame([$id, $id], [
            $writer->upsertTranslation($command),
            $writer->upsertTranslation($command),
        ]);
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_translations'));
        self::assertSame(1, $this->scalarInt('SELECT translated_count FROM maa_i18n_key_stats WHERE key_id=' . $key));
        self::assertSame(1, $this->summary->getRow('ct', 'home', 'ar')['translated_count'] ?? null);
    }

    public function testRekeyRejectsADestinationCommittedAfterAnOuterSnapshot(): void
    {
        $first = $this->createKey('ct', 'home', 'first');
        $second = $this->createKey('ct', 'home', 'second');
        $this->upsert('old', $first, 'old text', null);
        $pdo = self::newConnection();
        $writer = $this->writerOn($pdo);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();

        try {
            $translations = new MysqlTranslationRepository($pdo, $this->fixedClock());
            self::assertFalse($translations->hasAnyForLanguage('new'));
            $this->upsert('new', $second, 'destination text', null);
            self::assertFalse($translations->hasAnyForLanguage('new'));

            try {
                $writer->rekeyLanguageCode('old', 'new');
                self::fail('Expected the committed destination to be rejected.');
            } catch (LanguageCodeAlreadyInUseException) {
                self::assertTrue($pdo->inTransaction());
                self::assertTrue($pdo->commit());
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame('old text', $this->reader->getValue('old', 'ct', 'home', 'first'));
        self::assertNull($this->reader->getValue('new', 'ct', 'home', 'first'));
        self::assertSame('destination text', $this->reader->getValue('new', 'ct', 'home', 'second'));
    }

    public function testRekeyUsesTheCurrentDestinationWhenAnOlderSnapshotStillHasARow(): void
    {
        $first = $this->createKey('ct', 'home', 'first');
        $second = $this->createKey('ct', 'home', 'second');
        $this->upsert('old', $first, 'to move', null);
        $this->upsert('new', $second, 'to remove', null);
        $pdo = self::newConnection();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();

        try {
            $translations = new MysqlTranslationRepository($pdo, $this->fixedClock());
            self::assertTrue($translations->hasAnyForLanguage('new'));
            $this->writer->deleteTranslation('new', $second);
            self::assertTrue($translations->hasAnyForLanguage('new'));

            self::assertSame(1, $this->writerOn($pdo)->rekeyLanguageCode('old', 'new'));
            self::assertTrue($pdo->inTransaction());
            self::assertTrue($pdo->commit());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame('to move', $this->reader->getValue('new', 'ct', 'home', 'first'));
        self::assertNull($this->reader->getValue('new', 'ct', 'home', 'second'));
    }

    public function testRekeyRetainsBothLanguageLocksUntilTheOuterCommitUnderReadCommitted(): void
    {
        $first = $this->createKey('ct', 'home', 'first');
        $second = $this->createKey('ct', 'home', 'second');
        $this->upsert('old', $first, 'to move', null);

        $owner = self::newConnection();
        $contender = self::newConnection();
        $owner->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $contender->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $contender->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $owner->beginTransaction();

        try {
            self::assertSame(1, $this->writerOn($owner)->rekeyLanguageCode('old', 'new'));
            $writer = $this->writerOn($contender);
            foreach (['old', 'new'] as $code) {
                try {
                    $writer->upsertTranslation(new UpsertTranslationCommand($code, $second, 'concurrent', null));
                    self::fail('Expected the language write lock to be held by the host transaction.');
                } catch (PDOException $failure) {
                    self::assertSame(1205, $failure->errorInfo[1] ?? null);
                    self::assertFalse($contender->inTransaction());
                }
            }
            self::assertTrue($owner->commit());

            $writer->upsertTranslation(new UpsertTranslationCommand('new', $second, 'after commit', null));
            self::assertSame('to move', $this->reader->getValue('new', 'ct', 'home', 'first'));
            self::assertSame('after commit', $this->reader->getValue('new', 'ct', 'home', 'second'));
            self::assertSame(2, $this->summary->getRow('ct', 'home', 'new')['translated_count'] ?? null);
        } finally {
            if ($owner->inTransaction()) {
                $owner->rollBack();
            }
        }
    }

    public function testTranslationWritesRefreshTheCurrentDomainAfterAKeyMoves(): void
    {
        $this->pdo()->exec("INSERT INTO maa_i18n_domains (code, name) VALUES ('next', 'Next')");
        $this->pdo()->exec("INSERT INTO maa_i18n_domain_scopes (scope_code, domain_code) VALUES ('ct', 'next')");
        $keyId = $this->createKey('ct', 'home', 'moving');
        $pdo = self::newConnection();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $writer = $this->writerOn($pdo);
        $keys = new MysqlTranslationKeyRepository($pdo);
        $pdo->beginTransaction();

        try {
            self::assertSame('home', $keys->getById($keyId)?->domain);
            $this->renameKey($keyId, 'ct', 'next', 'moving');
            $writer->upsertTranslation(new UpsertTranslationCommand('ar', $keyId, 'stored', null));
            self::assertTrue($pdo->commit());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame('stored', $this->reader->getValue('ar', 'ct', 'next', 'moving'));
        self::assertSame(1, $this->summary->getRow('ct', 'next', 'ar')['translated_count'] ?? null);
        self::assertNull($this->summary->getRow('ct', 'home', 'ar'));

        $pdo->beginTransaction();
        try {
            self::assertSame('next', $keys->getById($keyId)->domain);
            $this->renameKey($keyId, 'ct', 'home', 'moving');
            $writer->deleteTranslation('ar', $keyId);
            self::assertTrue($pdo->commit());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertNull($this->reader->getValue('ar', 'ct', 'home', 'moving'));
        self::assertNull($this->summary->getRow('ct', 'home', 'ar'));
        self::assertNull($this->summary->getRow('ct', 'next', 'ar'));
        self::assertSame(0, $this->scalarInt('SELECT translated_count FROM maa_i18n_key_stats WHERE key_id=' . $keyId));
    }

    private function writerOn(PDO $pdo): TranslationWriteService
    {
        $keys = new MysqlTranslationKeyRepository($pdo);

        return new TranslationWriteService(
            new PdoTransactionRunner($pdo),
            $keys,
            new MysqlTranslationRepository($pdo, $this->fixedClock()),
            new I18nGovernancePolicyService(
                new MysqlScopeRepository($pdo),
                new MysqlDomainRepository($pdo),
                new MysqlDomainScopeRepository($pdo),
            ),
            new MissingCounterService(new MysqlDomainLanguageSummaryRepository($pdo), $keys, new MysqlKeyStatsRepository($pdo)),
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-18 12:00:00', $this->getTimezone());
            }

            public function getTimezone(): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }
        };
    }
}

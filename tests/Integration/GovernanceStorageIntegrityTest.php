<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Exception\I18nStorageException;
use Maatify\I18n\Management\Command\CreateDomainCommand;
use Maatify\I18n\Management\Command\CreateScopeCommand;
use Maatify\I18n\Repository\Mysql\MysqlDomainRepository;
use Maatify\I18n\Repository\Mysql\MysqlScopeRepository;
use Maatify\I18n\Tests\Support\Concurrency\Runtime;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

final class GovernanceStorageIntegrityTest extends MysqlIntegrationTestCase
{
    /** @return array<string, array{bool, string, string}> */
    public static function governanceTables(): array
    {
        return [
            'scopes' => [false, 'maa_i18n_scopes', 'ct'],
            'domains' => [true, 'maa_i18n_domains', 'home'],
        ];
    }

    #[DataProvider('governanceTables')]
    public function testOrderingLockSerializesFirstCreatesAtReadCommitted(bool $domain, string $table, string $code): void
    {
        $owner = $this->pdo();
        $other = self::newConnection();
        $owner->exec('DELETE FROM maa_i18n_domain_scopes');
        $owner->exec("DELETE FROM $table");
        $owner->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $other->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $ownerRepo = $domain ? new MysqlDomainRepository($owner) : new MysqlScopeRepository($owner);
        $first = Runtime::build($owner);
        $second = Runtime::build($other);
        $owner->beginTransaction();

        try {
            $ownerRepo->lockOrderingScope();
            try {
                $this->createOrdered($second, $domain, 'second');
                self::fail('A competing first create must wait on an empty ordering.');
            } catch (PDOException $failure) {
                self::assertSame(1205, $failure->errorInfo[1] ?? null);
                self::assertFalse($other->inTransaction());
            }

            self::assertSame(0, $this->scalarInt("SELECT COUNT(*) FROM $table"));
            $this->createOrdered($first, $domain, $code);
            self::assertTrue($owner->commit());
        } finally {
            if ($owner->inTransaction()) {
                $owner->rollBack();
            }
        }

        $this->createOrdered($second, $domain, 'second');
        self::assertSame(1, $this->scalarInt("SELECT sort_order FROM $table WHERE code = '$code'"));
        self::assertSame(2, $this->scalarInt("SELECT sort_order FROM $table WHERE code = 'second'"));
    }

    #[DataProvider('governanceTables')]
    public function testInvalidActivityIsRejectedBySchemaAndLegacyHydration(bool $domain, string $table, string $code): void
    {
        $pdo = $this->pdo();
        try {
            $pdo->exec("UPDATE $table SET is_active = 2 WHERE code = '$code'");
            self::fail('The schema must reject a third activity state.');
        } catch (PDOException $failure) {
            self::assertSame(3819, $failure->errorInfo[1] ?? null);
        }

        $constraint = 'chk_' . $table . '_active';
        $pdo->exec("ALTER TABLE $table DROP CHECK $constraint");
        try {
            $pdo->exec("UPDATE $table SET is_active = 2 WHERE code = '$code'");
            $this->expectException(I18nStorageException::class);
            $this->expectExceptionMessage('hydrate:is_active');
            if ($domain) {
                $this->managementRead->getDomain($this->scalarInt("SELECT id FROM $table WHERE code = '$code'"));
            } else {
                $this->managementRead->getScopeByCode($code);
            }
        } finally {
            $pdo->exec("UPDATE $table SET is_active = 1 WHERE code = '$code'");
            $pdo->exec("ALTER TABLE $table ADD CONSTRAINT $constraint CHECK (is_active IN (0, 1))");
        }
    }

    /** @throws PDOException if the competing MySQL write fails */
    private function createOrdered(Runtime $runtime, bool $domain, string $code): void
    {
        if ($domain) {
            $runtime->domains->create(new CreateDomainCommand($code, $code));
        } else {
            $runtime->scopes->create(new CreateScopeCommand($code, $code));
        }
    }
}

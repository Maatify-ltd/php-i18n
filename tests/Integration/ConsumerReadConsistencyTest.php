<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Consumer\Service\TranslationDomainReadService;
use Maatify\I18n\Consumer\Service\TranslationReadService;
use Maatify\I18n\DTO\TranslationKeyCollectionDTO;
use Maatify\I18n\Enum\I18nPolicyModeEnum;
use Maatify\I18n\Repository\DomainScopeRepositoryInterface;
use Maatify\I18n\Service\I18nGovernancePolicyService;
use Maatify\I18n\Management\Command\RenameKeyCommand;
use Maatify\I18n\Management\Command\UpsertTranslationCommand;
use Maatify\I18n\Repository\TranslationKeyRepositoryInterface;
use Maatify\I18n\Tests\Support\AfterSelectedReadStatement;
use Maatify\I18n\Tests\Support\Concurrency\Runtime;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsumerReadConsistencyTest extends MysqlIntegrationTestCase
{
    /** @return array<string, array{bool}> */
    public static function readModes(): array
    {
        return ['single key' => [false], 'domain' => [true]];
    }

    #[DataProvider('readModes')]
    public function testKeyMoveCannotReturnTheNewDomainValueUnderTheOldIdentity(bool $bulk): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO maa_i18n_domains (code, name) VALUES ('next', 'Next')");
        $pdo->exec("INSERT INTO maa_i18n_domain_scopes (scope_code, domain_code) VALUES ('ct', 'next')");
        $keyId = $this->createKey('ct', 'home', 'title');
        $this->upsert('ar', $keyId, 'old domain value', null);

        $other = self::newConnection();
        $writer = Runtime::build($other)->writer;
        $moved = false;
        $afterKeyRead = static function () use ($other, $writer, $keyId, &$moved): void {
            if ($moved) {
                return;
            }

            $other->beginTransaction();
            try {
                $writer->renameKey(new RenameKeyCommand($keyId, 'ct', 'next', 'title'));
                $writer->upsertTranslation(new UpsertTranslationCommand('ar', $keyId, 'new domain value', null));
                $moved = $other->commit();
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }
        };
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AfterSelectedReadStatement::class, [$afterKeyRead]]);
        try {
            $value = $bulk
                ? $this->domainReader->getDomainValues('ar', 'ct', 'home')->get('title')
                : $this->reader->getValue('ar', 'ct', 'home', 'title');
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        self::assertTrue($moved);
        self::assertSame('old domain value', $value);
        self::assertNull($this->reader->getValue('ar', 'ct', 'home', 'title'));
        self::assertSame('new domain value', $this->reader->getValue('ar', 'ct', 'next', 'title'));
    }

    /** @return array<string, array{bool, string}> */
    public static function transactionModes(): array
    {
        return [
            'standalone READ COMMITTED' => [false, 'READ COMMITTED'],
            'Host READ COMMITTED' => [true, 'READ COMMITTED'],
            'standalone REPEATABLE READ' => [false, 'REPEATABLE READ'],
            'Host REPEATABLE READ' => [true, 'REPEATABLE READ'],
        ];
    }

    #[DataProvider('transactionModes')]
    public function testGovernanceAndValueUseOneSnapshotDuringDeactivation(bool $outer, string $isolation): void
    {
        $pdo = $this->pdo();
        $keyId = $this->createKey('ct', 'home', 'title');
        $this->upsert('ar', $keyId, 'visible before', null);
        $domainId = $this->scalarInt("SELECT id FROM maa_i18n_domains WHERE code = 'home'");
        $other = self::newConnection();
        $runtime = Runtime::build($other);
        $changed = false;
        $afterRead = static function () use ($other, $runtime, $domainId, $keyId, &$changed): void {
            if ($changed) {
                return;
            }

            $other->beginTransaction();
            try {
                $runtime->domains->setActive($domainId, false);
                $runtime->writer->upsertTranslation(new UpsertTranslationCommand('ar', $keyId, 'updated while inactive', null));
                $changed = $other->commit();
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }
        };

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AfterSelectedReadStatement::class, [$afterRead, 'maa_i18n_domain_scopes']]);
        if ($outer) {
            $pdo->beginTransaction();
        }

        try {
            $observed = $this->domainReader->getDomainValues('ar', 'ct', 'home')->all();
            self::assertSame($outer, $pdo->inTransaction());
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertTrue($changed);
        self::assertSame(['title' => 'visible before'], $observed);
        self::assertSame([], $this->domainReader->getDomainValues('ar', 'ct', 'home')->all());
        self::assertSame('updated while inactive', $this->reader->getValue('ar', 'ct', 'home', 'title'));
    }

    public function testJoinedReadAgreesWithBothGovernanceModes(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO maa_i18n_keys (scope, domain, key_part) VALUES ('probe', 'probe', 'title')");
        $keyId = $this->scalarInt("SELECT id FROM maa_i18n_keys WHERE scope = 'probe'");
        $this->upsert('ar', $keyId, 'value', null);
        $scope = $pdo->prepare("INSERT INTO maa_i18n_scopes (code, name, is_active) VALUES ('probe', 'Probe', :active)");
        $domain = $pdo->prepare("INSERT INTO maa_i18n_domains (code, name, is_active) VALUES ('probe', 'Probe', :active)");
        foreach ([null, 0, 1] as $scopeActive) {
            foreach ([null, 0, 1] as $domainActive) {
                $pdo->exec("DELETE FROM maa_i18n_domain_scopes WHERE scope_code = 'probe'");
                $pdo->exec("DELETE FROM maa_i18n_scopes WHERE code = 'probe'");
                $pdo->exec("DELETE FROM maa_i18n_domains WHERE code = 'probe'");
                if ($scopeActive !== null) {
                    $scope->execute(['active' => $scopeActive]);
                }
                if ($domainActive !== null) {
                    $domain->execute(['active' => $domainActive]);
                }

                foreach ([false, true] as $assigned) {
                    if ($assigned) {
                        $pdo->exec("INSERT INTO maa_i18n_domain_scopes (scope_code, domain_code) VALUES ('probe', 'probe')");
                    }
                    foreach (I18nPolicyModeEnum::cases() as $mode) {
                        $policy = $this->policyFor($mode);
                        $reader = new TranslationDomainReadService($this->keys, $this->translations, $policy);
                        $expected = $policy->isScopeAndDomainReadable('probe', 'probe') ? ['title' => 'value'] : [];
                        self::assertSame($expected, $reader->getDomainValues('ar', 'probe', 'probe')->all());
                    }
                }
            }
        }
    }

    public function testJoinedReadDoesNotBypassInjectedGovernance(): void
    {
        $keyId = $this->createKey('ct', 'home', 'title');
        $this->upsert('ar', $keyId, 'hidden by custom policy', null);
        $assignments = $this->createMock(DomainScopeRepositoryInterface::class);
        $assignments->expects(self::once())->method('isDomainAllowedForScope')->willReturn(false);
        $policy = new I18nGovernancePolicyService($this->scopes, $this->domains, $assignments);
        $reader = new TranslationDomainReadService($this->keys, $this->translations, $policy);

        self::assertSame([], $reader->getDomainValues('ar', 'ct', 'home')->all());
    }

    public function testNativeTranslationRepositoryRespectsAnInjectedKeyRepository(): void
    {
        $keyId = $this->createKey('ct', 'home', 'title');
        $this->upsert('ar', $keyId, 'native value', null);
        $customKeys = $this->createMock(TranslationKeyRepositoryInterface::class);
        $customKeys->expects(self::once())->method('getByStructuredKey')->with('ct', 'home', 'title')->willReturn(null);
        $customKeys->expects(self::once())->method('listByScopeAndDomain')->with('ct', 'home')->willReturn(new TranslationKeyCollectionDTO([]));

        $reader = new TranslationReadService($customKeys, $this->translations);
        $domainReader = new TranslationDomainReadService($customKeys, $this->translations, $this->policy);

        self::assertNull($reader->getValue('ar', 'ct', 'home', 'title'));
        self::assertSame([], $domainReader->getDomainValues('ar', 'ct', 'home')->all());
    }
}

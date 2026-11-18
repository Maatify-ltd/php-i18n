<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Consumer\Service\TranslationDomainReadService;
use Maatify\I18n\Consumer\Service\TranslationReadService;
use Maatify\I18n\DTO\TranslationKeyCollectionDTO;
use Maatify\I18n\Management\Command\RenameKeyCommand;
use Maatify\I18n\Management\Command\UpsertTranslationCommand;
use Maatify\I18n\Repository\TranslationKeyRepositoryInterface;
use Maatify\I18n\Tests\Support\AfterKeyReadStatement;
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
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AfterKeyReadStatement::class, [$afterKeyRead]]);
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

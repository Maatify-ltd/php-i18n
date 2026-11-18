<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Exception\I18nStorageException;
use Maatify\I18n\Repository\Mysql\PdoGateway;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;

final class IntegerStorageBoundaryTest extends MysqlIntegrationTestCase
{
    public function testAKeyIdAbovePhpIntMaxCannotReturnAnotherKeysTranslation(): void
    {
        $large = self::abovePhpMax();
        $pdo = $this->pdo();
        $keys = $pdo->prepare(
            'INSERT INTO maa_i18n_keys (id, scope, domain, key_part) VALUES (:id, \'ct\', \'home\', :part)',
        );
        self::assertNotFalse($keys);
        $rows = $pdo->prepare(
            'INSERT INTO maa_i18n_translations (key_id, language_code, value) VALUES (:id, \'ar\', :value)',
        );
        self::assertNotFalse($rows);

        $keys->execute(['id' => (string) PHP_INT_MAX, 'part' => 'max-id']);
        $keys->execute(['id' => $large, 'part' => 'over-max']);
        $rows->execute(['id' => (string) PHP_INT_MAX, 'value' => 'max id']);
        $rows->execute(['id' => $large, 'value' => 'over max']);

        self::assertSame('max id', $this->reader->getValue('ar', 'ct', 'home', 'max-id'));
        $this->expectException(I18nStorageException::class);
        $this->expectExceptionMessage('hydrate:id');
        $this->reader->getValue('ar', 'ct', 'home', 'over-max');
    }

    public function testLastInsertIdRejectsAnUnrepresentableGeneratedId(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TEMPORARY TABLE generated_id_fixture (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $pdo->exec('ALTER TABLE generated_id_fixture AUTO_INCREMENT = ' . self::abovePhpMax());
            $pdo->exec('INSERT INTO generated_id_fixture VALUES (NULL)');
            $this->expectException(I18nStorageException::class);
            $this->expectExceptionMessage('fixture:lastInsertId');
            (new PdoGateway($pdo))->lastInsertId('fixture');
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE generated_id_fixture');
        }
    }

    private static function abovePhpMax(): string
    {
        return PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648';
    }
}

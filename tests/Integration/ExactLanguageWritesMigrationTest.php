<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use PDOException;

final class ExactLanguageWritesMigrationTest extends MysqlIntegrationTestCase
{
    public function testUpgradeRejectsInvalidLegacyCodesBeforeDdlAndPreservesValidRows(): void
    {
        $pdo = $this->pdo();
        $root = dirname(__DIR__, 2);
        $legacy = file_get_contents($root . '/tests/Fixtures/Schema/schema.pre-s1.i18n.sql');
        $translationType = file_get_contents($root . '/schema/migrations/2026-10-02-translation-type.sql');
        $migration = file_get_contents($root . '/schema/migrations/2026-11-18-exact-language-writes.sql');
        self::assertIsString($legacy);
        self::assertIsString($translationType);
        self::assertIsString($migration);

        $pdo->exec('DROP TABLE maa_i18n_language_write_locks');
        $pdo->exec($legacy);
        $pdo->exec($translationType);
        $pdo->exec("INSERT INTO maa_i18n_scopes (code, name) VALUES ('ct', 'Core')");
        $pdo->exec("INSERT INTO maa_i18n_domains (code, name, sort_order) VALUES ('home', 'Home', 1), ('next', 'Next', 1)");
        $pdo->exec("INSERT INTO maa_i18n_keys (id, scope, domain, key_part) VALUES (1, 'ct', 'home', 'legacy')");
        $pdo->exec(
            "INSERT INTO maa_i18n_translations (id, key_id, language_code, value, type)
             VALUES (1, 1, 'ar', 'Original value', 'client.text'),
                    (2, 1, CONVERT(0xC2A0 USING utf8mb4), 'Invalid legacy code', NULL)",
        );

        try {
            $pdo->exec($migration);
            self::fail('The upgrade must stop before modifying an invalid legacy schema.');
        } catch (PDOException $failure) {
            self::assertSame(3819, $failure->errorInfo[1] ?? null);
        }

        self::assertSame('utf8mb4_bin', $this->languageCollation());
        self::assertSame(0, $this->scalarInt(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'maa_i18n_language_write_locks'",
        ));
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_translations'));

        // Apply an explicit Host data decision before retrying the schema upgrade.
        $pdo->exec("UPDATE maa_i18n_translations SET language_code = 'retained' WHERE id = 2");
        $pdo->exec("UPDATE maa_i18n_scopes SET is_active = 2 WHERE code = 'ct'");
        try {
            $pdo->exec($migration);
            self::fail('Invalid legacy activity must stop the upgrade before persistent DDL.');
        } catch (PDOException $failure) {
            self::assertSame(3819, $failure->errorInfo[1] ?? null);
            self::assertStringContainsString('chk_i18n_upgrade_requires_valid_active_flags', $failure->getMessage());
        }

        self::assertSame('utf8mb4_bin', $this->languageCollation());
        $pdo->exec("UPDATE maa_i18n_scopes SET is_active = 0 WHERE code = 'ct'");
        $pdo->exec($migration);
        $pdo->exec($migration);
        self::assertSame(2, $this->scalarInt(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME IN ('chk_maa_i18n_scopes_active', 'chk_maa_i18n_domains_active')",
        ));
        self::assertSame(1, $this->scalarInt("SELECT sort_order FROM maa_i18n_domains WHERE code = 'home'"));
        self::assertSame(2, $this->scalarInt("SELECT sort_order FROM maa_i18n_domains WHERE code = 'next'"));
        self::assertSame(0, $this->scalarInt("SELECT sort_order FROM maa_i18n_scopes WHERE code = 'ct'"));
        self::assertSame(0, $this->scalarInt("SELECT is_active FROM maa_i18n_scopes WHERE code = 'ct'"));
        $pdo->exec("UPDATE maa_i18n_scopes SET is_active = 1 WHERE code = 'ct'");

        self::assertSame('utf8mb4_0900_bin', $this->languageCollation());
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_translations'));
        $stored = $this->translations->getByLanguageAndKey('ar', 1);
        self::assertNotNull($stored);
        self::assertSame(1, $stored->id);
        self::assertSame('Original value', $stored->value);
        self::assertSame('client.text', $stored->type);

        $this->upsert('ar ', 1, 'Distinct exact code', null);
        self::assertSame('Original value', $this->reader->getValue('ar', 'ct', 'home', 'legacy'));
        self::assertSame('Distinct exact code', $this->reader->getValue('ar ', 'ct', 'home', 'legacy'));
    }

    private function languageCollation(): string
    {
        $statement = $this->pdo()->query(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'maa_i18n_translations' AND COLUMN_NAME = 'language_code_identity'",
        );
        self::assertNotFalse($statement);
        $collation = $statement->fetchColumn();
        self::assertIsString($collation);

        return $collation;
    }
}

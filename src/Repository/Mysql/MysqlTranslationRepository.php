<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository\Mysql;

use Maatify\I18n\DTO\TranslationCollectionDTO;
use Maatify\I18n\DTO\TranslationDTO;
use Maatify\I18n\DTO\TranslationUpsertResultDTO;
use Maatify\I18n\Exception\LanguageCodeAlreadyInUseException;
use Maatify\I18n\Repository\GuardedLanguageRekeyRepositoryInterface;
use Maatify\I18n\ValueObject\LanguageCode;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * Persists and reads translation records through the package-owned MySQL schema.
 */
final readonly class MysqlTranslationRepository implements GuardedLanguageRekeyRepositoryInterface
{
    private const COLUMNS = 'id, key_id, language_code, value, type, created_at, updated_at';

    private PdoGateway $gateway;
    private PdoTransactionRunner $transactions;

    public function __construct(
        PDO $pdo,
        private ClockInterface $clock,
    ) {
        $this->gateway = new PdoGateway($pdo);
        $this->transactions = new PdoTransactionRunner($pdo);
    }

    /**
     * Insert or update the exact nullable language scope (`null` is
     * unlocalized), with no fallback or normalization, writing value and type
     * together; type is not identity and an empty value remains stored. The
     * result ID identifies the row and `created` is true only for an insert.
     */
    public function upsert(
        ?string $languageCode,
        int $keyId,
        string $value,
        ?string $type,
    ): TranslationUpsertResultDTO {
        $language = LanguageCode::fromNullable($languageCode);

        return $this->transactions->run(function () use ($language, $keyId, $value, $type): TranslationUpsertResultDTO {
            $this->lockLanguageWrites($language);

            $row = $this->gateway->fetchOne(
                'SELECT id FROM maa_i18n_translations
                 WHERE key_id = :key_id AND language_code_identity = :identity
                 FOR UPDATE',
                ['key_id' => $keyId, 'identity' => $language->identity()],
                'translation.upsert.find',
            );

            if ($row === null) {
                $params = [
                    'language_code' => $language->value(),
                    'key_id' => $keyId,
                    'value' => $value,
                    'type' => $type,
                ];
                $this->gateway->write(
                    'INSERT INTO maa_i18n_translations (language_code, key_id, value, type)
                     VALUES (:language_code, :key_id, :value, :type)',
                    $params,
                    'translation.upsert.insert',
                );

                return new TranslationUpsertResultDTO($this->gateway->lastInsertId('translation.upsert'), true);
            }

            $id = Row::int($row, 'id');
            $this->gateway->write(
                'UPDATE maa_i18n_translations
                 SET value = :value, type = :type, updated_at = :now
                 WHERE id = :id',
                ['value' => $value, 'type' => $type, 'now' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $id],
                'translation.upsert.update',
            );

            return new TranslationUpsertResultDTO($id, false);
        });
    }

    /** Return a translation by ID, or null only when no row matches. */
    public function getById(int $id): ?TranslationDTO
    {
        $row = $this->gateway->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM maa_i18n_translations WHERE id = :id LIMIT 1',
            ['id' => $id],
            'translation.getById',
        );

        return $row === null ? null : $this->map($row);
    }

    /**
     * Read the row for this exact nullable language scope and key; null is
     * unlocalized, with no fallback or normalization.
     */
    public function getByLanguageAndKey(?string $languageCode, int $keyId): ?TranslationDTO
    {
        $row = $this->gateway->fetchOne(
            'SELECT ' . self::COLUMNS . '
             FROM maa_i18n_translations
             WHERE language_code_identity = :language_identity AND key_id = :key_id
             LIMIT 1',
            ([
                'language_identity' => LanguageCode::fromNullable($languageCode)->identity(),
                'key_id' => $keyId,
            ]),
            'translation.getByLanguageAndKey',
        );

        return $row === null ? null : $this->map($row);
    }

    /** Return every translation of the key in language-identity order; no rows yields an empty collection. */
    public function listByKey(int $keyId): TranslationCollectionDTO
    {
        $rows = $this->gateway->fetchAll(
            'SELECT ' . self::COLUMNS . '
             FROM maa_i18n_translations
             WHERE key_id = :key_id
             ORDER BY language_code_identity ASC',
            ['key_id' => $keyId],
            'translation.listByKey',
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->map($row);
        }

        return new TranslationCollectionDTO($items);
    }

    /**
     * Delete the exact nullable language-scope row for the key (`null` is
     * unlocalized); return whether it existed.
     */
    public function deleteByLanguageAndKey(?string $languageCode, int $keyId): bool
    {
        $language = LanguageCode::fromNullable($languageCode);

        return $this->transactions->run(function () use ($language, $keyId): bool {
            $this->lockLanguageWrites($language);

            return $this->gateway->write(
                'DELETE FROM maa_i18n_translations
                 WHERE language_code_identity = :language_identity AND key_id = :key_id',
                ['language_identity' => $language->identity(), 'key_id' => $keyId],
                'translation.delete',
            ) > 0;
        });
    }

    /** Test whether a row exists for this exact nullable language scope and key. */
    public function existsByLanguageAndKey(?string $languageCode, int $keyId): bool
    {
        return $this->gateway->exists(
            'SELECT 1
             FROM maa_i18n_translations
             WHERE language_code_identity = :language_identity AND key_id = :key_id
             LIMIT 1',
            ([
                'language_identity' => LanguageCode::fromNullable($languageCode)->identity(),
                'key_id' => $keyId,
            ]),
            'translation.exists',
        );
    }

    /** Test whether any row exists in this exact nullable language scope. */
    public function hasAnyForLanguage(?string $languageCode): bool
    {
        return $this->gateway->exists(
            'SELECT 1
             FROM maa_i18n_translations
             WHERE language_code_identity = :language_identity
             LIMIT 1',
            ['language_identity' => LanguageCode::fromNullable($languageCode)->identity()],
            'translation.hasAnyForLanguage',
        );
    }

    /**
     * Move rows between exact codes while holding both language write locks.
     * The locking read checks committed and same-transaction destination rows.
     *
     * @throws LanguageCodeAlreadyInUseException when the destination is occupied
     */
    public function rekeyLanguageCode(string $oldCode, string $newCode): int
    {
        $old = LanguageCode::fromNullable($oldCode);
        $new = LanguageCode::fromNullable($newCode);

        if ($old->identity() === $new->identity()) {
            return 0;
        }

        return $this->transactions->run(function () use ($old, $new, $newCode): int {
            $this->lockLanguageWrites($old, $new);

            if ($this->gateway->exists(
                'SELECT 1 FROM maa_i18n_translations WHERE language_code = :code LIMIT 1 FOR UPDATE',
                ['code' => $new->value()],
                'translation.rekey.destination',
            )) {
                throw new LanguageCodeAlreadyInUseException($newCode);
            }

            return $this->gateway->write(
                'UPDATE maa_i18n_translations
                 SET language_code = :new_code
                 WHERE language_code = :old_code',
                ['new_code' => $new->value(), 'old_code' => $old->value()],
                'translation.rekey',
            );
        });
    }

    /**
     * Acquire bounded InnoDB row locks in slot order. A duplicate no-op still
     * takes the existing row's write lock until the active transaction ends.
     */
    private function lockLanguageWrites(LanguageCode ...$languages): void
    {
        $slots = array_map(static fn(LanguageCode $code): int => ord(hash('sha256', $code->identity(), true)[0]), $languages);
        sort($slots, SORT_NUMERIC);

        foreach (array_unique($slots) as $slot) {
            $this->gateway->write(
                'INSERT INTO maa_i18n_language_write_locks (id) VALUES (:id)
                 ON DUPLICATE KEY UPDATE id = id',
                ['id' => $slot],
                'translation.lockLanguage',
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): TranslationDTO
    {
        return new TranslationDTO(
            Row::int($row, 'id'),
            Row::int($row, 'key_id'),
            Row::nullableString($row, 'language_code'),
            Row::string($row, 'value'),
            Row::nullableString($row, 'type'),
            Row::string($row, 'created_at'),
            Row::nullableString($row, 'updated_at'),
        );
    }
}

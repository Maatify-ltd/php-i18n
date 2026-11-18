<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository\Mysql;

use Maatify\I18n\Consumer\DTO\TranslationDomainTranslationsDTO;
use Maatify\I18n\Consumer\DTO\TranslationValueDTO;
use Maatify\I18n\DTO\TranslationCollectionDTO;
use Maatify\I18n\DTO\TranslationDTO;
use Maatify\I18n\DTO\TranslationUpsertResultDTO;
use Maatify\I18n\Enum\I18nPolicyModeEnum;
use Maatify\I18n\Exception\I18nStorageException;
use Maatify\I18n\Exception\LanguageCodeAlreadyInUseException;
use Maatify\I18n\Repository\DomainRepositoryInterface;
use Maatify\I18n\Repository\DomainScopeRepositoryInterface;
use Maatify\I18n\Repository\GovernedDomainReadRepositoryInterface;
use Maatify\I18n\Repository\GuardedLanguageRekeyRepositoryInterface;
use Maatify\I18n\Repository\ScopeRepositoryInterface;
use Maatify\I18n\Repository\TranslationKeyRepositoryInterface;
use Maatify\I18n\ValueObject\LanguageCode;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/**
 * Persists and reads translation records through the package-owned MySQL schema.
 */
final readonly class MysqlTranslationRepository implements GuardedLanguageRekeyRepositoryInterface, GovernedDomainReadRepositoryInterface
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

    /** Join only the built-in key repository on the same PDO connection. */
    public function canReadKeysFrom(TranslationKeyRepositoryInterface $keyRepository): bool
    {
        return $keyRepository instanceof MysqlTranslationKeyRepository
            && $keyRepository->usesConnection($this->gateway->pdo());
    }

    /** Match the built-in governance repositories on the same PDO. */
    public function canReadGovernanceFrom(
        ScopeRepositoryInterface $scopes,
        DomainRepositoryInterface $domains,
        DomainScopeRepositoryInterface $assignments,
    ): bool {
        $pdo = $this->gateway->pdo();

        return $scopes instanceof MysqlScopeRepository
            && $domains instanceof MysqlDomainRepository
            && $assignments instanceof MysqlDomainScopeRepository
            && $scopes->usesConnection($pdo)
            && $domains->usesConnection($pdo)
            && $assignments->usesConnection($pdo);
    }

    /**
     * Read the governance facts, keys and translations in one statement, even
     * under READ COMMITTED. The left joins retain a governance row when the
     * domain is empty or denied, so invalid stored activity still raises.
     */
    public function readGovernedDomainTranslations(
        ?string $languageCode,
        string $scope,
        string $domain,
        I18nPolicyModeEnum $mode,
    ): TranslationDomainTranslationsDTO {
        $rows = $this->gateway->fetchAll(
            'SELECT s.is_active AS scope_active, d.is_active AS domain_active,
                    k.id, k.key_part, t.id AS translation_id, t.value, t.type
             FROM (SELECT :permissive AS permissive) AS request
             LEFT JOIN maa_i18n_scopes s ON s.code = :scope_policy
             LEFT JOIN maa_i18n_domains d ON d.code = :domain_policy
             LEFT JOIN maa_i18n_domain_scopes a ON a.scope_code = s.code AND a.domain_code = d.code
             LEFT JOIN maa_i18n_keys k ON k.scope = :scope_keys AND k.domain = :domain_keys
                AND (s.is_active = 1 OR (request.permissive = 1 AND s.id IS NULL))
                AND (d.is_active = 1 OR (request.permissive = 1 AND d.id IS NULL))
                AND (a.id IS NOT NULL OR (request.permissive = 1 AND (s.id IS NULL OR d.id IS NULL)))
             LEFT JOIN maa_i18n_translations t ON t.key_id = k.id AND t.language_code_identity = :identity
             ORDER BY k.key_part ASC',
            ([
                'permissive' => $mode === I18nPolicyModeEnum::PERMISSIVE ? 1 : 0,
                'scope_policy' => $scope,
                'domain_policy' => $domain,
                'scope_keys' => $scope,
                'domain_keys' => $domain,
                'identity' => LanguageCode::fromNullable($languageCode)->identity(),
            ]),
            'translation.readGovernedDomain',
        );

        $governance = $rows[0] ?? throw new I18nStorageException('translation.readGovernedDomain:governance');
        foreach (['scope_active', 'domain_active'] as $column) {
            if (!array_key_exists($column, $governance) || $governance[$column] !== null) {
                Row::bool($governance, $column);
            }
        }

        return $this->mapDomainValues($rows);
    }

    /** Read the structured key and its exact-language value in a single statement. */
    public function readStructuredTranslation(
        ?string $languageCode,
        string $scope,
        string $domain,
        string $key,
    ): ?TranslationValueDTO {
        $row = $this->gateway->fetchOne(
            'SELECT k.id, t.id AS translation_id, t.value, t.type
             FROM maa_i18n_keys k
             LEFT JOIN maa_i18n_translations t ON t.key_id = k.id AND t.language_code_identity = :identity
             WHERE k.scope = :scope AND k.domain = :domain AND k.key_part = :key
             LIMIT 1',
            ([
                'identity' => LanguageCode::fromNullable($languageCode)->identity(),
                'scope' => $scope,
                'domain' => $domain,
                'key' => $key,
            ]),
            'translation.readStructured',
        );

        return $row === null ? null : $this->valueForKeyRow($row);
    }

    /** Read the domain's key identities and exact-language values in a single statement. */
    public function readDomainTranslations(
        ?string $languageCode,
        string $scope,
        string $domain,
    ): TranslationDomainTranslationsDTO {
        $rows = $this->gateway->fetchAll(
            'SELECT k.id, k.key_part, t.id AS translation_id, t.value, t.type
             FROM maa_i18n_keys k
             LEFT JOIN maa_i18n_translations t ON t.key_id = k.id AND t.language_code_identity = :identity
             WHERE k.scope = :scope AND k.domain = :domain
             ORDER BY k.key_part ASC',
            ([
                'identity' => LanguageCode::fromNullable($languageCode)->identity(),
                'scope' => $scope,
                'domain' => $domain,
            ]),
            'translation.readDomain',
        );

        return $this->mapDomainValues($rows);
    }

    /**
     * Skip the governance-only row of a denied or empty domain.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function mapDomainValues(array $rows): TranslationDomainTranslationsDTO
    {
        $translations = [];
        foreach ($rows as $row) {
            if (array_key_exists('id', $row) && $row['id'] === null) {
                continue;
            }

            $value = $this->valueForKeyRow($row);
            if ($value !== null) {
                $translations[Row::string($row, 'key_part')] = $value;
            }
        }

        return new TranslationDomainTranslationsDTO($translations);
    }

    /**
     * Preserve the stored identifier validation of the key and translation reads.
     *
     * @param array<string, mixed> $row
     */
    private function valueForKeyRow(array $row): ?TranslationValueDTO
    {
        Row::int($row, 'id');

        return Row::nullableInt($row, 'translation_id') === null
            ? null
            : new TranslationValueDTO(Row::string($row, 'value'), Row::nullableString($row, 'type'));
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
     * Acquire language slots 3–255 in order. Slots 0–2 coordinate summaries and ordering.
     * A duplicate no-op still takes the existing row's write lock until the active transaction ends.
     */
    private function lockLanguageWrites(LanguageCode ...$languages): void
    {
        $slots = array_map(static fn(LanguageCode $code): int => 3 + ord(hash('sha256', $code->identity(), true)[0]) % 253, $languages);
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

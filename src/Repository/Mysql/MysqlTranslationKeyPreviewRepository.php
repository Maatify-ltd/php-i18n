<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository\Mysql;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Exception\I18nStorageException;
use Maatify\I18n\DTO\TranslationKeyPreviewDTO;
use Maatify\I18n\Repository\TranslationKeyPreviewRepositoryInterface;
use Maatify\I18n\ValueObject\PreviewTarget;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use PDO;

/** MySQL storage for optional preview snapshots with atomic activation. */
final readonly class MysqlTranslationKeyPreviewRepository implements TranslationKeyPreviewRepositoryInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    private PdoGateway $gateway;
    private PdoSavepointTransactionRunner $transactions;
    private DateTimeZone $utc;

    public function __construct(PDO $pdo)
    {
        $this->gateway = new PdoGateway($pdo);
        $this->transactions = new PdoSavepointTransactionRunner($pdo);
        $this->utc = new DateTimeZone('UTC');
    }

    /** Resolves the published PDO constructor argument outside the service. @internal */
    public static function fromLegacyConnection(PDO $pdo): TranslationKeyPreviewRepositoryInterface
    {
        return new self($pdo);
    }

    /**
     * Insert and validate the generated identity in one transaction or savepoint.
     *
     * @param non-empty-list<PreviewTarget> $targets
     */
    public function insert(array $targets, DateTimeImmutable $expiresAt): TranslationKeyPreviewDTO
    {
        $rows = [];

        foreach ($targets as $target) {
            $rows[] = [$target->scope, $target->domain, $target->key];
        }

        $json = json_encode($rows, JSON_THROW_ON_ERROR);
        return $this->transactions->run(function () use ($json, $expiresAt, $targets): TranslationKeyPreviewDTO {
            $this->gateway->write(
                'INSERT INTO maa_i18n_translation_key_previews (targets, expires_at) VALUES (:targets, :expiry)',
                ['targets' => $json, 'expiry' => $expiresAt->format(self::DATE_FORMAT)],
                'preview.activate',
            );

            return new TranslationKeyPreviewDTO($this->gateway->lastInsertId('preview.activate'), $expiresAt, $targets);
        });
    }

    /** Loads an active snapshot. Missing, stopped, and expired previews return null. */
    public function getActive(int $id, DateTimeImmutable $now): ?TranslationKeyPreviewDTO
    {
        $row = $this->gateway->fetchOne(
            'SELECT targets, expires_at FROM maa_i18n_translation_key_previews WHERE id = :id AND expires_at > :now',
            ['id' => $id, 'now' => $now->setTimezone($this->utc)->format(self::DATE_FORMAT)],
            'preview.getActive',
        );

        if ($row === null) {
            return null;
        }

        $expiry = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, Row::string($row, 'expires_at'), $this->utc);

        if ($expiry === false) {
            throw new I18nStorageException('preview.hydrateExpiry');
        }

        return new TranslationKeyPreviewDTO($id, $expiry, $this->decodeTargets(Row::string($row, 'targets')));
    }

    /** Removes a preview by ID, returning whether a stored row was removed. */
    public function deactivate(int $id): bool
    {
        return $this->gateway->write(
            'DELETE FROM maa_i18n_translation_key_previews WHERE id = :id',
            ['id' => $id],
            'preview.deactivate',
        ) === 1;
    }

    /** Removes expired records and returns the number removed. Expiry needs no cleanup job. */
    public function purgeExpired(DateTimeImmutable $now): int
    {
        return $this->gateway->write(
            'DELETE FROM maa_i18n_translation_key_previews WHERE expires_at <= :now',
            ['now' => $now->setTimezone($this->utc)->format(self::DATE_FORMAT)],
            'preview.purgeExpired',
        );
    }

    /** @return non-empty-list<PreviewTarget> */
    private function decodeTargets(string $json): array
    {
        try {
            $rows = json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);

            if (!is_array($rows) || $rows === []) {
                throw new I18nStorageException('preview.hydrateTargets');
            }

            $targets = [];

            foreach ($rows as $row) {
                if (!is_array($row) || count($row) !== 3 || !is_string($row[0]) || !is_string($row[1])
                    || ($row[2] !== null && !is_string($row[2]))
                ) {
                    throw new I18nStorageException('preview.hydrateTargets');
                }

                $targets[] = new PreviewTarget($row[0], $row[1], $row[2]);
            }

            return $targets;
        } catch (JsonException|I18nInvalidArgumentException) {
            throw new I18nStorageException('preview.hydrateTargets');
        }
    }
}

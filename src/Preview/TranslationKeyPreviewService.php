<?php

declare(strict_types=1);

namespace Maatify\I18n\Preview;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Exception\I18nStorageException;
use Maatify\I18n\Repository\Mysql\PdoGateway;
use Maatify\I18n\Repository\Mysql\Row;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/** Manages optional MySQL preview storage without owning the Host transaction. */
final readonly class TranslationKeyPreviewService
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    private PdoGateway $gateway;
    private DateTimeZone $utc;

    public function __construct(PDO $pdo, private ClockInterface $clock)
    {
        $this->gateway = new PdoGateway($pdo);
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * Persists a new preview. Expiry is exclusive and must be in the future.
     *
     * @param array<array-key, mixed> $targets A non-empty list of PreviewTarget objects.
     */
    public function activate(array $targets, DateTimeImmutable $expiresAt): TranslationKeyPreview
    {
        if ($targets === [] || !array_is_list($targets)) {
            throw new I18nInvalidArgumentException('Preview targets must be a non-empty list.');
        }

        $expiresAt = $expiresAt->setTimezone($this->utc);

        if ($expiresAt <= $this->clock->now() || !preg_match('/^[1-9][0-9]{3}$/D', $expiresAt->format('Y'))) {
            throw new I18nInvalidArgumentException('Preview expiry must be a future date supported by MySQL.');
        }

        $rows = [];
        $selection = [];

        foreach ($targets as $target) {
            if (!$target instanceof PreviewTarget) {
                throw new I18nInvalidArgumentException('Each preview target must be a PreviewTarget.');
            }

            $selection[] = $target;
            $rows[] = [$target->scope, $target->domain, $target->key];
        }

        $this->gateway->write(
            'INSERT INTO maa_i18n_translation_key_previews (targets, expires_at) VALUES (:targets, :expiry)',
            ['targets' => json_encode($rows, JSON_THROW_ON_ERROR), 'expiry' => $expiresAt->format(self::DATE_FORMAT)],
            'preview.activate',
        );

        return new TranslationKeyPreview($this->gateway->lastInsertId('preview.activate'), $expiresAt, $selection);
    }

    /** Loads an active snapshot. Missing, stopped, and expired previews return null. */
    public function getActive(int $id): ?TranslationKeyPreview
    {
        if ($id <= 0) {
            throw I18nInvalidArgumentException::notPositive('previewId');
        }

        $row = $this->gateway->fetchOne(
            'SELECT targets, expires_at FROM maa_i18n_translation_key_previews WHERE id = :id AND expires_at > :now',
            ['id' => $id, 'now' => $this->currentTimestamp()],
            'preview.getActive',
        );

        if ($row === null) {
            return null;
        }

        $expiry = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, Row::string($row, 'expires_at'), $this->utc);

        if ($expiry === false) {
            throw new I18nStorageException('preview.hydrateExpiry');
        }

        return new TranslationKeyPreview($id, $expiry, $this->decodeTargets(Row::string($row, 'targets')));
    }

    /** Removes a preview by ID, returning whether a stored row was removed. */
    public function deactivate(int $id): bool
    {
        if ($id <= 0) {
            throw I18nInvalidArgumentException::notPositive('previewId');
        }

        return $this->gateway->write(
            'DELETE FROM maa_i18n_translation_key_previews WHERE id = :id',
            ['id' => $id],
            'preview.deactivate',
        ) === 1;
    }

    /** Removes expired records and returns the number removed. Expiry needs no cleanup job. */
    public function purgeExpired(): int
    {
        return $this->gateway->write(
            'DELETE FROM maa_i18n_translation_key_previews WHERE expires_at <= :now',
            ['now' => $this->currentTimestamp()],
            'preview.purgeExpired',
        );
    }

    private function currentTimestamp(): string
    {
        return $this->clock->now()->setTimezone($this->utc)->format(self::DATE_FORMAT);
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

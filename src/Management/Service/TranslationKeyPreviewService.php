<?php

declare(strict_types=1);

namespace Maatify\I18n\Management\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\I18n\DTO\TranslationKeyPreviewDTO;
use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Repository\Mysql\MysqlTranslationKeyPreviewRepository;
use Maatify\I18n\Repository\TranslationKeyPreviewRepositoryInterface;
use Maatify\I18n\ValueObject\PreviewTarget;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PDO;

/** Manages optional MySQL preview storage without owning the Host transaction. */
final readonly class TranslationKeyPreviewService
{
    private TranslationKeyPreviewRepositoryInterface $repository;
    private DateTimeZone $utc;

    /** Inject preview storage. A PDO argument supports the published RC.2 constructor. */
    public function __construct(TranslationKeyPreviewRepositoryInterface|PDO $pdo, private ClockInterface $clock)
    {
        $this->repository = $pdo instanceof PDO ? MysqlTranslationKeyPreviewRepository::fromLegacyConnection($pdo) : $pdo;
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * Persists a new preview. Expiry is exclusive and must be in the future.
     *
     * @param array<array-key, mixed> $targets A non-empty list of PreviewTarget objects.
     */
    public function activate(array $targets, DateTimeImmutable $expiresAt): TranslationKeyPreviewDTO
    {
        if ($targets === [] || !array_is_list($targets)) {
            throw new I18nInvalidArgumentException('Preview targets must be a non-empty list.');
        }

        $expiresAt = $expiresAt->setTimezone($this->utc);

        if ($expiresAt <= $this->clock->now() || !preg_match('/^[1-9][0-9]{3}$/D', $expiresAt->format('Y'))) {
            throw new I18nInvalidArgumentException('Preview expiry must be a future date supported by MySQL.');
        }

        $selection = [];

        foreach ($targets as $target) {
            if (!$target instanceof PreviewTarget) {
                throw new I18nInvalidArgumentException('Each preview target must be a PreviewTarget.');
            }

            $selection[] = $target;
        }

        return $this->repository->insert($selection, $expiresAt);
    }

    /** Loads an active snapshot. Missing, stopped, and expired previews return null. */
    public function getActive(int $id): ?TranslationKeyPreviewDTO
    {
        if ($id <= 0) {
            throw I18nInvalidArgumentException::notPositive('previewId');
        }

        return $this->repository->getActive($id, $this->clock->now());
    }

    /** Removes a preview by ID, returning whether a stored row was removed. */
    public function deactivate(int $id): bool
    {
        if ($id <= 0) {
            throw I18nInvalidArgumentException::notPositive('previewId');
        }

        return $this->repository->deactivate($id);
    }

    /** Removes expired records and returns the number removed. Expiry needs no cleanup job. */
    public function purgeExpired(): int
    {
        return $this->repository->purgeExpired($this->clock->now());
    }
}

<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository;

use DateTimeImmutable;
use Maatify\I18n\DTO\TranslationKeyPreviewDTO;
use Maatify\I18n\ValueObject\PreviewTarget;

/** Owns persistence and expiry of optional translation key preview snapshots. */
interface TranslationKeyPreviewRepositoryInterface
{
    /**
     * Persist a preview and return its validated identity atomically.
     *
     * @param non-empty-list<PreviewTarget> $targets
     */
    public function insert(array $targets, DateTimeImmutable $expiresAt): TranslationKeyPreviewDTO;

    /** Return an unexpired preview, or null after a successful miss. */
    public function getActive(int $id, DateTimeImmutable $now): ?TranslationKeyPreviewDTO;

    /** Return whether the stored preview was removed. */
    public function deactivate(int $id): bool;

    /** Remove previews whose exclusive deadline has passed and return their count. */
    public function purgeExpired(DateTimeImmutable $now): int;
}

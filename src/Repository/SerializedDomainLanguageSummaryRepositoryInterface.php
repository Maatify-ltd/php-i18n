<?php

declare(strict_types=1);

namespace Maatify\I18n\Repository;

/** Coordinates mutations of authoritative records with their derived summaries. */
interface SerializedDomainLanguageSummaryRepositoryInterface extends DomainLanguageSummaryRepositoryInterface
{
    /** Take the summary-write lock before changing authoritative rows, in the active transaction. */
    public function lockSummaryWrites(): void;
}

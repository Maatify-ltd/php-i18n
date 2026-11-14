<?php

declare(strict_types=1);

namespace Maatify\I18n\Preview;

use DateTimeImmutable;

/** An immutable preview snapshot to use within a single authorized request. */
final readonly class TranslationKeyPreview
{
    /** @var array<string, array<string, array<string, true>|true>> */
    private array $selection;

    /**
     * @internal Obtain snapshots from TranslationKeyPreviewService.
     *
     * @param non-empty-list<PreviewTarget> $targets
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $expiresAt,
        public array $targets,
    ) {
        $selection = [];

        foreach ($targets as $target) {
            $domainSelection = $selection[$target->scope][$target->domain] ?? [];

            if ($target->key === null) {
                $selection[$target->scope][$target->domain] = true;
            } elseif ($domainSelection !== true) {
                $domainSelection[$target->key] = true;
                $selection[$target->scope][$target->domain] = $domainSelection;
            }
        }

        $this->selection = $selection;
    }

    /** Reports whether the exact, case-sensitive render identity is selected. */
    public function matches(string $scope, string $domain, string $key): bool
    {
        $domainSelection = $this->selection[$scope][$domain] ?? [];

        return $domainSelection === true || isset($domainSelection[$key]);
    }

    /** Returns the display identity on a match and null otherwise. */
    public function labelFor(string $scope, string $domain, string $key): ?string
    {
        return $this->matches($scope, $domain, $key) ? $scope . '.' . $domain . '.' . $key : null;
    }

    /**
     * Replaces matching values in an already loaded domain map, preserving
     * its keys and all unaffected translations, including empty strings.
     *
     * @param array<array-key, string> $messages
     *
     * @return array<array-key, string>
     */
    public function applyToDomain(string $scope, string $domain, array $messages): array
    {
        foreach ($messages as $key => $value) {
            $messages[$key] = $this->labelFor($scope, $domain, (string) $key) ?? $value;
        }

        return $messages;
    }
}

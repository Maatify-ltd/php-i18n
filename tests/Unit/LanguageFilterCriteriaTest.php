<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Unit;

use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Management\Criteria\DomainKeySummaryCriteria;
use Maatify\I18n\Management\Criteria\DomainTranslationGridCriteria;
use PHPUnit\Framework\TestCase;

final class LanguageFilterCriteriaTest extends TestCase
{
    public function testInvalidLanguageListsAreRejectedAtEveryArrayBoundary(): void
    {
        foreach ([[null], [123], ['named' => 'ar'], ["\u{00A0}"], ["\xFF"]] as $values) {
            $factories = [
                'summary' => static fn() => new DomainKeySummaryCriteria('ct', 'home', $values),
                'grid' => static fn() => new DomainTranslationGridCriteria('ct', 'home', $values),
                'grid search' => static fn() => new DomainTranslationGridCriteria('ct', 'home', ['ar'], globalSearchLanguageCodes: $values),
            ];

            foreach ($factories as $boundary => $makeCriteria) {
                try {
                    $makeCriteria();
                    self::fail("Expected $boundary to reject the invalid language list.");
                } catch (I18nInvalidArgumentException $failure) {
                    self::assertStringContainsString('languagecodes', strtolower($failure->getMessage()));
                }
            }
        }
    }

    public function testEmptyListsAndValidExactCodesArePreserved(): void
    {
        $summary = new DomainKeySummaryCriteria('ct', 'home', []);
        $grid = new DomainTranslationGridCriteria('ct', 'home', ['ar ', 'AR'], globalSearchLanguageCodes: ['ar ']);

        self::assertSame([], $summary->languageCodes);
        self::assertSame(['ar ', 'AR'], $grid->languageCodes);
        self::assertSame(['ar '], $grid->globalSearchLanguageCodes);
    }
}

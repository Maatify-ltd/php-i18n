<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Integration;

use Maatify\I18n\Exception\I18nInvalidArgumentException;
use Maatify\I18n\Preview\PreviewTarget;
use Maatify\I18n\Preview\TranslationKeyPreviewService;
use Maatify\I18n\Tests\Support\MysqlIntegrationTestCase;
use Maatify\I18n\Tests\Support\PreviewTestClock;

final class TranslationKeyPreviewTest extends MysqlIntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $sql = file_get_contents(dirname(__DIR__, 2) . '/schema/translation-key-preview.sql');
        self::assertIsString($sql);
        self::$pdo?->exec($sql);
        self::$pdo?->exec($sql);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('TRUNCATE TABLE maa_i18n_translation_key_previews');
    }

    public function testRoundTripWithExactTargetsAndExpiry(): void
    {
        $clock = new PreviewTestClock();
        $service = new TranslationKeyPreviewService($this->pdo(), $clock);
        $expiry = $clock->now()->modify('+10 minutes');
        $created = $service->activate([
            new PreviewTarget('web', 'account', 'profile.name'),
            new PreviewTarget('web', 'checkout', 'one'),
            new PreviewTarget('web', 'checkout'),
            new PreviewTarget('web', 'checkout', 'two'),
        ], $expiry);

        $preview = $service->getActive($created->id);
        self::assertNotNull($preview);
        self::assertEquals($created->expiresAt, $preview->expiresAt);
        self::assertSame('UTC', $preview->expiresAt->getTimezone()->getName());
        self::assertSame('web.checkout.missing', $preview->labelFor('web', 'checkout', 'missing'));
        self::assertNull($preview->labelFor('other', 'checkout', 'missing'));
        self::assertNull($preview->labelFor('web', 'Checkout', 'missing'));
        self::assertNull($preview->labelFor('web', 'account', 'Profile.name'));
        self::assertSame(
            ['profile.name' => 'web.account.profile.name', 'cancel' => '', 'other' => 'Other'],
            $preview->applyToDomain('web', 'account', ['profile.name' => 'Name', 'cancel' => '', 'other' => 'Other']),
        );

        $clock->instant = $expiry;
        self::assertNull($service->getActive($created->id));
        self::assertSame(1, $service->purgeExpired());
        self::assertFalse($service->deactivate($created->id));
    }

    public function testDeactivationAndRollbackAreOwnedByTheHost(): void
    {
        $clock = new PreviewTestClock();
        $pdo = $this->pdo();
        $service = new TranslationKeyPreviewService($pdo, $clock);
        $targets = [new PreviewTarget('web', 'checkout')];
        $expiresAt = $clock->now()->modify('+5 minutes');
        $saved = $service->activate($targets, $expiresAt);

        $pdo->beginTransaction();
        try {
            $temporary = $service->activate($targets, $expiresAt);
            self::assertTrue($service->deactivate($saved->id));
            self::assertNull($service->getActive($saved->id));
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }

        self::assertNull($service->getActive($temporary->id));
        self::assertNotNull($service->getActive($saved->id));
        self::assertTrue($service->deactivate($saved->id));
        self::assertFalse($service->deactivate($saved->id));
        self::assertNull($service->getActive($saved->id));
    }

    public function testRejectsInvalidActivation(): void
    {
        $clock = new PreviewTestClock();
        $service = new TranslationKeyPreviewService($this->pdo(), $clock);

        foreach ([[], [1 => new PreviewTarget('web', 'home')], ['home']] as $invalid) {
            try {
                $service->activate($invalid, $clock->now()->modify('+5 minutes'));
                self::fail('Expected invalid targets to be rejected.');
            } catch (I18nInvalidArgumentException) {
                self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM maa_i18n_translation_key_previews'));
            }
        }

        $this->expectException(I18nInvalidArgumentException::class);
        $service->activate([new PreviewTarget('web', 'home')], $clock->now());
    }
}

<?php

declare(strict_types=1);

require __DIR__ . '/_support/bootstrap.php';

use Maatify\I18n\Management\Service\TranslationKeyPreviewService;
use Maatify\I18n\Repository\Mysql\MysqlTranslationKeyPreviewRepository;
use Maatify\I18n\ValueObject\PreviewTarget;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$pdo = example_pdo();
$schema = file_get_contents(dirname(__DIR__) . '/schema/translation-key-preview.sql');

if ($schema === false) {
    throw new RuntimeException('The optional preview schema is missing.');
}

$pdo->exec($schema);
$clock = new SystemClock(new DateTimeZone('UTC'));
$previewRepository = new MysqlTranslationKeyPreviewRepository($pdo);
$previews = new TranslationKeyPreviewService($previewRepository, $clock);

// The Host authorizes activation and selects this ID for an authorized request.
$created = $previews->activate([
    new PreviewTarget('web', 'checkout'),
    new PreviewTarget('web', 'account', 'profile.save'),
], $clock->now()->modify('+10 minutes'));

$preview = $previews->getActive($created->id);
example_expect('a selected key can be shown without a translation', 'web.checkout.submit', $preview?->labelFor('web', 'checkout', 'submit'));

$cached = ['profile.save' => 'Save', 'profile.cancel' => 'Cancel'];
example_expect(
    'the frontend map substitutes only the selected key after the cache',
    ['profile.save' => 'web.account.profile.save', 'profile.cancel' => 'Cancel'],
    $preview?->applyToDomain('web', 'account', $cached),
);
example_expect('the cached value stays intact', 'Save', $cached['profile.save']);
example_expect('deactivation succeeds', true, $previews->deactivate($created->id));
example_expect('the next request resolves no active preview', null, $previews->getActive($created->id));

<?php

declare(strict_types=1);

$floors = [
    '8.4' => ['8.4.27', '8.4.26'],
    '8.5' => ['8.5.12', '8.5.11'],
];

$fail = static function (string $message): never {
    fwrite(STDERR, "[i18n-php] $message\n");
    exit(1);
};

if (($argv[1] ?? '') === 'runtime') {
    $minor = $argv[2] ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    if (!isset($floors[$minor])
        || preg_match('/^' . preg_quote($minor, '/') . '\.\d+$/D', PHP_VERSION) !== 1
        || version_compare(PHP_VERSION, $floors[$minor][0], '<')
    ) {
        $fail("Expected a supported Stable PHP $minor patch, got " . PHP_VERSION);
    }

    fwrite(STDOUT, '[i18n-php] Verified Stable runtime ' . PHP_VERSION . "\n");
    exit(0);
}

if (($argv[1] ?? '') !== 'constraint') {
    $fail('Expected constraint or runtime verification.');
}

$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$constraint = $manifest['require']['php'] ?? null;
if (!is_string($constraint) || $constraint === '') {
    $fail('composer.json must declare a PHP constraint.');
}

$directory = sys_get_temp_dir() . '/i18n-php-constraint-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    $fail('Could not create the isolated Composer project.');
}

register_shutdown_function(static function () use ($directory): void {
    foreach (['composer.json', 'composer.lock', '.htaccess'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory);
});

$expectations = [
    '8.3.99' => false,
    '8.6.0' => false,
    '9.0.0' => false,
];
foreach ($floors as [$floor, $previous]) {
    $expectations[$floor] = true;
    $expectations[$previous] = false;
}

foreach ($expectations as $version => $expected) {
    $probe = [
        'name' => 'maatify/i18n-php-constraint-probe',
        'version' => '1.0.0',
        'require' => ['php' => $constraint],
        'config' => ['platform' => ['php' => $version]],
        'repositories' => [['packagist.org' => false]],
    ];
    $json = json_encode($probe, JSON_THROW_ON_ERROR);
    if (file_put_contents($directory . '/composer.json', $json) !== strlen($json)) {
        $fail('Could not write the Composer platform probe.');
    }

    $output = [];
    exec(
        'COMPOSER_DISABLE_NETWORK=1 COMPOSER_HOME=' . escapeshellarg($directory)
        . ' COMPOSER=' . escapeshellarg($directory . '/composer.json')
        . ' composer --no-cache --working-dir=' . escapeshellarg($directory)
        . ' update --dry-run --no-install --no-interaction --no-plugins --no-scripts --no-audit --no-progress 2>&1',
        $output,
        $status,
    );

    if ($status !== 0 && ($status !== 2 || !str_contains(implode("\n", $output), 'your php version'))) {
        $fail("Unexpected Composer failure for simulated PHP $version:\n" . implode("\n", $output));
    }

    if (($status === 0) !== $expected) {
        $wanted = $expected ? 'acceptance' : 'rejection';
        $fail("Expected $wanted for simulated PHP $version under $constraint.");
    }

    $result = $expected ? 'accepted' : 'rejected';
    fwrite(STDOUT, "[i18n-php] Composer $result simulated PHP $version\n");
}

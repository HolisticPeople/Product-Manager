<?php

declare(strict_types=1);

function hp_pm_absence_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * @return array{exit:int,output:string}
 */
function hp_pm_absence_run(string $program): array
{
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program) . ' 2>&1';
    $lines = [];
    $exit = 0;
    exec($command, $lines, $exit);
    return ['exit' => $exit, 'output' => implode("\n", $lines)];
}

$provider = dirname(__DIR__) . '/includes/class-hp-pm-product-editorial-gallery-source-provider.php';
$providerLiteral = var_export($provider, true);

$sourceUnavailable = hp_pm_absence_run(<<<PHP
define('ABSPATH', __DIR__ . '/');
require {$providerLiteral};
HP_PM_Product_Editorial_Gallery_Source_Provider::register();
HP_PM_Product_Editorial_Gallery_Source_Provider::register_provider();
echo json_encode(HP_PM_Product_Editorial_Gallery_Source_Provider::payload(
    HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY,
    ['consumer' => 'hp-catalog', 'product_id' => 109731]
));
PHP);
hp_pm_absence_assert($sourceUnavailable['exit'] === 0, 'Absent WordPress/HP-Core APIs must not make registration or payload resolution fatal.');
$sourcePayload = json_decode($sourceUnavailable['output'], true);
hp_pm_absence_assert(
    is_array($sourcePayload)
        && ($sourcePayload['state'] ?? '') === 'error'
        && ($sourcePayload['error']['code'] ?? '') === 'hp_pm_editorial_gallery_source_unavailable',
    'Absent WordPress source APIs must return the exact public-safe array diagnostic.'
);

$acfUnavailable = hp_pm_absence_run(<<<PHP
define('ABSPATH', __DIR__ . '/');
function get_post(int \$id): object { return (object) ['ID' => \$id, 'post_type' => 'product', 'post_status' => 'publish']; }
function get_post_meta(int \$id, string \$key, bool \$single = false): mixed { return \$key === 'product_type_hp' ? 'book_type' : []; }
require {$providerLiteral};
echo json_encode(HP_PM_Product_Editorial_Gallery_Source_Provider::payload(
    HP_PM_Product_Editorial_Gallery_Source_Provider::CAPABILITY,
    ['consumer' => 'hp-catalog', 'product_id' => 109731]
));
PHP);
hp_pm_absence_assert($acfUnavailable['exit'] === 0, 'Absent ACF registry APIs must not make payload resolution fatal.');
$acfPayload = json_decode($acfUnavailable['output'], true);
hp_pm_absence_assert(
    is_array($acfPayload)
        && ($acfPayload['state'] ?? '') === 'error'
        && ($acfPayload['error']['code'] ?? '') === 'hp_pm_editorial_gallery_field_registry_unavailable',
    'Absent ACF registry APIs must return the exact public-safe array diagnostic.'
);

foreach ([$sourcePayload, $acfPayload] as $failure) {
    $encoded = (string) json_encode($failure);
    hp_pm_absence_assert(!str_contains($encoded, 'product_magazine_gallery'), 'Failure envelopes must not expose private field names.');
    hp_pm_absence_assert(($failure['attachment_ids'] ?? null) === [], 'Failure envelopes must expose no partial source IDs.');
}

fwrite(STDOUT, "Product editorial-gallery runtime absence test passed.\n");

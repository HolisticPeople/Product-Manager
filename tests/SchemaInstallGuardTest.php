<?php
declare(strict_types=1);

$plugin = file_get_contents(__DIR__ . '/../products-manager.php');

function hp_pm_schema_guard_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

hp_pm_schema_guard_assert(is_string($plugin), 'Unable to read products-manager.php');

hp_pm_schema_guard_assert(strpos($plugin, "private const ERP_SCHEMA_VERSION") !== false, 'ERP schema installs must use an explicit schema version.');
hp_pm_schema_guard_assert(strpos($plugin, "get_option('hp_pm_erp_schema_version'") !== false, 'ERP schema installs must read the stored schema version before running dbDelta.');
hp_pm_schema_guard_assert(strpos($plugin, '$installed_version === self::ERP_SCHEMA_VERSION') !== false, 'ERP schema installs must skip dbDelta when the stored schema version is current.');
hp_pm_schema_guard_assert(strpos($plugin, "update_option('hp_pm_erp_schema_version', self::ERP_SCHEMA_VERSION, false)") !== false, 'ERP schema installs must persist the schema version after dbDelta succeeds.');
hp_pm_schema_guard_assert(strpos($plugin, "delete_option('hp_pm_erp_schema_version')") !== false, 'ERP schema install state must be removed on uninstall.');

echo "SchemaInstallGuardTest passed\n";

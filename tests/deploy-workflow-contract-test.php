<?php
declare(strict_types=1);

$workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');

function hp_pm_deploy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$wordpress_root_resolution = 'WP_PATH="$(dirname "$(dirname "$(dirname "$PLUGIN_PATH")")")"';
$wp_content_resolution = 'WP_PATH="$(dirname "$(dirname "$PLUGIN_PATH")")"';

hp_pm_deploy_assert(
    substr_count($workflow, $wordpress_root_resolution) === 3,
    'Every plugin-path-derived WP_PATH must traverse plugins, wp-content, and reach the WordPress root.'
);
hp_pm_deploy_assert(
    !str_contains($workflow, $wp_content_resolution),
    'The deploy workflow must not stop WP_PATH at wp-content.'
);
hp_pm_deploy_assert(
    str_contains($workflow, 'wp --path="$WP_PATH" kinsta cache purge --all'),
    'Production deploys must purge Kinsta page cache from the resolved WordPress root.'
);

// P4/P4b (2026-10-30): the staging job respects per-plugin staging leases
// before its first host step; production is never lease-gated.
$hp_pm_parts = explode('  deploy-production:', $workflow, 2);
$hp_pm_staging = $hp_pm_parts[0];
$hp_pm_production = $hp_pm_parts[1] ?? '';
$hp_pm_lease_at = strpos($hp_pm_staging, '- name: Respect active staging leases');
hp_pm_deploy_assert(
    $hp_pm_lease_at !== false && str_contains($hp_pm_staging, '- name: Test staging lease check'),
    'The staging job must self-test and run the staging lease check.'
);
foreach (['- name: Load SSH key', '- name: Ensure plugin dir exists', '- name: Rsync plugin files'] as $hp_pm_host_step) {
    $hp_pm_step_at = strpos($hp_pm_staging, $hp_pm_host_step);
    hp_pm_deploy_assert(
        $hp_pm_step_at !== false && $hp_pm_step_at > $hp_pm_lease_at,
        'The staging lease check must precede ' . $hp_pm_host_step . '.'
    );
}
hp_pm_deploy_assert(
    str_contains($hp_pm_staging, '"python3 - --plugin products-manager" < .github/hp-deploy/staging-lease-check.py'),
    'The staging lease check must name the products-manager slug.'
);
hp_pm_deploy_assert(
    $hp_pm_production !== '' && !str_contains($hp_pm_production, 'staging-lease'),
    'Production deploys must never be gated on staging leases.'
);

// Canonical two-job guard (2026-10-31 side-findings sweep): a production
// dispatch from the dev ref must never also run staging, so the staging
// auto-trigger is scoped to push events; production runs only on dispatch.
$hp_pm_norm = static fn(string $s): string => (string) preg_replace('/\s+/', ' ', $s);
hp_pm_deploy_assert(
    preg_match('/deploy-staging:\s*\n\s*if:\s*\|?\s*\n?(.*?)\n\s*name:/s', $hp_pm_staging, $hp_pm_guard) === 1,
    'The staging job must declare an if: guard.'
);
hp_pm_deploy_assert(
    trim($hp_pm_norm($hp_pm_guard[1])) === "(github.event_name == 'push' && github.ref_name == 'dev') || (github.event_name == 'workflow_dispatch' && inputs.deploy == 'staging')",
    'The staging guard must be the canonical push-scoped dev guard OR a staging dispatch.'
);
hp_pm_deploy_assert(
    preg_match("/^\s*if: github.event_name == 'workflow_dispatch' && inputs.deploy == 'production'\s*$/m", $hp_pm_production) === 1,
    'The production job must run only on a production dispatch.'
);

echo "Deploy workflow contract passed\n";

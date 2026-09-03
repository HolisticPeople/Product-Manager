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

echo "Deploy workflow contract passed\n";

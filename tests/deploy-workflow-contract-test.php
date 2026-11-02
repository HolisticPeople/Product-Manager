<?php
/** Workflow-to-performer coupling; command behavior is exercised by the offline Python suite. */
declare(strict_types=1);
$workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');
function hp_pm_deploy_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
$parts = explode('  deploy-production:', $workflow, 2); $staging = $parts[0]; $production = $parts[1] ?? '';
foreach ([['body' => $staging, 'environment' => 'staging'], ['body' => $production, 'environment' => 'production']] as $job) {
    hp_pm_deploy_assert(str_contains($job['body'], 'group: hp-products-manager-' . $job['environment']) && str_contains($job['body'], 'cancel-in-progress: false'), 'Each native target must have its fixed non-cancelling mutation group.');
    hp_pm_deploy_assert(str_contains($job['body'], 'python3 -B .github/hp-deploy/deploy.py ' . $job['environment']), 'Each native job must invoke the tested selected-revision performer.');
    hp_pm_deploy_assert(str_contains($job['body'], 'python3 -B .github/hp-deploy/test-deployment-controls.py'), 'Native jobs must retain their offline control checks.');
    hp_pm_deploy_assert(str_contains($job['body'], "python-version: '3.12'"), 'Native jobs must pin the same qualified archive runtime as source CI.');
}
hp_pm_deploy_assert(str_contains($production, 'ref: ${{ inputs.expected_main_sha }}') && str_contains($production, 'PM_EXPECTED_TREE: ${{ inputs.expected_main_tree }}'), 'Production checkout and performer must bind explicit expected main SHA AND full tree.');
hp_pm_deploy_assert(str_contains($production, 'test "$GITHUB_REF" = refs/heads/main') && str_contains($production, 'test "$GITHUB_SHA" = "$PM_EXPECTED_SHA"'), 'Production admission must require the selected main dispatch.');
hp_pm_deploy_assert(str_contains($production, 'PM_EXPECTED_SITE: ${{ inputs.expected_site_url }}') && str_contains($production, 'PM_EXPECTED_CURRENT_VERSION: ${{ inputs.expected_current_version }}'), 'Production must bind exact target site and existing active-version baseline.');
hp_pm_deploy_assert(str_contains($staging, 'ref: ${{ github.sha }}'), 'Staging checkout must pin its immutable selected event revision.');
hp_pm_deploy_assert(str_contains($staging, 'Respect active staging leases') && str_contains($staging, '"python3 - --plugin products-manager" < .github/hp-deploy/staging-lease-check.py'), 'Staging must retain the exact-plugin lease check.');
hp_pm_deploy_assert(!str_contains($production, 'staging-lease'), 'Production is not governed by staging leases.');
hp_pm_deploy_assert(!str_contains($workflow, 'appleboy/ssh-action') && !str_contains($workflow, 'rsync -') && !str_contains($workflow, '|| true'), 'The workflow may not introduce unguarded copy/host writer or swallowed required effects outside the performer.');
hp_pm_deploy_assert(!str_contains($workflow, 'ignore_staging_leases'), 'No lease bypass is part of this control successor.');
echo "Deployment workflow/performer coupling passed\n";

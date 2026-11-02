<?php
/** Private eval-file observer: reuse the reviewed owner's existing read API.
 * No bootstrap, private metadata access, extra getter, FD transfer or hook here.
 * NativeCLIReader retains full stdout privately before interpreting it.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || WP_CLI !== true
    || !class_exists('HP_PM_Actual_Preinit_Guard', false)
    || !is_callable(['HP_PM_Actual_Preinit_Guard', 'read_code_before'])) {
    throw new RuntimeException('reviewed_actual_preinit_context_unavailable');
}
$frame = HP_PM_Actual_Preinit_Guard::read_code_before();
if (!is_array($frame) || ($frame['context'] ?? null) !== 'full-native'
    || ($frame['projection'] ?? null) !== 'code-before'
    || ($frame['executionAuthority'] ?? null) !== false
    || ($frame['disposition'] ?? null) !== 'PRE_FIELD_CLEAR') {
    throw new RuntimeException('owner_actual_native_projection_incomplete');
}
echo json_encode(['contract' => 'hp_pm_three_source_observation', 'version' => 1,
    'context' => 'cli_code', 'state' => 'COMPLETE', 'disposition' => $frame['disposition'],
    'projection' => $frame], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

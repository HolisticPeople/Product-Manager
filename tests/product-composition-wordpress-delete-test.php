<?php
/** Runs the unmodified WordPress7.1 delete_metadata function with a synthetic database. */
declare(strict_types=1);
$metaPath = getenv('WP_CORE_META_FILE');
if (!is_string($metaPath) || !is_file($metaPath)) { fwrite(STDERR, "WP_CORE_META_FILE must name the qualified WordPress7.1 wp-includes/meta.php capture.\n"); exit(2); }
if (hash_file('sha256', $metaPath) !== 'b1b49d1d3ddbf10d0f17286f53c30ce9582c636a4679f9a0ef92ee5ebb020a3e') { throw new RuntimeException('Expected exact WordPress7.1 source bytes.'); }
$GLOBALS['fixtureOnly'] = true; require __DIR__ . '/product-composition-import-test.php';
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
function _get_meta_table($type) { return $type === 'post' ? 'synthetic_postmeta' : false; }
function maybe_serialize($value) { return is_array($value) || is_object($value) ? serialize($value) : $value; }
function do_action(...$args) {}
function wp_cache_delete_multiple(...$args) {}
$GLOBALS['wpdb'] = new class {
    private int $serial = 0; private array $prepared = []; private array $rows = [];
    public function prepare($query, ...$args) { $id = ++$this->serial; $this->prepared[$id] = [$query, $args]; return '__prepared_' . $id . '__'; }
    public function get_col($query) {
        preg_match_all('/__prepared_(\d+)__/', $query, $matches); $key = ''; $id = 0; $expected = null; $conditional = false;
        foreach ($matches[1] as $serial) { [$sql, $args] = $this->prepared[(int) $serial]; if (str_contains($sql, 'WHERE meta_key')) { $key = $args[0]; } elseif (str_contains($sql, 'post_id')) { $id = $args[0]; } elseif (str_contains($sql, 'meta_value')) { $conditional = true; $expected = $args[0]; } }
        if (!metadata_exists('post', $id, $key) || ($conditional && maybe_serialize(get_post_meta($id, $key, true)) !== $expected)) { return []; }
        $row = ++$this->serial; $this->rows[$row] = [$id, $key]; return [$row];
    }
    public function query($query) {
        if (!preg_match('/^DELETE FROM synthetic_postmeta WHERE meta_id IN\( ([0-9,]+) \)$/', $query, $match)) { throw new RuntimeException('Unexpected synthetic DB mutation.'); }
        $count = 0; foreach (explode(',', $match[1]) as $row) { [$id, $key] = $this->rows[(int) $row]; if (metadata_exists('post', $id, $key)) { unset($GLOBALS['meta'][$id][$key]); ++$GLOBALS['writes']; ++$count; } } return $count;
    }
};
// Extract only the upstream function declaration; execute its body without rewriting it.
$tokens = token_get_all((string) file_get_contents($metaPath)); $source = ''; $capturing = false; $opened = false; $depth = 0;
foreach ($tokens as $offset => $token) {
    if (!$capturing && is_array($token) && $token[0] === T_FUNCTION) { $next = $offset + 1; while (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { ++$next; } if (is_array($tokens[$next]) && $tokens[$next][1] === 'delete_metadata') { $capturing = true; } }
    if (!$capturing) { continue; }
    $source .= is_array($token) ? $token[1] : $token;
    if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { ++$depth; }
    elseif ($token === '{') { $opened = true; ++$depth; } elseif ($token === '}') { --$depth; if ($opened && $depth === 0) { break; } }
}
if (!$opened || $depth !== 0 || $source === '') { throw new RuntimeException('Exact upstream function extraction failed.'); }
eval($source); $GLOBALS['useActualDeleteMetadata'] = true;
reset_fixture(); $p = packet(); HP_PM_Reviewed_Product_Import::run($p, 'apply');
$quoted = get_post_meta(101, 'natural_constituents', true); $beforeWrites = $writes;
check(str_contains($quoted[0]['sourceName'], '\\'), 'regression includes actual literal backslash');
check(delete_post_meta(101, 'natural_constituents', $quoted) === false && get_post_meta(101, 'natural_constituents', true) === $quoted && $writes === $beforeWrites, 'upstream function reproduces raw conditional deletion refusal without mutation');
// Model the retained native failure: later row restored, natural pointer already absent, actual value retained.
$key = array_key_first($options); $options[$key]['phase'] = 'rollback_intent'; $options[$key]['rows']['ingredients']['phase'] = 'restored'; $meta[101]['ingredients'] = 'old oil'; unset($meta[101]['_natural_constituents']);
\HP_Catalog\Product_Source_Update::$pending[101] = $p['operationId'];
$result = HP_PM_Reviewed_Product_Import::run($p, 'rollback');
check($result['status'] === 'rolled_back' && !metadata_exists('post', 101, 'natural_constituents') && !metadata_exists('post', 101, '_natural_constituents'), 'fixed operator uses upstream deletion to recover exact before absence despite missing pointer');
check(!\HP_Catalog\Product_Source_Update::active(101), 'verified scoped inverse completes held source boundary');
$n = $writes; $o = $options; check(HP_PM_Reviewed_Product_Import::run($p, 'rollback')['status'] === 'noop_restored' && $writes === $n && $options === $o, 'restored repeat makes zero native/owner journal writes');
reset_fixture(); $p = packet(); HP_PM_Reviewed_Product_Import::run($p, 'apply'); $meta[101]['natural_constituents'][0]['sourceName'] = 'foreign \\ editorial value'; $n = $writes;
refuses(fn() => HP_PM_Reviewed_Product_Import::run($p, 'rollback'), 'foreign conditional target still refuses'); check($writes === $n, 'comparison encoding fix never broadens inverse ownership');
echo $checks . " exact WordPress7.1 conditional-delete/operator checks passed; native DB remains synthetic\n";

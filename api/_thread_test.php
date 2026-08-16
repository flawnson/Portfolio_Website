<?php
declare(strict_types=1);

// Load the real micro-posts.php functions without its config load / web dispatch.
define('MICRO_POSTS_TEST', true);
require __DIR__ . '/micro-posts.php';

$fail = 0;
function check(string $label, $got, $expected): void {
    global $fail;
    $ok = ($got === $expected);
    if (!$ok) { $fail++; }
    echo ($ok ? "PASS " : "FAIL ") . $label . "\n";
    if (!$ok) {
        echo "   expected: " . json_encode($expected) . "\n";
        echo "   got:      " . json_encode($got) . "\n";
    }
}

// Every chunk within limit AND a faithful (whitespace-insensitive) reconstruction.
function assert_valid_split(string $label, string $body, int $limit): void {
    global $fail;
    $chunks = deterministic_split($body, $limit);
    $within = true;
    foreach ($chunks as $c) {
        if (mb_strlen($c) > $limit) { $within = false; }
    }
    $faithful = split_is_faithful($body, $chunks, $limit);
    $ok = $within && $faithful && count($chunks) >= 1;
    if (!$ok) { $fail++; }
    echo ($ok ? "PASS " : "FAIL ") . $label
        . " (chunks=" . count($chunks) . ", maxlen=" . max(array_map('mb_strlen', $chunks)) . ")\n";
    if (!$ok) {
        echo "   within=" . json_encode($within) . " faithful=" . json_encode($faithful) . "\n";
        echo "   chunks=" . json_encode($chunks, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "=== 1. platform_char_limit (defaults + config override) ===\n";
check('x default 280', platform_char_limit('x'), 280);
check('bluesky default 300', platform_char_limit('bluesky'), 300);
check('threads default 500', platform_char_limit('threads'), 500);
$GLOBALS['xCharLimit'] = 25000;
check('x reads config override', platform_char_limit('x'), 25000);
unset($GLOBALS['xCharLimit']);

echo "\n=== 2. Within-limit body returns a single chunk ===\n";
$short = "Just a short thought.";
check('single chunk', split_text_for_limit($short, 280), [$short]);

echo "\n=== 3. deterministic_split: within limit + faithful ===\n";
$long = "Rare disease software has to optimize for trust before growth. "
      . "You earn it slowly: clear data provenance, conservative claims, and a willingness "
      . "to say 'we don't know yet'. Patients and clinicians can tell when a tool respects "
      . "the weight of the decisions it's informing, and that respect compounds over time.";
assert_valid_split('long text @280', $long, 280);
assert_valid_split('long text @300', $long, 300);
assert_valid_split('long text @100', $long, 100);
assert_valid_split('tiny limit @15', $long, 15);

echo "\n=== 4. Faithfulness rejects rewriting, accepts whitespace-only boundary change ===\n";
$orig = "alpha beta gamma";
check('split at spaces is faithful', split_is_faithful($orig, ['alpha beta', 'gamma'], 280), true);
check('reworded is NOT faithful', split_is_faithful($orig, ['alpha beta', 'delta'], 280), false);
check('added counter is NOT faithful', split_is_faithful($orig, ['alpha beta gamma 1/2'], 280), false);
check('over-limit chunk is NOT faithful', split_is_faithful($orig, ['alpha beta gamma'], 5), false);
check('dropped word is NOT faithful', split_is_faithful($orig, ['alpha', 'beta'], 280), false);

echo "\n=== 5. A single word longer than the limit is hard-split ===\n";
$bigWord = str_repeat('x', 50) . " then short";
$parts = deterministic_split($bigWord, 20);
$within = true;
foreach ($parts as $p) { if (mb_strlen($p) > 20) { $within = false; } }
check('hard-split stays within limit', $within, true);
check('hard-split is faithful', split_is_faithful($bigWord, $parts, 20), true);

echo "\n=== 6. Multibyte safety (emoji) ===\n";
$emoji = str_repeat("🍵 tea ", 30); // multibyte
assert_valid_split('emoji text @50', trim($emoji), 50);

echo "\n=== 7. split_text_for_limit falls back to deterministic when Gemini absent ===\n";
// No geminiApiKey in test mode -> gemini_split_text returns null -> deterministic.
$chunks = split_text_for_limit($long, 280);
$within = true;
foreach ($chunks as $c) { if (mb_strlen($c) > 280) { $within = false; } }
check('fallback produced valid chunks', $within && split_is_faithful($long, $chunks, 280) && count($chunks) > 1, true);

echo "\n=== 8. normalize_syndication_choice (incl. multi-platform forcing) ===\n";
check('empty -> auto', normalize_syndication_choice(''), 'auto');
check('auto -> auto', normalize_syndication_choice('auto'), 'auto');
check('none -> none', normalize_syndication_choice('none'), 'none');
check('off -> none', normalize_syndication_choice('off'), 'none');
check('single platform', normalize_syndication_choice('threads'), 'threads');
check('multi platform', normalize_syndication_choice('x,threads'), 'x,threads');
check('multi platform reordered to canonical', normalize_syndication_choice('bluesky,x'), 'x,bluesky');
check('all three', normalize_syndication_choice('threads,bluesky,x'), 'x,threads,bluesky');
check('dupes collapse', normalize_syndication_choice('x,x'), 'x');
check('whitespace tolerated', normalize_syndication_choice(' x , threads '), 'x,threads');
check('unknown token -> auto', normalize_syndication_choice('x,myspace'), 'auto');
check('garbage -> auto', normalize_syndication_choice('banana'), 'auto');

echo "\n=== 9. successful_syndication records only platforms that published ===\n";
$results = [
    'x' => ['ok' => true, 'post_id' => ['post_id' => '123']],
    'threads' => ['ok' => false, 'post_id' => null],
];
$succ = successful_syndication($results);
check('failed platform excluded from labels', $succ['platforms'], ['x']);
check('failed platform excluded from ids', array_keys($succ['ids']), ['x']);
$succ = successful_syndication(['threads' => ['ok' => false, 'post_id' => null]]);
check('all-failed -> no labels', $succ['platforms'], []);
$succ = successful_syndication([
    'x' => ['ok' => true, 'post_id' => ['post_id' => '1']],
    'bluesky' => ['ok' => true, 'post_id' => ['uri' => 'at://a/b/c', 'cid' => 'z']],
]);
check('all-ok -> both recorded', $succ['platforms'], ['x', 'bluesky']);

echo "\n" . ($fail === 0 ? "ALL PASSED ✅" : "{$fail} FAILED ❌") . "\n";
exit($fail === 0 ? 0 : 1);

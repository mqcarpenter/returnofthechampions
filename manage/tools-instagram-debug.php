<?php
/**
 * CLI only: php manage/tools-instagram-debug.php [--clear] [--config=/path/config.php]
 * Shows Instagram feed cache age + last error, does one live fetch bypassing
 * the cache, prints the newest post date. Never prints the token.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts = getopt('', ['clear', 'config::']);
$root = realpath(__DIR__ . '/..');
$_SERVER['DOCUMENT_ROOT'] = $root;
$cfg = $opts['config'] ?? (getenv('ROTC_CONFIG_PATH') ?: dirname($root) . '/config.php');
if (!is_file($cfg)) { fwrite(STDERR, "config.php not found at $cfg (use --config=)\n"); exit(1); }
require_once $cfg;
require_once __DIR__ . '/includes/instagram-feed.php';

$dir = rotc_instagram_cache_dir();
echo "cache dir: $dir\n";
if (isset($opts['clear'])) {
    foreach (glob($dir . '/instagram-*') ?: [] as $f) { @unlink($f); echo "removed " . basename($f) . "\n"; }
}
echo "before: " . json_encode(rotc_instagram_debug_info(7), JSON_PRETTY_PRINT) . "\n";
foreach (glob($dir . '/instagram-*.json') ?: [] as $f) {
    if (basename($f) !== 'instagram-status.json') @unlink($f);   // force a live fetch
}
rotc_instagram_status_write(['last_fail_at' => 0]);
$posts = rotc_fetch_instagram_posts(7);
echo "live fetch: " . count($posts) . " posts\n";
if ($posts) echo "newest post: " . date('c', $posts[0]['time']) . "  " . $posts[0]['url'] . "\n";
echo "after: " . json_encode(rotc_instagram_debug_info(7), JSON_PRETTY_PRINT) . "\n";

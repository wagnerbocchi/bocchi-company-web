<?php
// Run: php tests/security-smoke.php [normal|unsafe-dir|unsafe-salt|stale-counter|turnstile-config]
$mode = isset($argv[1]) ? $argv[1] : 'normal';
$root = sys_get_temp_dir() . '/bocchi-security-' . bin2hex(random_bytes(8));
$public = $root . '/public_html';
mkdir($public, 0700, true);
copy(dirname(__DIR__) . '/antispam.php', $public . '/antispam.php');
$preferred = $root . '/.bocchi-state';

if ($mode === 'unsafe-dir') {
    mkdir($preferred, 0700);
    chmod($preferred, 0777);
} elseif ($mode === 'unsafe-salt') {
    mkdir($preferred, 0700);
    file_put_contents($root . '/outside', 'attacker-controlled-salt');
    symlink($root . '/outside', $preferred . '/.salt');
}

define('BOCCHI_SEND', true);
require $public . '/antispam.php';

function check($ok, $message) {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

if ($mode === 'unsafe-dir') {
    check(bocchi_rate_dir() !== $preferred, 'unsafe directory accepted');
} elseif ($mode === 'unsafe-salt') {
    check(bocchi_rate_salt() === false, 'symlink salt accepted');
    check(bocchi_token_issue() === '', 'token issued with unsafe salt');
    check(bocchi_rate_ok('test', 1, 3600) === null, 'rate limit failed open');
} elseif ($mode === 'normal') {
    $token = bocchi_token_issue();
    check($token !== '', 'token not issued');
    $nonce = bocchi_token_verify($token, 43200);
    check(is_string($nonce), 'valid token rejected');
    check(bocchi_rate_ok('test', 1, 3600) === true, 'first event rejected');
    check(bocchi_rate_ok('test', 1, 3600) === false, 'rate limit bypassed');
    check(bocchi_nonce_claim($nonce, 43200) === true, 'nonce rejected');
    check(bocchi_nonce_claim($nonce, 43200) === false, 'nonce replay accepted');
    check((fileperms($preferred . '/.salt') & 0077) === 0, 'salt permissions too broad');
} elseif ($mode === 'stale-counter') {
    check(bocchi_rate_ok('test', 1, 3600) === true, 'rate setup failed');
    $file = $preferred . '/' . hash_hmac('sha256', 'test', bocchi_rate_salt());
    touch($file, time() - 3700);
    mt_srand(1);
    for ($i = 0; $i < 300 && file_exists($file); $i++) { bocchi_nonce_gc(43200); }
    check(!file_exists($file), 'stale counter not collected');
} elseif ($mode === 'turnstile-config') {
    copy(dirname(__DIR__) . '/turnstile.php', $public . '/turnstile.php');
    require $public . '/turnstile.php';
    check(bocchi_turnstile_verify('', '127.0.0.1') === false, 'empty challenge accepted');
    check(bocchi_turnstile_verify('test-token', '127.0.0.1') === null, 'missing secret accepted');
} else {
    fwrite(STDERR, "FAIL: unknown mode\n");
    exit(1);
}

echo "OK: $mode\n";

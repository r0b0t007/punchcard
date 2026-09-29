<?php

/**
 * Stop hook: before Claude finishes, run the PHP test suite if PHP files changed.
 * On failure it blocks the stop (exit 2) and hands the failures back to Claude.
 * It blocks at most twice per session so an unfixable failure cannot loop forever.
 */
$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$root = getenv('CLAUDE_PROJECT_DIR') ?: dirname(__DIR__, 2);
chdir($root);

if (! is_file('vendor/autoload.php') || ! is_dir('.git')) {
    exit(0); // not bootstrapped yet
}

// Only run when PHP (or test) files are modified or untracked.
exec('git status --porcelain', $status, $gitCode);
if ($gitCode !== 0) {
    exit(0);
}
$phpChanged = array_filter($status, fn (string $line) => (bool) preg_match('/\.(php|neon|xml)$/', trim($line)));
if ($phpChanged === []) {
    exit(0);
}

// Loop guard: count blocks per session in the system temp dir.
$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['session_id'] ?? 'unknown'));
$counterFile = sys_get_temp_dir().DIRECTORY_SEPARATOR."punchcard-stop-{$session}.count";
$blocks = is_file($counterFile) ? (int) file_get_contents($counterFile) : 0;
if ($blocks >= 2) {
    exit(0);
}

$cmd = escapeshellarg(PHP_BINARY).' artisan test --stop-on-failure 2>&1';
exec($cmd, $output, $code);

if ($code === 0) {
    @unlink($counterFile);
    exit(0);
}

file_put_contents($counterFile, (string) ($blocks + 1));
$tail = implode(PHP_EOL, array_slice($output, -60));
fwrite(STDERR, "PHP tests are failing. Fix them before finishing (do not weaken or delete tests).\n\n{$tail}\n");
exit(2);

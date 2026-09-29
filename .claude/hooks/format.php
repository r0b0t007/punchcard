<?php

/**
 * PostToolUse hook: format the file Claude just edited.
 * PHP files go through Pint, frontend files through vite-plus (oxfmt).
 * Never blocks: formatting problems surface later in CI and the Stop hook.
 */
$input = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$file = $input['tool_input']['file_path'] ?? null;
$root = getenv('CLAUDE_PROJECT_DIR') ?: dirname(__DIR__, 2);

if (! is_string($file) || ! is_file($file)) {
    exit(0);
}

$real = realpath($file);
$rootReal = realpath($root);
if ($real === false || $rootReal === false || ! str_starts_with($real, $rootReal)) {
    exit(0);
}

$relative = ltrim(substr($real, strlen($rootReal)), DIRECTORY_SEPARATOR);
if (preg_match('#^(vendor|node_modules|public/build|storage)[/\\\\]#', $relative)) {
    exit(0);
}

$isWindows = PHP_OS_FAMILY === 'Windows';
$command = null;

if (str_ends_with($real, '.php')) {
    $pint = $rootReal.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pint';
    if (is_file($pint)) {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($pint).' --quiet '.escapeshellarg($real);
    }
} elseif (preg_match('/\.(tsx?|jsx?|css|json)$/', $real)) {
    $vp = $rootReal.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'.bin'.DIRECTORY_SEPARATOR.($isWindows ? 'vp.cmd' : 'vp');
    if (is_file($vp)) {
        $command = escapeshellarg($vp).' fmt --no-error-on-unmatched-pattern '.escapeshellarg($real);
    }
}

if ($command !== null) {
    $cwd = getcwd();
    chdir($rootReal);
    exec($command.($isWindows ? ' 2>NUL' : ' 2>/dev/null'), $output, $code);
    chdir($cwd ?: $rootReal);
}

exit(0);

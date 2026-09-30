<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds physical (non RTL-safe) Tailwind utilities; see tests/Unit/LogicalTailwindUtilitiesTest.php.
 */
final class PhysicalUtilities
{
    public const string PATTERN = '/(?<![\w\-\[=])!?-?(?:'
        .'(?:scroll-)?[mp][lr]-[\w\[\]\(\)\.\/%\-]+'
        .'|(?:left|right)-[\w\[\]\(\)\.\/%\-]+'
        .'|(?:text|float|clear)-(?:left|right)'
        .'|border-[lr](?:-[\w\[\]\(\)\.\/%\-]+)?'
        .'|rounded-(?:[lr]|[tb][lr])(?:-[\w\[\]\(\)\.\/%\-]+)?'
        .'|(?<!side=left\]:)(?<!side=right\]:)slide-(?:in-from|out-to)-(?:left|right)(?:-[\w\[\]\(\)\.\/%\-]+)?'
        .'|cursor-(?:w|e|nw|ne|sw|se)-resize'
        .')(?![\w\-])/';

    /**
     * Physical utilities in the given lines, minus classes an rtl-physical-ok(...) marker exempts.
     *
     * @param  list<string>  $lines
     * @return list<array{line: int, class: string}>
     */
    public static function in(array $lines): array
    {
        $found = [];

        foreach ($lines as $index => $line) {
            $allowed = [];

            foreach ([$line, $lines[$index - 1] ?? ''] as $candidate) {
                if (preg_match('/rtl-physical-ok\(([^)]*)\)/', $candidate, $marker) === 1) {
                    $allowed = [...$allowed, ...preg_split('/[\s,]+/', trim($marker[1]), flags: PREG_SPLIT_NO_EMPTY) ?: []];
                }
            }

            // The marker names classes itself; don't scan it.
            preg_match_all(self::PATTERN, (string) preg_replace('/rtl-physical-ok\([^)]*\)/', '', $line), $matches);

            foreach ($matches[0] as $class) {
                if (! in_array(ltrim($class, '!-'), $allowed, true)) {
                    $found[] = ['line' => $index + 1, 'class' => $class];
                }
            }
        }

        return $found;
    }
}

<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| RTL-safe Tailwind utilities
|--------------------------------------------------------------------------
|
| Arabic renders right-to-left, so layout classes must be logical (ms-, pe-,
| start-, text-end, border-s, rounded-e, slide-in-from-end...). A class that
| genuinely has to stay physical is named in an `rtl-physical-ok(...)` comment
| on the same line or the line above, e.g.
|
|     // rtl-physical-ok(left-[50%]): centred with translate-x, same in both directions
|
| Only the listed classes are exempt. Not checked on purpose:
| - slide-* under a Radix `data-[side=left|right]:` variant: that side is the
|   physical placement side, so a physical slide is correct;
| - translate-x-*: mostly direction-neutral centring; pair nudges with rtl:.
|
*/

const PHYSICAL_UTILITY = '/(?<![\w\-\[=])!?-?(?:'
    .'(?:scroll-)?[mp][lr]-[\w\[\]\(\)\.\/%\-]+'
    .'|(?:left|right)-[\w\[\]\(\)\.\/%\-]+'
    .'|(?:text|float|clear)-(?:left|right)'
    .'|border-[lr](?:-[\w\[\]\(\)\.\/%\-]+)?'
    .'|rounded-(?:[lr]|[tb][lr])(?:-[\w\[\]\(\)\.\/%\-]+)?'
    .'|(?<!side=left\]:)(?<!side=right\]:)slide-(?:in-from|out-to)-(?:left|right)(?:-[\w\[\]\(\)\.\/%\-]+)?'
    .'|cursor-(?:w|e|nw|ne|sw|se)-resize'
    .')(?![\w\-])/';

/**
 * @param  list<string>  $lines
 * @return list<array{line: int, class: string}>
 */
function physicalUtilitiesIn(array $lines): array
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
        preg_match_all(PHYSICAL_UTILITY, (string) preg_replace('/rtl-physical-ok\([^)]*\)/', '', $line), $matches);

        foreach ($matches[0] as $class) {
            if (! in_array(ltrim($class, '!-'), $allowed, true)) {
                $found[] = ['line' => $index + 1, 'class' => $class];
            }
        }
    }

    return $found;
}

it('uses logical Tailwind utilities in resources/js', function (): void {
    $files = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.ts', '*.tsx'])
        ->exclude(['actions', 'routes', 'wayfinder']);

    $violations = [];

    foreach ($files as $file) {
        foreach (physicalUtilitiesIn(explode("\n", $file->getContents())) as $hit) {
            $violations[] = $file->getRelativePathname().':'.$hit['line'].'  '.$hit['class'];
        }
    }

    expect($violations)->toBe([]);
});

it('detects physical utilities and ignores logical ones', function (string $line, bool $flagged): void {
    expect(physicalUtilitiesIn([$line]) !== [])->toBe($flagged);
})->with([
    ['className="ml-2 flex"', true],
    ['className="-mr-1"', true],
    ['className="sm:pl-4"', true],
    ['className="absolute right-0"', true],
    ['className="left-[50%]"', true],
    ['className="text-left"', true],
    ['className="border-r"', true],
    ['className="rounded-tl-lg"', true],
    ['data-[motion=from-end]:slide-in-from-right-52', true],
    ['in-data-[side=left]:cursor-w-resize', true],
    ['className="ms-2 pe-4 start-0 end-1 text-start border-s rounded-e-md"', false],
    ['data-[motion=from-end]:slide-in-from-end-52 slide-out-to-start', false],
    ['side="left"', false],
    ['data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2', false],
    ['className="mx-auto px-4 py-2 cursor-ew-resize"', false],
    ['className="blur-lg"', false],
]);

it('exempts only the classes named in an rtl-physical-ok marker', function (): void {
    $hits = physicalUtilitiesIn([
        '// rtl-physical-ok(left-[50%]): centred',
        '"fixed left-[50%] translate-x-[-50%] pl-4"',
        '"right-2 cursor-w-resize" // rtl-physical-ok(right-2, cursor-w-resize)',
        '"left-[50%]"',
    ]);

    expect($hits)->toBe([
        ['line' => 2, 'class' => 'pl-4'],
        ['line' => 4, 'class' => 'left-[50%]'],
    ]);
});

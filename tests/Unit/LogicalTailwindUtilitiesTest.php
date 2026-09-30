<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| RTL-safe Tailwind utilities
|--------------------------------------------------------------------------
|
| Arabic renders right-to-left, so layout classes must be logical (ms-, pe-,
| start-, text-end, border-s, rounded-e...). A line that genuinely needs a
| physical class (e.g. a centred dialog: left-[50%] + translate) carries a
| `rtl-physical-ok` comment on the same line or the line above.
|
*/

const PHYSICAL_UTILITY = '/(?<![\w\-\[=])!?-?(?:'
    .'(?:scroll-)?[mp][lr]-[\w\[\]\(\)\.\/%\-]+'
    .'|(?:left|right)-[\w\[\]\(\)\.\/%\-]+'
    .'|(?:text|float|clear)-(?:left|right)'
    .'|border-[lr](?:-[\w\[\]\(\)\.\/%\-]+)?'
    .'|rounded-(?:[lr]|[tb][lr])(?:-[\w\[\]\(\)\.\/%\-]+)?'
    .')(?![\w\-])/';

/**
 * @return list<string> "path:line  class" for every physical utility found
 */
function physicalUtilityViolations(): array
{
    $files = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.ts', '*.tsx'])
        ->exclude(['actions', 'routes', 'wayfinder']);

    $violations = [];

    foreach ($files as $file) {
        $lines = explode("\n", $file->getContents());

        foreach ($lines as $index => $line) {
            $previous = $lines[$index - 1] ?? '';

            if (str_contains($line, 'rtl-physical-ok') || str_contains($previous, 'rtl-physical-ok')) {
                continue;
            }

            if (preg_match_all(PHYSICAL_UTILITY, $line, $matches) > 0) {
                foreach ($matches[0] as $class) {
                    $violations[] = $file->getRelativePathname().':'.($index + 1).'  '.$class;
                }
            }
        }
    }

    return $violations;
}

it('uses logical Tailwind utilities in resources/js', function (): void {
    expect(physicalUtilityViolations())->toBe([]);
});

it('detects physical utilities and ignores logical ones', function (string $line, bool $flagged): void {
    expect(preg_match(PHYSICAL_UTILITY, $line) === 1)->toBe($flagged);
})->with([
    ['className="ml-2 flex"', true],
    ['className="-mr-1"', true],
    ['className="sm:pl-4"', true],
    ['className="absolute right-0"', true],
    ['className="left-[50%]"', true],
    ['className="text-left"', true],
    ['className="border-r"', true],
    ['className="rounded-tl-lg"', true],
    ['className="ms-2 pe-4 start-0 end-1 text-start border-s rounded-e-md"', false],
    ['side="left"', false],
    ['data-[side=left]:slide-in-from-right-2', false],
    ['className="mx-auto px-4 py-2"', false],
    ['className="blur-lg"', false],
]);

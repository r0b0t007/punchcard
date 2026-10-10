<?php

declare(strict_types=1);

use App\Support\Branding\Contrast;

/*
|--------------------------------------------------------------------------
| Brand colour contrast on the server (ADR 0007, CHW-32)
|--------------------------------------------------------------------------
|
| The PHP twin of resources/js/lib/color.ts: the same fixtures as
| color.test.ts, so the foreground a card stores is the one its preview
| showed.
|
*/

it('computes the WCAG contrast ratio', function (): void {
    expect(Contrast::ratio('#000000', '#ffffff'))->toEqualWithDelta(21, 0.00001)
        ->and(Contrast::ratio('#ffffff', '#ffffff'))->toEqualWithDelta(1, 0.00001)
        ->and(Contrast::ratio('#241A13', '#FBF6EE'))->toEqualWithDelta(15.84, 0.05)
        ->and(Contrast::ratio('#fff', '#000'))->toEqualWithDelta(21, 0.00001);
});

it('picks cream on dark brand colours and ink on light ones', function (string $brand, string $foreground): void {
    expect(Contrast::readableForeground($brand))->toBe($foreground);
})->with([
    ['#3B2A20', '#FBF6EE'],
    ['#0F4C81', '#FBF6EE'],
    ['#F2A541', '#241A13'],
    ['#FFE8D6', '#241A13'],
    ['not-a-colour', '#FBF6EE'],
]);

it('always reaches at least 4.5:1', function (string $brand): void {
    expect(Contrast::ratio(Contrast::readableForeground($brand), $brand))->toBeGreaterThanOrEqual(4.5);
})->with(['#E11D48', '#16A34A', '#7C3AED', '#0EA5E9', '#808080', '#B45309']);

it('uses saffron stamps when they stand out, the card\'s text colour otherwise', function (string $brand, string $stamp): void {
    expect(Contrast::stampColor($brand))->toBe($stamp);
})->with([
    ['#3B2A20', '#F2A541'],
    ['#0F4C81', '#F2A541'],
    ['#F2A541', '#241A13'],
    ['#FFFFFF', '#241A13'],
]);

it('warns when neither of punchcard\'s inks reads on the colour, so its text only just passes', function (): void {
    expect(Contrast::isWeak('#808080'))->toBeTrue()
        ->and(Contrast::isWeak('#3B2A20'))->toBeFalse()
        ->and(Contrast::isWeak('#FFE8D6'))->toBeFalse();
});

it('reads hex colours as the browser does: no trailing newline, the pure fallback on mid-tones', function (): void {
    expect(Contrast::isHex("#AABBCC\n"))->toBeFalse()
        ->and(Contrast::isHex('#aabbcc'))->toBeTrue()
        ->and(Contrast::readableForeground('#808080'))->toBe('#000000');
});

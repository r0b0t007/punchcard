<?php

declare(strict_types=1);

namespace App\Support\Branding;

/**
 * WCAG contrast for brand colours (ADR 0007, CHW-32), the server's twin of
 * resources/js/lib/color.ts: a brand colour only paints the loyalty card, so
 * the card stores the text colour it reads with, computed here when the
 * colour is saved (the preview computes the same in the browser).
 */
final class Contrast
{
    /** punchcard's own inks, also the default espresso card (design-tokens skill). */
    public const string CREAM = '#FBF6EE';

    public const string INK = '#241A13';

    public const string ESPRESSO = '#3B2A20';

    public const string SAFFRON = '#F2A541';

    /** WCAG 2.x contrast ratio between two hex colours (#rgb or #rrggbb), 1 to 21. */
    public static function ratio(string $a, string $b): float
    {
        $luminances = [self::luminance($a), self::luminance($b)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * The text colour for a brand-coloured card, always 4.5:1 or more:
     * punchcard's cream or ink when one passes, else pure white or black.
     */
    public static function readableForeground(string $brand): string
    {
        $background = self::isHex($brand) ? $brand : self::ESPRESSO;
        $preferred = self::best([self::CREAM, self::INK], $background);

        return self::ratio($preferred, $background) >= 4.5 ? $preferred : self::best(['#FFFFFF', '#000000'], $background);
    }

    /** Filled stamps: saffron when it stands out (3:1, the non-text rule), else the card's text colour. */
    public static function stampColor(string $brand): string
    {
        $background = self::isHex($brand) ? $brand : self::ESPRESSO;

        return self::ratio(self::SAFFRON, $background) >= 3 ? self::SAFFRON : self::readableForeground($background);
    }

    /**
     * A colour only pure white or black reads on: its text just passes AA
     * (about 4.6:1), so the builder suggests a darker or lighter shade.
     */
    public static function isWeak(string $brand): bool
    {
        $background = self::isHex($brand) ? $brand : self::ESPRESSO;

        return self::ratio(self::best([self::CREAM, self::INK], $background), $background) < 4.5;
    }

    public static function isHex(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/iD', $value) === 1;
    }

    /** @param  list<string>  $candidates */
    private static function best(array $candidates, string $background): string
    {
        return array_reduce($candidates, fn (?string $best, string $candidate): string => $best === null || self::ratio($candidate, $background) > self::ratio($best, $background) ? $candidate : $best)
            ?? $candidates[0];
    }

    private static function luminance(string $hex): float
    {
        $value = ltrim($hex, '#');
        $full = strlen($value) === 3 ? $value[0].$value[0].$value[1].$value[1].$value[2].$value[2] : $value;
        [$r, $g, $b] = array_map(function (int $offset) use ($full): float {
            $channel = hexdec(substr($full, $offset, 2)) / 255;

            return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, [0, 2, 4]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}

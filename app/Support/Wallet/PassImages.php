<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A pass's images (CHW-38, design 25), at 1x, 2x and 3x as Wallet asks:
 * icon.png (punchcard's app icon, 29 pt), logo.png (the organization's logo,
 * fitted in 160 × 50 pt) and strip.png (375 × 144 pt, a store card's strip):
 * the stamp grid drawn as the card draws it (Stamp in loyalty-card/stamp.tsx),
 * a disc in the stamp colour per stamp, check, heart and star in the brand
 * colour on it, a ring for "ring", dashed outlines for the stamps to come,
 * filled from where the customer's language starts to read. Drawn twice as
 * large and scaled down, for smooth edges. Strips are kept on the local disk by
 * what they show, so a pass fetched again isn't drawn again.
 */
final class PassImages
{
    private const array SCALES = [1, 2, 3];

    private const int STRIP_WIDTH = 375;

    private const int STRIP_HEIGHT = 144;

    /** How much larger shapes are drawn before scaling down. */
    private const int SUPERSAMPLE = 2;

    /**
     * @param  array{stampsCollected: int, stampsRequired: int, stampStyle: ?string, background: string, foreground: string, stamp: string, rtl: bool, logoPath: ?string}  $look
     * @return array<string, string> file name => PNG bytes
     */
    public static function for(array $look): array
    {
        $files = [];

        foreach (self::SCALES as $scale) {
            $suffix = $scale === 1 ? '' : "@{$scale}x";
            $files["icon{$suffix}.png"] = self::icon($scale);
            $files["strip{$suffix}.png"] = self::cachedStrip($look, $scale);

            if ($look['logoPath'] !== null) {
                $logo = self::logo($look['logoPath'], $scale);

                if ($logo !== null) {
                    $files["logo{$suffix}.png"] = $logo;
                }
            }
        }

        return $files;
    }

    /**
     * The strip on its own, for tests and the cache.
     *
     * @param  array{stampsCollected: int, stampsRequired: int, stampStyle: ?string, background: string, foreground: string, stamp: string, rtl: bool, logoPath: ?string}  $look
     */
    public static function strip(array $look, int $scale): string
    {
        $k = $scale * self::SUPERSAMPLE;
        $width = self::STRIP_WIDTH * $k;
        $height = self::STRIP_HEIGHT * $k;
        $image = self::canvas($width, $height);
        $background = self::mix($look['foreground'], $look['background'], 0.10);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, self::colour($image, $background));

        $count = max(1, $look['stampsRequired']);
        [$columns, $rows, $cell] = self::grid($count, ($width - 32 * $k), ($height - 24 * $k));
        $gap = $cell * 0.25;
        $gridWidth = $columns * $cell + ($columns - 1) * $gap;
        $gridHeight = $rows * $cell + ($rows - 1) * $gap;
        $left = ($width - $gridWidth) / 2;
        $top = ($height - $gridHeight) / 2;

        for ($index = 0; $index < $count; $index++) {
            $column = $index % $columns;
            $row = intdiv($index, $columns);
            $x = $left + ($look['rtl'] ? $columns - 1 - $column : $column) * ($cell + $gap) + $cell / 2;
            $y = $top + $row * ($cell + $gap) + $cell / 2;

            if ($index < $look['stampsCollected']) {
                self::stamp($image, $look, $x, $y, $cell / 2, $background);
            } else {
                self::emptySlot($image, $look['foreground'], $x, $y, $cell / 2, $k);
            }
        }

        return self::png(self::scaled($image, self::STRIP_WIDTH * $scale, self::STRIP_HEIGHT * $scale));
    }

    /**
     * The columns, rows and cell size that fit the most stamps' size in the strip: 5 to 10 a row.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    public static function grid(int $count, float $width, float $height): array
    {
        $best = [5, (int) ceil($count / 5), 0.0];

        foreach (range(5, 10) as $columns) {
            $columns = min($columns, $count);
            $rows = (int) ceil($count / $columns);
            $cell = min($width / ($columns + ($columns - 1) * 0.25), $height / ($rows + ($rows - 1) * 0.25));

            if ($cell > $best[2] + 0.01) {
                $best = [$columns, $rows, $cell];
            }
        }

        return $best;
    }

    /**
     * @param  array{stampsCollected: int, stampsRequired: int, stampStyle: ?string, background: string, foreground: string, stamp: string, rtl: bool, logoPath: ?string}  $look
     */
    private static function cachedStrip(array $look, int $scale): string
    {
        $path = 'wallet/strips/'.sha1(json_encode([$look['stampsCollected'], $look['stampsRequired'], $look['stampStyle'], $look['background'], $look['foreground'], $look['stamp'], $look['rtl']], JSON_THROW_ON_ERROR)).'@'.$scale.'x.png';
        $disk = Storage::disk('local');
        $cached = $disk->exists($path) ? $disk->get($path) : null;

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $strip = self::strip($look, $scale);
        $disk->put($path, $strip);

        return $strip;
    }

    /**
     * @param  array{stampsCollected: int, stampsRequired: int, stampStyle: ?string, background: string, foreground: string, stamp: string, rtl: bool, logoPath: ?string}  $look
     */
    private static function stamp(GdImage $image, array $look, float $x, float $y, float $radius, string $background): void
    {
        $stamp = self::colour($image, $look['stamp']);

        if ($look['stampStyle'] === 'ring') {
            imagefilledellipse($image, (int) $x, (int) $y, (int) (2 * $radius), (int) (2 * $radius), $stamp);
            imagefilledellipse($image, (int) $x, (int) $y, (int) (1.6 * $radius), (int) (1.6 * $radius), self::colour($image, $background));

            return;
        }

        imagefilledellipse($image, (int) $x, (int) $y, (int) (2 * $radius), (int) (2 * $radius), $stamp);
        $ink = self::colour($image, $look['background']);
        $size = $radius * 0.6;

        match ($look['stampStyle']) {
            'check' => self::check($image, $x, $y, $size, $ink),
            'heart' => imagefilledpolygon($image, self::heart($x, $y, $size), $ink),
            'star' => imagefilledpolygon($image, self::star($x, $y, $size), $ink),
            default => null,
        };
    }

    private static function check(GdImage $image, float $x, float $y, float $size, int $colour): void
    {
        imagesetthickness($image, max(2, (int) round($size * 0.32)));
        imageline($image, (int) ($x - $size * 0.75), (int) ($y + $size * 0.05), (int) ($x - $size * 0.2), (int) ($y + $size * 0.6), $colour);
        imageline($image, (int) ($x - $size * 0.2), (int) ($y + $size * 0.6), (int) ($x + $size * 0.8), (int) ($y - $size * 0.55), $colour);
        imagesetthickness($image, 1);
    }

    /**
     * @return list<int>
     */
    private static function star(float $x, float $y, float $size): array
    {
        $points = [];

        for ($i = 0; $i < 10; $i++) {
            $angle = -M_PI / 2 + $i * M_PI / 5;
            $length = $i % 2 === 0 ? $size : $size * 0.45;
            $points[] = (int) round($x + cos($angle) * $length);
            $points[] = (int) round($y + sin($angle) * $length);
        }

        return $points;
    }

    /**
     * @return list<int>
     */
    private static function heart(float $x, float $y, float $size): array
    {
        $points = [];

        for ($i = 0; $i < 48; $i++) {
            $t = $i / 48 * 2 * M_PI;
            $hx = 16 * sin($t) ** 3;
            $hy = 13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t);
            $points[] = (int) round($x + $hx / 17 * $size);
            $points[] = (int) round($y - $hy / 17 * $size + $size * 0.1);
        }

        return $points;
    }

    /** A stamp to come: a dashed outline in the text colour, as on the card. */
    private static function emptySlot(GdImage $image, string $foreground, float $x, float $y, float $radius, int $k): void
    {
        $colour = self::colour($image, $foreground, 0.8);
        $thickness = max(2, $k * 2);
        $dashes = 16;
        imagesetthickness($image, $thickness);

        for ($i = 0; $i < $dashes; $i++) {
            $from = $i * 360 / $dashes;
            imagearc($image, (int) $x, (int) $y, (int) (2 * $radius - $thickness), (int) (2 * $radius - $thickness), (int) $from, (int) ($from + 360 / $dashes / 2), $colour);
        }

        imagesetthickness($image, 1);
    }

    private static function icon(int $scale): string
    {
        $source = @imagecreatefrompng(public_path('icons/icon-512.png'));

        if (! $source instanceof GdImage) {
            throw new RuntimeException('The app icon public/icons/icon-512.png can\'t be read.');
        }

        return self::png(self::scaled($source, 29 * $scale, 29 * $scale));
    }

    /** The organization's logo fitted in 160 × 50 pt, or null when it can't be read. */
    private static function logo(string $path, int $scale): ?string
    {
        $bytes = Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : null;
        $source = is_string($bytes) && $bytes !== '' ? @imagecreatefromstring($bytes) : false;

        if (! $source instanceof GdImage) {
            return null;
        }

        $ratio = min(160 * $scale / imagesx($source), 50 * $scale / imagesy($source));

        return self::png(self::scaled($source, max(1, (int) round(imagesx($source) * $ratio)), max(1, (int) round(imagesy($source) * $ratio))));
    }

    private static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD could not make an image.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        return $image;
    }

    private static function scaled(GdImage $source, int $width, int $height): GdImage
    {
        $target = self::canvas($width, $height);
        imagealphablending($target, false);
        imagecopyresampled($target, $source, 0, 0, 0, 0, max(1, $width), max(1, $height), imagesx($source), imagesy($source));

        return $target;
    }

    private static function png(GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /** A colour, with how opaque it is (1 = fully). */
    private static function colour(GdImage $image, string $hex, float $opacity = 1.0): int
    {
        [$red, $green, $blue] = self::channels($hex);

        $alpha = max(0, min(127, (int) round(127 * (1 - $opacity))));

        return (int) imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
    }

    /** $over mixed into $base at $amount (0 to 1), as CSS color-mix in the design. */
    private static function mix(string $over, string $base, float $amount): string
    {
        $a = self::channels($over);
        $b = self::channels($base);

        return sprintf('#%02X%02X%02X', ...array_map(fn (int $i): int => (int) round($a[$i] * $amount + $b[$i] * (1 - $amount)), [0, 1, 2]));
    }

    /**
     * A "#RRGGBB" colour's red, green and blue, each 0 to 255.
     *
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private static function channels(string $hex): array
    {
        $parts = array_map(hexdec(...), str_split(ltrim($hex, '#'), 2));
        $channel = fn (int $i): int => max(0, min(255, (int) ($parts[$i] ?? 0)));

        return [$channel(0), $channel(1), $channel(2)];
    }
}

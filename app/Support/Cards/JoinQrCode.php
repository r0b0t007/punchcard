<?php

declare(strict_types=1);

namespace App\Support\Cards;

use App\Support\QrCode;

/**
 * The QR code of a join page link (CHW-31), crisp when printed on the QR
 * stand (QrCode). The link is the app's own, so the SVG carries no user
 * input.
 */
final class JoinQrCode
{
    public static function svg(string $url): string
    {
        return QrCode::svg($url);
    }

    /** The SVG as a data URL, for an <img>: no markup is injected into the page. */
    public static function dataUrl(string $url): string
    {
        return QrCode::dataUrl($url);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Cards;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code of a join page link (CHW-31), as an SVG made on the server
 * (bacon/bacon-qr-code, also Fortify's for 2FA): 320 px with a one-module
 * margin, crisp when printed on the QR stand. The link is the app's own,
 * so the SVG carries no user input.
 */
final class JoinQrCode
{
    public static function svg(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /** The SVG as a data URL, for an <img>: no markup is injected into the page. */
    public static function dataUrl(string $url): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($url));
    }
}

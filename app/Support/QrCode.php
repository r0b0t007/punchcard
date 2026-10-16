<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code made on the server (bacon/bacon-qr-code, also Fortify's for
 * 2FA), as an SVG: 320 px with the 4-module quiet zone the QR standard asks
 * for. The join page's link (JoinQrCode), the member QR (C10, CHW-28).
 */
final class QrCode
{
    public static function svg(string $content): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd)))->writeString($content);
    }

    /** The SVG as a data URL, for an <img>: no markup is injected into the page. */
    public static function dataUrl(string $content): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($content));
    }
}

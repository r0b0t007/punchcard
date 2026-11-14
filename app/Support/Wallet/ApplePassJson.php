<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use App\Support\Branding\Contrast;

/**
 * An enrollment's pass.json (CHW-38, design 25): an Apple Wallet store card
 * with the organization's name beside its logo, the stamps in the header
 * (the lock screen says so when they change), the stamps drawn on the strip
 * (PassImages), the reward and the member's short name, and the pass code as
 * a QR with the member number under it. The colours are the card's
 * (CardBrand, as every customer screen): the text colour the server worked
 * out, else the readable one, and the labels in the stamp colour. Written in
 * the customer's language. The web service and its token only on HTTPS, which
 * Wallet requires. Pure: what the pass says comes in, its JSON goes out.
 */
final class ApplePassJson
{
    /**
     * @param  array{
     *     passTypeId: string,
     *     teamId: string,
     *     serial: string,
     *     authToken: string,
     *     webServiceUrl: ?string,
     *     organizationName: string,
     *     cardName: string,
     *     rewardText: string,
     *     terms: ?string,
     *     stampsCollected: int,
     *     stampsRequired: int,
     *     brandColor: ?string,
     *     brandForeground: ?string,
     *     memberName: ?string,
     *     memberNumber: int,
     *     cardUrl: string,
     *     locale: string,
     * }  $pass
     * @return array<string, mixed>
     */
    public static function build(array $pass): array
    {
        $locale = $pass['locale'];
        [$background, $foreground, $label] = self::colours($pass['brandColor'], $pass['brandForeground']);

        $secondary = [['key' => 'reward', 'label' => __('Reward', [], $locale), 'value' => $pass['rewardText']]];

        if ($pass['memberName'] !== null) {
            $secondary[] = ['key' => 'member', 'label' => __('Member', [], $locale), 'value' => $pass['memberName'], 'textAlignment' => 'PKTextAlignmentRight'];
        }

        $back = [['key' => 'card', 'label' => __('Card', [], $locale), 'value' => $pass['cardName']]];

        if ($pass['terms'] !== null && trim($pass['terms']) !== '') {
            $back[] = ['key' => 'terms', 'label' => __('Terms', [], $locale), 'value' => $pass['terms']];
        }

        $back[] = ['key' => 'open', 'label' => __('Your card', [], $locale), 'value' => $pass['cardUrl']];

        $json = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $pass['passTypeId'],
            'teamIdentifier' => $pass['teamId'],
            'serialNumber' => $pass['serial'],
            'organizationName' => $pass['organizationName'],
            'description' => __(':card at :organization', ['card' => $pass['cardName'], 'organization' => $pass['organizationName']], $locale),
            'logoText' => $pass['organizationName'],
            'backgroundColor' => self::rgb($background),
            'foregroundColor' => self::rgb($foreground),
            'labelColor' => self::rgb($label),
            // iOS 16+: Wallet offers no Share for it, as a member card is the customer's own.
            'sharingProhibited' => true,
            'storeCard' => [
                'headerFields' => [[
                    'key' => 'stamps',
                    'label' => __('Stamps', [], $locale),
                    'value' => $pass['stampsCollected'].'/'.$pass['stampsRequired'],
                    'changeMessage' => __('Stamps: %@', [], $locale),
                ]],
                'secondaryFields' => $secondary,
                'backFields' => $back,
            ],
            'barcodes' => [[
                'format' => 'PKBarcodeFormatQR',
                'message' => PassCode::of($pass['serial']),
                'messageEncoding' => 'iso-8859-1',
                'altText' => __('Member :number', ['number' => $pass['memberNumber']], $locale),
            ]],
        ];

        if ($pass['webServiceUrl'] !== null && str_starts_with($pass['webServiceUrl'], 'https://')) {
            $json['webServiceURL'] = $pass['webServiceUrl'];
            $json['authenticationToken'] = $pass['authToken'];
        }

        return $json;
    }

    /**
     * The pass's background, text and label colours, as the card shows them (cardInks in lib/color.ts).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function colours(?string $brandColor, ?string $brandForeground): array
    {
        $background = $brandColor !== null && Contrast::isHex($brandColor) ? strtoupper($brandColor) : Contrast::ESPRESSO;
        $foreground = $background === strtoupper((string) $brandColor) && $brandForeground !== null && Contrast::isHex($brandForeground)
            && Contrast::ratio($brandForeground, $background) >= 4.5
            ? strtoupper($brandForeground)
            : Contrast::readableForeground($background);

        return [$background, $foreground, Contrast::stampColor($background, $foreground)];
    }

    /** "#0F5E63" as Wallet writes colours: "rgb(15, 94, 99)". */
    public static function rgb(string $hex): string
    {
        [$red, $green, $blue] = array_map(hexdec(...), str_split(ltrim($hex, '#'), 2));

        return "rgb({$red}, {$green}, {$blue})";
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How a stamp event came about (stamp-flow skill). */
enum StampSource: string implements HasLabel
{
    /** A verified SUN tap on a stamper: the tag and counter are recorded. */
    case Nfc = 'nfc';

    /** Staff scanned the customer's member QR. */
    case Qr = 'qr';

    /** Staff or owner added stamps by hand, with a reason. */
    case Manual = 'manual';

    case Bonus = 'bonus';

    case Birthday = 'birthday';

    case Referral = 'referral';

    /** A correction taking earlier stamps back (qty always negative), with a reason. */
    case Correction = 'correction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nfc => __('Tap'),
            self::Qr => __('QR scan'),
            self::Manual => __('By hand'),
            self::Bonus => __('Bonus'),
            self::Birthday => __('Birthday'),
            self::Referral => __('Referral'),
            self::Correction => __('Correction'),
        };
    }

    /**
     * Sources that prove the customer was at the business (a tap, a staff scan,
     * staff by hand): only these make a customer "stamped here" (ADR 0006).
     * System events (bonus, birthday, referral) and corrections, which are
     * administrative fixes, do not.
     *
     * @return list<string>
     */
    public static function presenceValues(): array
    {
        return array_map(fn (self $source): string => $source->value, [self::Nfc, self::Qr, self::Manual]);
    }
}

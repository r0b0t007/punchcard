<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a stamper tap was refused. Each rejected tap is recorded with one of
 * these for the owner's fraud view, so the values are stable identifiers.
 */
enum TapRejection: string
{
    /** The tap URL is not a well-formed SUN message. */
    case Malformed = 'malformed';

    /** No NFC tag is registered for the UID. */
    case UnknownTag = 'unknown_tag';

    /** The CMAC does not match: the URL was forged or altered. */
    case BadMac = 'bad_mac';

    /** The read counter is not above the tag's last one: a copied or reused URL. */
    case Replay = 'replay';

    /** The tag was retired (lost, broken or replaced). */
    case RetiredTag = 'retired_tag';

    /** No stamper holds the tag now (unassigned, or its site closed), or it moved since the tap. */
    case UnassignedTag = 'unassigned_tag';

    /** The stamper was disabled (lost or stolen). */
    case StamperDisabled = 'stamper_disabled';

    /** The customer was stamped on this card too recently. */
    case Cooldown = 'cooldown';

    /** The customer reached today's stamp limit on this card at this business. */
    case DailyCap = 'daily_cap';

    /** The card is switched off. */
    case CardInactive = 'card_inactive';

    /** The business honours no active card (or no longer this one). */
    case NotHonoured = 'not_honoured';

    /** The business or location closed (archived) after the tap. */
    case SiteClosed = 'site_closed';

    /** The card's progressive tiers are malformed. */
    case CardMisconfigured = 'card_misconfigured';

    /** Nobody signed in to claim the tap before it expired. */
    case Expired = 'expired';

    /** A redeem window was open, but RedeemReward refused (an unverified email, a closed site). */
    case RedeemRefused = 'redeem_refused';

    /** The tap equivalent of an AddStamps refusal. */
    public static function fromStamp(StampRejection $rejection): self
    {
        return match ($rejection) {
            StampRejection::Cooldown => self::Cooldown,
            StampRejection::DailyCap => self::DailyCap,
            StampRejection::CardInactive => self::CardInactive,
            StampRejection::NotHonoured => self::NotHonoured,
            StampRejection::SiteClosed => self::SiteClosed,
            StampRejection::StamperUnavailable => self::StamperDisabled,
            StampRejection::CardMisconfigured => self::CardMisconfigured,
            // A tap carries no idempotency key and never corrects: these cannot happen on /t.
            StampRejection::IdempotencyConflict, StampRejection::CorrectionBelowZero, StampRejection::CorrectionExceedsGiven => throw new \LogicException("A tap cannot be refused as {$rejection->value}."),
        };
    }
}

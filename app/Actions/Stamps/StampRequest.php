<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Enums\StampSource;
use App\Models\Location;
use App\Models\Stamper;
use App\Models\User;
use InvalidArgumentException;

/**
 * One stamp to give, by source. The named constructors check its shape the
 * way the Postgres CHECKs on stamp_events do, so SQLite and Postgres refuse
 * the same requests: a bad shape is a programming error
 * (InvalidArgumentException), not a StampRejected.
 */
final readonly class StampRequest
{
    private const int MAX_TAP = 10;

    private const int MAX_STAMPS = 50;

    private function __construct(
        public StampSource $source,
        public int $qty,
        public int $businessId,
        public int $locationId,
        public ?int $stamperId = null,
        public ?int $nfcTagId = null,
        public ?int $counter = null,
        public ?int $staffId = null,
        public ?string $idempotencyKey = null,
        public ?string $reason = null,
    ) {}

    /** A verified tap on the stamper (1 stamp, or what staff armed it with), at its counter. */
    public static function nfc(Stamper $stamper, int $counter, int $qty = 1): self
    {
        self::assertBetween($qty, 1, self::MAX_TAP);

        if ($counter < 0) {
            throw new InvalidArgumentException('A tap counter is never negative.');
        }

        return new self(StampSource::Nfc, $qty, (int) $stamper->business_id, (int) $stamper->location_id, $stamper->id, (int) $stamper->nfc_tag_id, $counter);
    }

    /** Staff scanned the customer's member QR. */
    public static function qr(Location $location, User $staff, string $idempotencyKey, int $qty = 1): self
    {
        self::assertBetween($qty, 1, self::MAX_STAMPS);

        return new self(StampSource::Qr, $qty, (int) $location->business_id, $location->id, staffId: $staff->id, idempotencyKey: self::key($idempotencyKey));
    }

    /** Staff added stamps by hand, with a reason; held to the cooldown and the daily cap like a scan. */
    public static function manual(Location $location, User $staff, string $idempotencyKey, string $reason, int $qty): self
    {
        self::assertBetween($qty, 1, self::MAX_STAMPS);

        return new self(StampSource::Manual, $qty, (int) $location->business_id, $location->id, staffId: $staff->id, idempotencyKey: self::key($idempotencyKey), reason: self::reason($reason));
    }

    /** A correction of earlier stamps (negative takes them back), with a reason. */
    public static function correction(Location $location, User $staff, string $idempotencyKey, string $reason, int $qty): self
    {
        self::assertBetween($qty, -self::MAX_STAMPS, self::MAX_STAMPS);

        if ($qty === 0) {
            throw new InvalidArgumentException('A correction changes at least one stamp.');
        }

        return new self(StampSource::Correction, $qty, (int) $location->business_id, $location->id, staffId: $staff->id, idempotencyKey: self::key($idempotencyKey), reason: self::reason($reason));
    }

    /** A stamp given by a rule (bonus, birthday, referral), credited to a location. */
    public static function system(StampSource $source, Location $location, int $qty = 1, ?string $idempotencyKey = null): self
    {
        if (! in_array($source, [StampSource::Bonus, StampSource::Birthday, StampSource::Referral], true)) {
            throw new InvalidArgumentException("{$source->value} is not a system stamp.");
        }

        self::assertBetween($qty, 1, self::MAX_STAMPS);

        return new self($source, $qty, (int) $location->business_id, $location->id, idempotencyKey: $idempotencyKey === null ? null : self::key($idempotencyKey));
    }

    /** The customer was there (a tap, a scan, staff by hand): held to the cooldown and the daily cap, it starts the cooldown and counts as a visit. */
    public function provesPresence(): bool
    {
        return in_array($this->source->value, StampSource::presenceValues(), true);
    }

    /** A correction that takes stamps back: the only stamp still written at an archived site, so the ledger stays fixable. */
    public function takesStampsBack(): bool
    {
        return $this->source === StampSource::Correction && $this->qty < 0;
    }

    private static function assertBetween(int $qty, int $min, int $max): void
    {
        if ($qty < $min || $qty > $max) {
            throw new InvalidArgumentException("A stamp quantity of {$qty} is outside {$min}..{$max}.");
        }
    }

    private static function key(string $key): string
    {
        if (trim($key) === '' || strlen($key) > 64) {
            throw new InvalidArgumentException('An idempotency key is 1 to 64 characters.');
        }

        return $key;
    }

    private static function reason(string $reason): string
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Manual stamps and corrections need a reason.');
        }

        return trim($reason);
    }
}

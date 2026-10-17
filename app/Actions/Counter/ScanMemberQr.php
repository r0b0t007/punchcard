<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\MemberQr\MemberQrRefusal;
use App\Actions\MemberQr\MemberQrRefused;
use App\Actions\MemberQr\MemberQrScan;
use App\Actions\MemberQr\VerifyMemberQr;
use App\Actions\MemberQr\WrongCodes;
use App\Actions\Stamps\WorksAt;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\User;
use App\Support\MemberQr\MemberQrToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/**
 * A staff member scans a customer's member QR, or types its short code
 * (CHW-30, S2): checked by VerifyMemberQr, used once per customer at this
 * business while a code of theirs counts (scope "scan:business:{id}"), then
 * kept as a CounterScan, the staff member's for CounterScan::SECONDS at the
 * location they work at. Its unique key holds one scan per code at a
 * business without the cache. Never their own code (OwnCode): a colleague
 * stamps them, as an arming never pays whoever made it (CHW-29).
 */
final readonly class ScanMemberQr
{
    /** Codes one staff member may get wrong (Forged) before their scans are refused for a while. */
    public const int STAFF_WRONG_CODES = 20;

    /** How long a staff member's wrong codes count against them. */
    public const int STAFF_WRONG_CODES_SECONDS = 3600;

    public function __construct(private VerifyMemberQr $verifyMemberQr, private WorksAt $worksAt) {}

    /**
     * @throws MemberQrRefused
     * @throws ScanRefused
     */
    public function handle(User $staff, Location $location, #[SensitiveParameter] string $scanned): CounterScan
    {
        if (! $this->worksAt->handle($staff->id, $location->business_id, $location->id)) {
            throw new LogicException('Only the business\'s staff, owners or org admins scan member QRs there.');
        }

        // Before the code is checked, so their own scan spends nothing a colleague then needs to scan them.
        if (MemberQrToken::memberOf($scanned) === $staff->id) {
            throw new ScanRefused(ScanRefusal::OwnCode);
        }

        $scan = $this->verified($staff, $scanned, 'scan:business:'.$location->business_id);

        // In a savepoint: on Postgres a unique violation aborts the transaction it happens in, so a caller's own
        // transaction must survive the refusal.
        try {
            return DB::transaction(fn (): CounterScan => CounterScan::query()->forceCreate([
                'business_id' => $location->business_id,
                'location_id' => $location->id,
                'user_id' => $scan->userId,
                'staff_id' => $staff->id,
                'member_qr_window' => $scan->window,
                'scanned_at' => $scan->scannedAt,
                'expires_at' => $scan->scannedAt->addSeconds(CounterScan::SECONDS),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new MemberQrRefused(MemberQrRefusal::Replayed);
        }
    }

    /**
     * The code checked. A typed short code counts against the staff member (WrongCodes): past STAFF_WRONG_CODES
     * wrong ones in STAFF_WRONG_CODES_SECONDS, TooManyWrongCodes for any typed code; the per-member count
     * (MemberQrScan::fromCode) alone would let one account try a few codes for each of many members. A QR's token
     * never counts: its signature can't be found by trying, and a bad QR held to the camera, read again and again,
     * must not lock the staff member out.
     *
     * @throws MemberQrRefused
     */
    private function verified(User $staff, #[SensitiveParameter] string $scanned, string $scope): MemberQrScan
    {
        if (str_starts_with(trim($scanned), MemberQrToken::VERSION.'.')) {
            return $this->verifyMemberQr->handle($scanned, $scope);
        }

        return WrongCodes::counted(
            'counter-scan-wrong:'.$staff->id,
            self::STAFF_WRONG_CODES,
            self::STAFF_WRONG_CODES_SECONDS,
            fn (): MemberQrScan => $this->verifyMemberQr->handle($scanned, $scope),
            fn (): ScanRefused => new ScanRefused(ScanRefusal::TooManyWrongCodes),
        );
    }
}

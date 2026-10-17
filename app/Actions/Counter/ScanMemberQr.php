<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\MemberQr\MemberQrRefusal;
use App\Actions\MemberQr\MemberQrRefused;
use App\Actions\MemberQr\VerifyMemberQr;
use App\Actions\Stamps\WorksAt;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\User;
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
 * business without the cache.
 */
final readonly class ScanMemberQr
{
    public function __construct(private VerifyMemberQr $verifyMemberQr, private WorksAt $worksAt) {}

    /**
     * @throws MemberQrRefused
     */
    public function handle(User $staff, Location $location, #[SensitiveParameter] string $scanned): CounterScan
    {
        if (! $this->worksAt->handle($staff->id, $location->business_id, $location->id)) {
            throw new LogicException('Only the business\'s staff, owners or org admins scan member QRs there.');
        }

        $scan = $this->verifyMemberQr->handle($scanned, 'scan:business:'.$location->business_id);

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
}

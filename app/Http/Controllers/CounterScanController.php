<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Counter\DescribeScan;
use App\Actions\Counter\RedeemScan;
use App\Actions\Counter\ScanLocations;
use App\Actions\Counter\ScanMemberQr;
use App\Actions\Counter\ScanRefusal;
use App\Actions\Counter\ScanRefused;
use App\Actions\Counter\StampScan;
use App\Actions\MemberQr\MemberQrRefusal;
use App\Actions\MemberQr\MemberQrRefused;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Stamps\StampRejected;
use App\Enums\RedeemRefusal;
use App\Enums\StampRejection;
use App\Http\Requests\Counter\ScanMemberQrRequest;
use App\Http\Requests\Counter\StampScanRequest;
use App\Models\CounterScan;
use App\Models\Location;
use App\Models\Reward;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff scanning a customer's member QR at the counter (CHW-30), for
 * whoever works in the business:
 *
 * - GET /business/counter/scan: the scanner (S2a), at one of the sites they
 *   work at (ScanLocations).
 * - POST /business/counter/scans (throttled): the code read or typed
 *   (ScanMemberQr), then its result. A code that doesn't count is an error on
 *   the field, or, when there is nothing to retry, a refusal the scanner
 *   shows in full (flash "scanRefusal").
 * - GET /business/counter/scans/{scan}: the result (S2, DescribeScan), the
 *   staff member's own scan only (CounterScanPolicy).
 * - POST …/stamps: stamps (StampScan); the outcome comes back as flash
 *   "stampResult" for S2 to show in place.
 * - POST …/rewards/{reward}/redeem: a reward (RedeemScan, S3), flash
 *   "redeemed".
 *
 * {scan} resolves in the tenant: another business's is a 404.
 */
final class CounterScanController extends Controller
{
    public function create(TenantContext $context, ScanLocations $scanLocations): Response
    {
        $business = $context->business() ?? abort(403);

        return Inertia::render('business/scan', [
            'locations' => $scanLocations->handle($business, $context->locationId()),
        ]);
    }

    public function store(ScanMemberQrRequest $request, TenantContext $context, ScanLocations $scanLocations, ScanMemberQr $scanMemberQr): RedirectResponse
    {
        $business = $context->business() ?? abort(403);
        $location = $scanLocations->find($business, $context->locationId(), $request->location()) ?? abort(404);

        /** @var User $staff */
        $staff = $request->user();

        try {
            $scan = $scanMemberQr->handle($staff, $location, $request->code());
        } catch (MemberQrRefused|ScanRefused $refused) {
            return $this->refused($refused->reason);
        }

        return to_route('counter.scans.show', $scan);
    }

    public function show(CounterScan $scan, DescribeScan $describeScan, TenantContext $context): Response|RedirectResponse
    {
        Gate::authorize('use', $scan);

        // A scan that ran out unused shows no customer: its page is no way to follow them afterwards. One that
        // stamped or redeemed still shows, for a retry that gets its answer after the two minutes.
        $used = $scan->stamp_event_id !== null
            || $context->bypass(fn (): bool => Reward::query()->where('redeemed_scan_id', $scan->id)->exists());

        if ($scan->hasExpired() && ! $used) {
            Inertia::flash('scanRefusal', 'expired');

            return to_route('counter.scan');
        }

        return Inertia::render('business/scan-result', [
            'scan' => $scan->id,
            ...$describeScan->handle($scan),
            'maxQty' => StampScanRequest::MAX_QTY,
            'totalSeconds' => CounterScan::SECONDS,
        ]);
    }

    public function stamp(StampScanRequest $request, CounterScan $scan, StampScan $stampScan): RedirectResponse
    {
        Gate::authorize('use', $scan);

        /** @var User $staff */
        $staff = $request->user();

        try {
            $result = $stampScan->handle($scan, $staff, $request->qty(), $request->key());
            Inertia::flash('stampResult', ['state' => 'done', 'qty' => $result->event->qty, 'rewards' => count($result->rewards)]);
        } catch (StampRejected $rejected) {
            $this->stampRejected($rejected, $scan);
        } catch (ScanRefused $refused) {
            Inertia::flash('stampResult', ['state' => $refused->reason->value]);
        }

        return to_route('counter.scans.show', $scan);
    }

    public function redeem(Request $request, CounterScan $scan, int $reward, TenantContext $context, RedeemScan $redeemScan): RedirectResponse
    {
        Gate::authorize('use', $scan);

        /** @var User $staff */
        $staff = $request->user();

        // In bypass(): a franchise sibling redeems a reward earned at another of its businesses. Only the scanned
        // customer's resolve (another id is a 404, whoever's it is); RedeemScan refuses one on a card this business
        // doesn't honour.
        $current = $context->bypass(fn (): ?Reward => Reward::query()
            ->whereKey($reward)
            ->whereHas('enrollment', fn ($enrollments) => $enrollments->where('user_id', $scan->user_id))
            ->first()) ?? abort(404);

        try {
            $result = $redeemScan->handle($scan, $staff, $current);
            $location = $context->bypass(fn (): ?Location => Location::query()->find($scan->location_id));
            Inertia::flash('redeemed', [
                'reward' => $result->reward->reward_text,
                'at' => LocalMoment::of($result->reward->redeemed_at ?? now(), LocalMoment::timezoneOf($location))['time'],
                'location' => $location?->name,
            ]);
        } catch (RedeemRefused $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $this->redeemMessage($refused->refusal)]);
        } catch (ScanRefused $refused) {
            Inertia::flash('stampResult', ['state' => $refused->reason->value]);
        }

        return to_route('counter.scans.show', $scan);
    }

    /**
     * A code that doesn't count: a typing mistake or a late code is an error on the field, to correct; the rest
     * leave nothing to retry with this code and show in full on the scanner.
     */
    private function refused(MemberQrRefusal|ScanRefusal $reason): RedirectResponse
    {
        $message = match ($reason) {
            MemberQrRefusal::Malformed, MemberQrRefusal::Forged => __("That code doesn't match. Check the numbers, or scan the QR."),
            MemberQrRefusal::Expired => __('That code has run out. Ask for the one on screen now.'),
            MemberQrRefusal::UnknownUser => __('No customer has this code any more.'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['code' => $message]);
        }

        Inertia::flash('scanRefusal', $reason->value);

        return to_route('counter.scan');
    }

    /** Why a stamp was refused, for S2 to say in place: a wait, a card not running, or a toast. */
    private function stampRejected(StampRejected $rejected, CounterScan $scan): void
    {
        $state = match ($rejected->rejection) {
            StampRejection::Cooldown => 'cooldown',
            StampRejection::DailyCap => 'cap',
            StampRejection::CardInactive, StampRejection::NotHonoured => 'paused',
            default => null,
        };

        if ($state === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => match ($rejected->rejection) {
                StampRejection::SiteClosed => __('This site is closed: nothing was added.'),
                StampRejection::AccountDeleted => __('This customer\'s account was deleted: nothing was added.'),
                default => __('The stamp could not be added. Try again.'),
            }]);

            return;
        }

        $location = app(TenantContext::class)->bypass(fn (): ?Location => Location::query()->find($scan->location_id));

        Inertia::flash('stampResult', [
            'state' => $state,
            // Today, tomorrow or a later day: a cooldown can run a day or more.
            'availableAt' => LocalMoment::next($rejected->availableAt, LocalMoment::timezoneOf($location)),
        ]);
    }

    private function redeemMessage(RedeemRefusal $refusal): string
    {
        return match ($refusal) {
            RedeemRefusal::Unverified => __('The customer must verify their email before redeeming.'),
            RedeemRefusal::NotHonoured => __('This business does not honour that card.'),
            RedeemRefusal::SiteClosed => __('This site is closed: nothing was redeemed.'),
            RedeemRefusal::Unavailable => __('That reward is no longer available.'),
            RedeemRefusal::AccountDeleted => __('This customer\'s account was deleted: nothing was redeemed.'),
            default => __('That reward is not this customer\'s.'),
        };
    }
}

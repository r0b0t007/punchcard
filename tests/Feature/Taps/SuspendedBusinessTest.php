<?php

declare(strict_types=1);

use App\Actions\Admin\ReinstateBusiness;
use App\Actions\Admin\SuspendBusiness;
use App\Actions\Rewards\OpenRedeemWindow;
use App\Actions\Rewards\RedeemPresence;
use App\Actions\Rewards\RedeemRefused;
use App\Actions\Rewards\RedeemReward;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Actions\Stamps\StampRequest;
use App\Actions\Taps\ApplyTap;
use App\Actions\Taps\DescribeTap;
use App\Actions\Taps\ReceiveTap;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\RedeemRefusal;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\NfcTag;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A suspended business stops at the counter (CHW-22)
|--------------------------------------------------------------------------
|
| Its people get no tenant (ResolveTenant), and its stampers, staff and
| redemptions stop: taps, manual stamps and redemptions are refused as
| unavailable. A pending business keeps working, so a pilot café starts at
| once.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-07 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->customer = User::factory()->create();

    $this->received = function (int $counter): Tap {
        $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
        $url = app(FakeTap::class)->build($tag->uid, $counter);

        return app(ReceiveTap::class)->handle($url['e'], $url['c'], null, null, null);
    };
    // Suspended through the admin's Action (CHW-34), as in production; other statuses set directly.
    $this->status = fn (BusinessStatus $status) => $status === BusinessStatus::Suspended
        ? app(SuspendBusiness::class)->handle($this->tenants->a1, 'Under review')
        : $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => $status])->save());
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => StampEvent::query()->count());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refuses a tap at a suspended business, and stamps at a pending one', function (BusinessStatus $status, bool $stamps): void {
    $pending = ($this->received)(5);
    ($this->status)($status);

    $tap = app(ApplyTap::class)->handle($pending, $this->customer);

    expect($tap->status)->toBe($stamps ? TapStatus::Stamped : TapStatus::Rejected)
        ->and(($this->stamps)())->toBe($stamps ? 1 : 0);

    if (! $stamps) {
        expect($tap->rejection)->toBe(TapRejection::SiteClosed)
            ->and(app(DescribeTap::class)->handle($tap)['props']['reason'])->toBe('unavailable');
    }
})->with([
    'suspended' => [BusinessStatus::Suspended, false],
    'pending' => [BusinessStatus::Pending, true],
]);

it('refuses a tap made during a suspension when it arrives, so a reinstatement never applies it', function (): void {
    ($this->status)(BusinessStatus::Suspended);
    $tap = ($this->received)(5);
    app(ReinstateBusiness::class)->handle($this->tenants->a1);

    expect($tap->status)->toBe(TapStatus::Rejected)
        ->and($tap->rejection)->toBe(TapRejection::SiteClosed);

    app(ApplyTap::class)->handle($tap, $this->customer);

    expect(($this->stamps)())->toBe(0);
});

it('reads the business under a share lock, after the stamper, when it applies a tap', function (): void {
    $pending = ($this->received)(5);
    DB::enableQueryLog();

    app(ApplyTap::class)->handle($pending, $this->customer);

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    $stamper = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "stampers"') && str_contains($sql, 'for update'));
    $business = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "businesses" where') && str_ends_with(trim($sql), 'for share'));

    expect($stamper)->toBeInt()
        ->and($business)->toBeInt()
        ->and($stamper)->toBeLessThan($business);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

it('refuses a manual stamp at a suspended business', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    ($this->status)(BusinessStatus::Suspended);

    expect(fn () => $this->context->bypass(fn () => app(AddStamps::class)->handle($enrollment, StampRequest::manual($this->tenants->locationOf($this->tenants->a1), $staff, (string) Str::uuid(), 'Card forgotten', 1))))
        ->toThrow(function (StampRejected $rejected): void {
            expect($rejected->rejection)->toBe(StampRejection::SiteClosed);
        });
    expect(($this->stamps)())->toBe(0);
});

it('still takes back stamps given at a suspended business', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    app(ApplyTap::class)->handle(($this->received)(5), $this->customer);
    ($this->status)(BusinessStatus::Suspended);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($enrollment, StampRequest::correction($this->tenants->locationOf($this->tenants->a1), $staff, (string) Str::uuid(), 'Double stamp', -1)));

    expect($this->context->bypass(fn (): int => (int) StampEvent::query()->sum('qty')))->toBe(0);
});

it('still gives a system stamp at a suspended business: it follows its own rules', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    ($this->status)(BusinessStatus::Suspended);

    $this->context->bypass(fn () => app(AddStamps::class)->handle($enrollment, StampRequest::system(StampSource::Birthday, $this->tenants->locationOf($this->tenants->a1), (string) Str::uuid())));

    expect(($this->stamps)())->toBe(1);
});

it('keeps the other franchisees of the card stamping while one is suspended', function (): void {
    ($this->status)(BusinessStatus::Suspended);
    $this->stamper = $this->tenants->stamper($this->tenants->a2);

    expect(app(ApplyTap::class)->handle(($this->received)(5), $this->customer)->status)->toBe(TapStatus::Stamped);
});

it('refuses a redemption at a suspended business', function (): void {
    $reward = $this->tenants->reward($this->tenants->enroll($this->customer, $this->tenants->cardA));
    app(OpenRedeemWindow::class)->handle($reward, $this->customer);
    $this->travel(5)->seconds();
    $presence = RedeemPresence::tap(($this->received)(5));
    ($this->status)(BusinessStatus::Suspended);

    expect(fn () => app(RedeemReward::class)->handle($reward, $this->customer, $presence))
        ->toThrow(function (RedeemRefused $refused): void {
            expect($refused->refusal)->toBe(RedeemRefusal::SiteClosed);
        });
});

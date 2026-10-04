<?php

declare(strict_types=1);

use App\Actions\Taps\ReceiveTap;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\NfcTag;
use App\Models\Tap;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Fake taps and the tap log's retention (CHW-25)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
});

it('builds the same URL a provisioned tag emits', function (): void {
    $tap = app(FakeTap::class)->build(SunVectors::PROVISIONED_TAP['uid'], SunVectors::PROVISIONED_TAP['counter']);

    expect($tap)->toBe(['e' => SunVectors::PROVISIONED_TAP['e'], 'c' => SunVectors::PROVISIONED_TAP['c']]);
});

it('prints a working URL for a stamper\'s next tap', function (): void {
    Artisan::call('punchcard:fake-tap', ['stamper' => $this->stamper->id]);
    parse_str((string) parse_url(trim(Artisan::output()), PHP_URL_QUERY), $query);

    $tap = app(ReceiveTap::class)->handle((string) $query['e'], (string) $query['c'], null, '203.0.113.7', null);

    expect($tap->status)->toBe(TapStatus::Pending)
        ->and($tap->counter)->toBe(1);
});

it('builds fake taps only in local and testing', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    try {
        expect(fn () => app(FakeTap::class)->build(SunVectors::PROVISIONED_TAP['uid'], 7))->toThrow(LogicException::class, 'only in local and testing')
            ->and(Artisan::call('punchcard:fake-tap', ['stamper' => $this->stamper->id]))->toBe(1);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
})->with(['production', 'staging', 'prod']);

it('prunes the tap log after 180 days', function (): void {
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    $this->context->bypass(function () use ($tag): void {
        foreach ([181, 179] as $days) {
            (new Tap)->forceFill([
                'nfc_tag_id' => $tag->id,
                'counter' => $days,
                'status' => TapStatus::Rejected,
                'rejection' => TapRejection::RetiredTag,
                'created_at' => now()->subDays($days),
            ])->save();
        }
    });

    Artisan::call('model:prune', ['--model' => [Tap::class]]);

    expect($this->context->bypass(fn (): array => Tap::query()->pluck('counter')->all()))->toBe([179]);
});

it('keeps the tap log out of reach outside bypass()', function (): void {
    $this->context->bypass(fn () => (new Tap)->forceFill(['status' => TapStatus::Rejected, 'rejection' => TapRejection::Malformed])->save());

    expect(Tap::query()->count())->toBe(0)
        ->and(fn () => (new Tap)->forceFill(['status' => TapStatus::Rejected, 'rejection' => TapRejection::Malformed])->save())->toThrow(LogicException::class, 'platform state');
});

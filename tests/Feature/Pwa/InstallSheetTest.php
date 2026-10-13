<?php

declare(strict_types=1);

use App\Enums\StampSource;
use App\Models\NfcTag;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The install sheet, after the second stamp (CHW-35, C13)
|--------------------------------------------------------------------------
|
| The stamp screen (C2) offers to install the app from the customer's second
| visit on: their second stamp proving presence (a tap, a scan, a stamp by
| staff), on any card, counted up to the stamp on screen. Whether the phone
| can install, and whether it was asked lately, is the phone's to know
| (install-prompt.ts).
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $context = app(TenantContext::class);
    $tag = $context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->tenants->stamper($this->tenants->a1)->nfc_tag_id));
    $this->customer = User::factory()->create();

    $this->tap = fn (int $counter) => $this->actingAs($this->customer)
        ->get(route('taps.receive', app(FakeTap::class)->build($tag->uid, $counter)))
        ->assertRedirect(route('taps.result'));
    $this->offersInstall = fn (bool $offered) => $this->get(route('taps.result'))
        ->assertInertia(fn (Assert $page): Assert => $page->component('tap/stamped')->where('offerInstall', $offered));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('never offers it on the first stamp', function (): void {
    ($this->tap)(5);

    ($this->offersInstall)(false);
});

it('offers it from the second visit on', function (): void {
    ($this->tap)(5);
    $this->travel(1)->days();
    ($this->tap)(6);

    ($this->offersInstall)(true);
});

it('counts a visit to any café, on any card', function (): void {
    $elsewhere = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->tenants->stamp($elsewhere, $this->tenants->b1, ['created_at' => now()->subDays(3)]);

    ($this->tap)(5);

    ($this->offersInstall)(true);
});

it('counts only stamps given in person, not bonus or correction stamps', function (): void {
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);

    foreach ([StampSource::Bonus, StampSource::Birthday, StampSource::Referral] as $source) {
        $this->tenants->stamp($enrollment, $this->tenants->a1, ['source' => $source, 'created_at' => now()->subDays(3)]);
    }

    // A correction takes stamps back.
    $this->tenants->stamp($enrollment, $this->tenants->a1, ['source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Stamped twice', 'created_at' => now()->subDays(2)]);

    ($this->tap)(5);

    ($this->offersInstall)(false);
});

it('counts up to the stamp on screen, not the stamps since', function (): void {
    ($this->tap)(5);
    $later = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->tenants->stamp($later, $this->tenants->b1);

    // The first stamp's screen, opened again: still the first visit.
    ($this->offersInstall)(false);
});

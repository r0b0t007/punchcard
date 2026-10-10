<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\StampSource;
use App\Http\Middleware\SetTenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The setup checklist on the dashboard (CHW-31, spec B2)
|--------------------------------------------------------------------------
|
| After the wizard, the owner's dashboard says what is left before the
| first customers: print the QR stand, place the stamper (done with its
| first tap), invite staff. Each item ticks itself from what happened.
| Customers and staff see no checklist.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $this->checklist = fn (?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->b1->id])
        ->get(route('dashboard'))->assertOk();
});

it('shows the owner what is left, nothing done yet', function (): void {
    ($this->checklist)()->assertInertia(fn ($page) => $page->component('dashboard')
        ->where('checklist.qrStand', false)
        ->where('checklist.stamperPlaced', false)
        ->where('checklist.staffInvited', false));
});

it('ticks each item from what happened', function (): void {
    $stamper = $this->tenants->stamper($this->tenants->b1);
    $this->context->bypass(fn () => $this->tenants->b1->forceFill(['qr_stand_opened_at' => now()])->save());
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardB), $this->tenants->b1, [
        'source' => StampSource::Nfc, 'stamper_id' => $stamper->id, 'nfc_tag_id' => $stamper->nfc_tag_id, 'counter' => 1,
    ]);
    $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Staff);

    ($this->checklist)()->assertInertia(fn ($page) => $page
        ->where('checklist.qrStand', true)
        ->where('checklist.stamperPlaced', true)
        ->where('checklist.staffInvited', true));
});

it('counts the stamper placed only once it has been tapped, not for a stamp by hand', function (): void {
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardB), $this->tenants->b1, ['source' => StampSource::Qr]);

    ($this->checklist)()->assertInertia(fn ($page) => $page->where('checklist.stamperPlaced', false));
});

it('shows no checklist to a customer or to staff', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Staff);

    foreach ([User::factory()->create(), $staff] as $user) {
        ($this->checklist)($user)->assertInertia(fn ($page) => $page->where('checklist', null));
    }
});

it('reads only the owner\'s business: what happens at another never ticks it', function (): void {
    $stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA), $this->tenants->a1, [
        'source' => StampSource::Nfc, 'stamper_id' => $stamper->id, 'nfc_tag_id' => $stamper->nfc_tag_id, 'counter' => 1,
    ]);
    $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);

    ($this->checklist)()->assertInertia(fn ($page) => $page
        ->where('checklist.stamperPlaced', false)
        ->where('checklist.staffInvited', false));
});

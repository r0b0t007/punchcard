<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The printable QR stand (CHW-31, B2 checklist)
|--------------------------------------------------------------------------
|
| A page the owner prints for the counter: the business's name and logo,
| and a QR code to its join page, so a customer without NFC can add the
| card. Opening it ticks "Print your QR stand" on the checklist.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $this->stand = fn (User $as) => $this->actingAs($as)
        ->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->b1->id])
        ->get(route('business.qr-stand'));
    $this->openedAt = fn (): mixed => $this->context->bypass(fn (): mixed => Business::query()->findOrFail($this->tenants->b1->id)->qr_stand_opened_at);
});

it('shows the owner a stand whose QR leads to the business\'s join page', function (): void {
    $joinUrl = route('join.show', $this->tenants->b1->slug);
    $expected = (new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd)))->writeString($joinUrl);

    ($this->stand)($this->owner)->assertOk()
        ->assertInertia(fn ($page) => $page->component('business/qr-stand')
            ->where('businessName', 'B1')
            ->where('joinUrl', $joinUrl)
            ->where('qrCode', 'data:image/svg+xml;base64,'.base64_encode($expected)));
});

it('ticks the checklist the first time the owner opens it', function (): void {
    $this->travelTo(now()->subDay());
    ($this->stand)($this->owner);
    $first = ($this->openedAt)();
    $this->travelBack();

    ($this->stand)($this->owner);

    expect($first)->not->toBeNull()
        ->and(($this->openedAt)()?->toDateTimeString())->toBe($first?->toDateTimeString());
});

it('is the owner\'s: staff and customers can\'t open it', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Staff);

    ($this->stand)($staff)->assertForbidden();
    ($this->stand)(User::factory()->create())->assertForbidden();

    expect(($this->openedAt)())->toBeNull();
});

it('never opens another franchisee\'s stand, nor one for franchise HQ', function (): void {
    $ownerA1 = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);

    // A1's owner asking for A2 stays in A1: A2's stand is never shown or marked.
    $this->actingAs($ownerA1)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a2->id])
        ->get(route('business.qr-stand'))->assertOk()->assertInertia(fn ($page) => $page->where('businessName', 'A1'));
    $this->actingAs($hq)->withSession([SetTenant::SESSION_KEY => 'org:'.$this->tenants->orgA->id])
        ->get(route('business.qr-stand'))->assertForbidden();

    expect($this->context->bypass(fn (): mixed => Business::query()->findOrFail($this->tenants->a2->id)->qr_stand_opened_at))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Actions\Cards\DescribeJoinPage;
use App\Actions\Cards\EnrollCustomer;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Which card a customer gets at a business (CHW-148, CHW-32)
|--------------------------------------------------------------------------
|
| A card they hold comes first, so a tap never splits their progress, but
| only while it runs: a customer holding a paused card gets the card the
| business runs now (their old stamps stay on the old card). With no card
| running, nothing is offered and a tap is refused, as before.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->business = $this->tenants->b1;
    $this->customer = User::factory()->create();
    $this->old = $this->tenants->cardB;
    $this->tenants->enroll($this->customer, $this->old);
    $this->pause = fn (LoyaltyCard $card) => $this->context->bypass(fn () => $card->forceFill(['active' => false])->save());
    $this->addCard = fn (string $name): LoyaltyCard => $this->context->bypass(function () use ($name): LoyaltyCard {
        $card = LoyaltyCard::factory()->for($this->tenants->orgB)->create(['name' => $name]);
        $card->businesses()->attach($this->business->id);

        return $card;
    });
});

it('keeps a customer on the running card they hold', function (): void {
    ($this->addCard)('Newer card');

    expect(app(EnrollCustomer::class)->handle($this->business, $this->customer)?->card_id)->toBe($this->old->id);
});

it('moves a customer holding a paused card onto the card the business runs now', function (): void {
    ($this->pause)($this->old);
    $new = ($this->addCard)('New card');

    expect(app(DescribeJoinPage::class)->handle($this->business, $this->customer))->toMatchArray(['available' => true, 'joined' => false])
        ->and(app(EnrollCustomer::class)->handle($this->business, $this->customer)?->card_id)->toBe($new->id);
});

it('keeps them on the paused card when the business runs none, which a tap then refuses', function (): void {
    ($this->pause)($this->old);

    expect(app(EnrollCustomer::class)->handle($this->business, $this->customer)?->card_id)->toBe($this->old->id)
        ->and(app(DescribeJoinPage::class)->handle($this->business, $this->customer)['available'])->toBeFalse();
});

it('keeps a customer on the newer running card they hold, even when an older one runs too', function (): void {
    $newer = ($this->addCard)('Newer card');
    $other = User::factory()->create();
    $this->tenants->enroll($other, $newer);

    expect(app(EnrollCustomer::class)->handle($this->business, $other)?->card_id)->toBe($newer->id);
});

it('keeps a customer on the paused card they hold when none runs, not another paused one', function (): void {
    $newer = ($this->addCard)('Newer card');
    $other = User::factory()->create();
    $this->tenants->enroll($other, $newer);
    ($this->pause)($this->old);
    ($this->pause)($newer);

    expect(app(EnrollCustomer::class)->handle($this->business, $other)?->card_id)->toBe($newer->id);
});

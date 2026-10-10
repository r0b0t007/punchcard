<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A card's stamp count once customers hold it (CHW-32)
|--------------------------------------------------------------------------
|
| Lowering the stamps a reward takes removes nothing: customers who now have
| enough get their reward with their next stamp. Raising it would move the
| goal under them, so once customers hold the card it is refused (start a
| new card), whoever writes, as for its mode and tiers.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->card = $this->tenants->cardB;
    $this->asOwner = fn () => $this->context->set($this->tenants->orgB, $this->tenants->b1, orgAdmin: true, businessRole: BusinessRole::Owner, ownsTheAccount: true);
    $this->required = fn (): int => $this->context->bypass(fn (): int => LoyaltyCard::query()->findOrFail($this->card->id)->stamps_required);
});

it('raises the stamp count of a card nobody holds yet', function (): void {
    ($this->asOwner)();
    LoyaltyCard::query()->findOrFail($this->card->id)->forceFill(['stamps_required' => 12])->save();

    expect(($this->required)())->toBe(12);
});

it('lowers the stamp count of a card customers hold', function (): void {
    $this->tenants->enroll(User::factory()->create(), $this->card);
    ($this->asOwner)();

    LoyaltyCard::query()->findOrFail($this->card->id)->forceFill(['stamps_required' => 8])->save();

    expect(($this->required)())->toBe(8);
});

it('never raises the stamp count of a card customers hold, whoever writes', function (string $who): void {
    $this->tenants->enroll(User::factory()->create(), $this->card);

    $raise = fn () => LoyaltyCard::query()->findOrFail($this->card->id)->forceFill(['stamps_required' => 12])->save();

    if ($who === 'the owner') {
        ($this->asOwner)();
        $raise();
    } else {
        $this->context->bypass($raise);
    }
})->throws(LogicException::class, 'raised')->with(['the owner', 'bypass()']);

it('refuses a bulk change of the stamp count: it can\'t tell which cards are held', function (): void {
    $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->card->id)->update(['stamps_required' => 8]));
})->throws(LogicException::class, 'one card at a time');

it('keeps the stamp style to the known ones on Postgres', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('loyalty_cards')->where('id', $this->card->id)->update(['stamp_style' => 'skull'])))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The check constraint is Postgres only');

it('never raises it by increment(), nor through a query built from a loaded card', function (string $how): void {
    $this->tenants->enroll(User::factory()->create(), $this->card);
    $loaded = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->findOrFail($this->card->id));

    $this->context->bypass(fn () => match ($how) {
        'increment' => $loaded->increment('stamps_required', 5),
        'query from a card' => $loaded->newQuery()->update(['stamps_required' => 12]),
    });
})->throws(LogicException::class, 'one card at a time')->with(['increment', 'query from a card']);

it('never raises it from a stale copy that still has the old count', function (): void {
    $this->tenants->enroll(User::factory()->create(), $this->card);
    $stale = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->findOrFail($this->card->id));
    $this->context->bypass(fn () => LoyaltyCard::query()->findOrFail($this->card->id)->forceFill(['stamps_required' => 5])->save());

    // Still below the 10 the stale copy remembers, yet above the 5 stored now: a raise.
    $this->context->bypass(fn () => $stale->forceFill(['stamps_required' => 8])->save());
})->throws(LogicException::class, 'never raised');

it('never lets a query built from a card write its count over other cards', function (): void {
    $short = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgB)->create(['stamps_required' => 5]));
    $this->tenants->enroll(User::factory()->create(), $short);
    $loaded = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::query()->findOrFail($this->card->id));

    // The loaded card keeps its own 10: only the other, held card would be raised.
    $this->context->bypass(fn () => $loaded->newQuery()->update(['stamps_required' => 10]));
})->throws(LogicException::class, 'one card at a time');

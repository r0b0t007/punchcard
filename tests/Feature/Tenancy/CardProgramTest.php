<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Models\CardBusiness;
use App\Models\LoyaltyCard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Card programs belong to the organization (ADR 0006)
|--------------------------------------------------------------------------
|
| A loyalty card is program data: every business of the organization sees
| it (a franchise shares one card), no other organization does. Only an org
| admin changes the program and which businesses honour it (card_business);
| a franchisee cannot change the card the others share.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
});

describe('reads', function (): void {
    it('shows the organization\'s cards to its org admin and every franchisee, never another organization\'s', function (string $tenant): void {
        match ($tenant) {
            'org admin of A' => $this->context->set($this->tenants->orgA, orgAdmin: true),
            'franchisee A1' => $this->context->set($this->tenants->orgA, $this->tenants->a1),
            'franchisee A2' => $this->context->set($this->tenants->orgA, $this->tenants->a2),
            'business B1' => $this->context->set($this->tenants->orgB, $this->tenants->b1),
        };

        expect(LoyaltyCard::query()->pluck('id')->all())->toBe([
            $tenant === 'business B1' ? $this->tenants->cardB->id : $this->tenants->cardA->id,
        ]);
    })->with(['org admin of A', 'franchisee A1', 'franchisee A2', 'business B1']);

    it('shows no card and no participation without a tenant', function (): void {
        expect(LoyaltyCard::query()->count())->toBe(0)
            ->and(CardBusiness::query()->count())->toBe(0);
    });

    it('shows a franchisee which sibling business ids honour the card, but not the sibling businesses', function (): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1);

        expect(CardBusiness::query()->orderBy('business_id')->pluck('business_id')->all())->toBe([$this->tenants->a1->id, $this->tenants->a2->id])
            ->and($this->tenants->cardA->businesses()->pluck('businesses.id')->all())->toBe([$this->tenants->a1->id]);

        $this->context->set($this->tenants->orgB, $this->tenants->b1);

        expect(CardBusiness::query()->pluck('business_id')->all())->toBe([$this->tenants->b1->id]);
    });

    it('lists the businesses that honour a shared card', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect($this->tenants->cardA->businesses()->orderBy('businesses.id')->pluck('businesses.id')->all())
            ->toBe([$this->tenants->a1->id, $this->tenants->a2->id]);
    });
});

describe('cards', function (): void {
    it('lets an org admin create a card in their organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $card = LoyaltyCard::query()->create(['name' => 'Summer card', 'stamps_required' => 8, 'reward_text' => 'Free iced tea']);

        expect($card->organization_id)->toBe($this->tenants->orgA->id);
    });

    it('lets the owner of an independent café, its org admin, change its card from inside the business', function (): void {
        $this->context->set($this->tenants->orgB, $this->tenants->b1, orgAdmin: true, businessRole: BusinessRole::Owner);

        $this->tenants->cardB->update(['stamps_required' => 12]);

        expect($this->tenants->cardB->refresh()->stamps_required)->toBe(12);
    });

    it('does not let a franchisee change the shared card', function (string $role, string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::from($role));

        match ($how) {
            'create' => LoyaltyCard::query()->create(['name' => 'Own card', 'stamps_required' => 5, 'reward_text' => 'x']),
            'update' => $this->tenants->cardA->update(['reward_text' => 'Nothing']),
            'bulk update' => LoyaltyCard::query()->update(['active' => false]),
            'delete' => $this->tenants->cardA->delete(),
        };
    })->throws(LogicException::class, 'Only an org admin changes the card program.')
        ->with(['owner', 'staff'])
        ->with(['create', 'update', 'bulk update', 'delete']);

    it('refuses a card for another organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        LoyaltyCard::query()->create(['organization_id' => $this->tenants->orgB->id, 'name' => 'x', 'stamps_required' => 5, 'reward_text' => 'x']);
    })->throws(LogicException::class);

    it('never moves a card to another organization', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $this->tenants->cardA->update(['organization_id' => $this->tenants->orgB->id]);
    })->throws(LogicException::class);

    it('starts a card with the spec\'s anti-fraud defaults', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $card = LoyaltyCard::query()->create(['name' => 'Defaults', 'stamps_required' => 10, 'reward_text' => 'x'])->refresh();

        expect($card->cooldown_min)->toBe(20)
            ->and($card->daily_cap)->toBe(5);
    });

    it('lets Postgres refuse a card the stamp flow cannot work with', function (array $values): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => LoyaltyCard::query()->create([
            'name' => 'Broken', 'stamps_required' => 10, 'reward_text' => 'x', ...$values,
        ])))->toThrow(QueryException::class);
    })->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'CHECK constraints are Postgres only')->with([
        'no stamps required' => [['stamps_required' => 0]],
        'too many stamps' => [['stamps_required' => 51]],
        'a negative cooldown' => [['cooldown_min' => -1]],
        'a zero daily cap' => [['daily_cap' => 0]],
        'a percent reward without a value' => [['reward_type' => 'percent', 'reward_value' => null]],
        'more than 100 percent' => [['reward_type' => 'percent', 'reward_value' => 150]],
        'a fixed reward of zero' => [['reward_type' => 'fixed', 'reward_value' => 0]],
    ]);

    it('needs a tenant or bypass() to create a card', function (): void {
        LoyaltyCard::query()->create(['organization_id' => $this->tenants->orgA->id, 'name' => 'x', 'stamps_required' => 5, 'reward_text' => 'x']);
    })->throws(LogicException::class);

    it('refuses raw inserts and upserts outside bypass()', function (string $how): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $row = ['organization_id' => $this->tenants->orgA->id, 'name' => 'x', 'stamps_required' => 5, 'reward_text' => 'x'];

        match ($how) {
            'card insert' => LoyaltyCard::query()->insert($row),
            'card upsert' => LoyaltyCard::query()->upsert([$row], ['id']),
            'participation insert' => CardBusiness::query()->insert(['organization_id' => $this->tenants->orgA->id, 'card_id' => $this->tenants->cardA->id, 'business_id' => $this->tenants->a1->id]),
        };
    })->throws(LogicException::class)->with(['card insert', 'card upsert', 'participation insert']);

    it('does not let another organization\'s admin change or remove the card or who honours it', function (string $how): void {
        $this->context->set($this->tenants->orgB, orgAdmin: true);

        match ($how) {
            'update the card' => $this->tenants->cardA->update(['name' => 'hijacked']),
            'delete the card' => $this->tenants->cardA->delete(),
            'detach' => $this->tenants->cardA->businesses()->detach([$this->tenants->a1->id]),
            'update participation' => $this->tenants->cardA->businesses()->updateExistingPivot($this->tenants->a1->id, ['created_at' => now()->subDay()]),
            'toggle' => $this->tenants->cardA->businesses()->toggle([$this->tenants->a1->id]),
        };
    })->throws(LogicException::class)->with(['update the card', 'delete the card', 'detach', 'update participation', 'toggle']);

    it('limits another organization\'s bulk writes to its own cards', function (): void {
        $this->context->set($this->tenants->orgB, orgAdmin: true);

        LoyaltyCard::query()->update(['active' => false]);
        CardBusiness::query()->delete();

        $this->context->bypass(function (): void {
            expect($this->tenants->cardA->refresh()->active)->toBeTrue()
                ->and($this->tenants->cardB->refresh()->active)->toBeFalse()
                ->and(CardBusiness::query()->where('card_id', $this->tenants->cardA->id)->count())->toBe(2)
                ->and(CardBusiness::query()->where('card_id', $this->tenants->cardB->id)->count())->toBe(0);
        });
    });
});

describe('participating businesses', function (): void {
    it('lets an org admin choose which businesses honour a card', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);
        $card = LoyaltyCard::query()->create(['name' => 'A1 only', 'stamps_required' => 6, 'reward_text' => 'x']);

        $card->businesses()->attach($this->tenants->a1);
        $card->businesses()->syncWithoutDetaching([$this->tenants->a2->id]);
        $card->businesses()->detach([$this->tenants->a2->id]);

        expect($card->businesses()->pluck('businesses.id')->all())->toBe([$this->tenants->a1->id])
            ->and(CardBusiness::query()->where('card_id', $card->id)->value('organization_id'))->toBe($this->tenants->orgA->id);
    });

    it('does not let a franchisee change which businesses honour the shared card', function (string $role, string $how): void {
        $newCard = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::from($role));

        match ($how) {
            'attach itself to a card' => $newCard->businesses()->attach($this->tenants->a1),
            'detach a sibling' => $this->tenants->cardA->businesses()->detach([$this->tenants->a2->id]),
            'detach everyone' => $this->tenants->cardA->businesses()->detach(),
            'detach through wherePivot' => $this->tenants->cardA->businesses()->wherePivot('business_id', $this->tenants->a2->id)->detach(),
            'sync to itself' => $this->tenants->cardA->businesses()->sync([$this->tenants->a1->id]),
            'toggle' => $this->tenants->cardA->businesses()->toggle([$this->tenants->a2->id]),
            'update participation' => $this->tenants->cardA->businesses()->updateExistingPivot($this->tenants->a1->id, ['created_at' => now()->subDay()]),
            'bulk update' => CardBusiness::query()->update(['updated_at' => now()]),
            'bulk delete' => CardBusiness::query()->delete(),
        };
    })->throws(LogicException::class, 'Only an org admin changes the card program.')
        ->with(['owner', 'staff'])
        ->with(['attach itself to a card', 'detach a sibling', 'detach everyone', 'detach through wherePivot', 'sync to itself', 'toggle', 'update participation', 'bulk update', 'bulk delete']);

    it('never moves a participation to another card, even in bypass()', function (string $how): void {
        $newCard = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        match ($how) {
            'updateExistingPivot' => $this->tenants->cardA->businesses()->updateExistingPivot($this->tenants->a2->id, ['card_id' => $newCard->id]),
            'bulk update in bypass' => $this->context->bypass(fn (): int => CardBusiness::query()->update(['card_id' => $newCard->id])),
        };
    })->throws(LogicException::class, 'A participation cannot move to another card')->with(['updateExistingPivot', 'bulk update in bypass']);

    it('refuses an explicit organization other than the tenant\'s', function (): void {
        $newCard = $this->context->bypass(fn (): LoyaltyCard => LoyaltyCard::factory()->for($this->tenants->orgA)->create());
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        $newCard->businesses()->attach($this->tenants->a1, ['organization_id' => $this->tenants->orgB->id]);
    })->throws(LogicException::class, 'Cannot write CardBusiness for another organization.');

    it('lets the database refuse a mismatched organization in bypass()', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => $this->tenants->cardB->businesses()->attach($this->tenants->a1, ['organization_id' => $this->tenants->orgA->id]),
        )))->toThrow(QueryException::class);
    });

    it('removes participation with the card, the business or the organization', function (): void {
        $this->context->bypass(function (): void {
            $this->tenants->a1->delete();

            expect(CardBusiness::query()->where('card_id', $this->tenants->cardA->id)->pluck('business_id')->all())->toBe([$this->tenants->a2->id]);

            $this->tenants->cardA->delete();
            $this->tenants->orgB->delete();

            expect(CardBusiness::query()->count())->toBe(0)
                ->and(LoyaltyCard::query()->count())->toBe(0);
        });
    });

    it('does not let another organization\'s admin add a business to the card', function (): void {
        $this->context->set($this->tenants->orgB, orgAdmin: true);

        $this->tenants->cardA->businesses()->attach($this->tenants->b1);
    })->throws(LogicException::class);

    it('lets the database refuse a business of another organization, even in bypass()', function (): void {
        expect(fn () => DB::transaction(fn () => $this->context->bypass(
            fn () => $this->tenants->cardA->businesses()->attach($this->tenants->b1),
        )))->toThrow(QueryException::class);
    });

    it('lists a business once per card', function (): void {
        $this->context->set($this->tenants->orgA, orgAdmin: true);

        expect(fn () => DB::transaction(fn () => $this->tenants->cardA->businesses()->attach($this->tenants->a1)))
            ->toThrow(QueryException::class);
    });
});

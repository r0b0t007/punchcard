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

    it('needs a tenant or bypass() to create a card', function (): void {
        LoyaltyCard::query()->create(['organization_id' => $this->tenants->orgA->id, 'name' => 'x', 'stamps_required' => 5, 'reward_text' => 'x']);
    })->throws(LogicException::class);
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

    it('does not let a franchisee change which businesses honour the shared card', function (string $how): void {
        $this->context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        match ($how) {
            'detach a sibling' => $this->tenants->cardA->businesses()->detach([$this->tenants->a2->id]),
            'sync to itself' => $this->tenants->cardA->businesses()->sync([$this->tenants->a1->id]),
            'bulk delete' => CardBusiness::query()->delete(),
        };
    })->throws(LogicException::class, 'Only an org admin changes the card program.')
        ->with(['detach a sibling', 'sync to itself', 'bulk delete']);

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

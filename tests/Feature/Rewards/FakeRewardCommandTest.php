<?php

declare(strict_types=1);

use App\Enums\RewardStatus;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| punchcard:fake-reward (CHW-26)
|--------------------------------------------------------------------------
|
| Gives a customer an available reward at a stamper's café, for local work
| and the end-to-end redemption test. Never outside local and testing.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->customer = User::factory()->create(['email' => 'salma@example.test']);
    $this->rewards = fn (): array => $this->context->bypass(fn (): array => Reward::query()->ownedBy($this->customer)->orderBy('milestone')->get()->all());
});

it('gives the customer an available reward on the café\'s card, one more each time', function (): void {
    expect(Artisan::call('punchcard:fake-reward', ['email' => 'salma@example.test', 'stamper' => $this->stamper->id]))->toBe(0)
        ->and(Artisan::call('punchcard:fake-reward', ['email' => 'salma@example.test', 'stamper' => $this->stamper->id]))->toBe(0);

    [$first, $second] = ($this->rewards)();

    expect($first->status)->toBe(RewardStatus::Available)
        ->and($first->organization_id)->toBe($this->tenants->orgA->id)
        ->and([$first->milestone, $second->milestone])->toBe([1, 2]);
});

it('refuses an unknown customer or stamper', function (array $arguments): void {
    expect(Artisan::call('punchcard:fake-reward', $arguments))->toBe(1)
        ->and(($this->rewards)())->toBe([]);
})->with([
    'unknown email' => [['email' => 'nobody@example.test', 'stamper' => 1]],
    'unknown stamper' => [['email' => 'salma@example.test', 'stamper' => 999_999]],
]);

it('gives fake rewards only in local and testing', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    try {
        expect(Artisan::call('punchcard:fake-reward', ['email' => 'salma@example.test', 'stamper' => $this->stamper->id]))->toBe(1);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(($this->rewards)())->toBe([]);
})->with(['production', 'staging']);

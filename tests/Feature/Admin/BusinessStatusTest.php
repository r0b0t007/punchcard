<?php

declare(strict_types=1);

use App\Actions\Admin\BusinessStatusRefused;
use App\Actions\Admin\ReinstateBusiness;
use App\Actions\Admin\SuspendBusiness;
use App\Actions\Admin\VerifyBusiness;
use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ResolveTenant;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Verifying, suspending and reinstating a business (CHW-34, spec A1)
|--------------------------------------------------------------------------
|
| A business starts pending and works as such; the platform admin verifies
| it once reviewed, suspends it (taps, stamps, redemptions and its people's
| access stop) with a reason, and reinstates it. Each change is recorded in
| the audit log with it, in one transaction; only these Actions change it.
|
*/

beforeEach(function (): void {
    // The messages are translated; these tests read them in English, the key (the app's default is French).
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->admin = User::factory()->create(['email' => 'ops@example.test']);
    $this->actingAs($this->admin);
    $this->business = $this->tenants->a1;
    $this->status = fn (BusinessStatus $status) => $this->context->bypass(fn () => $this->business->forceFill(['status' => $status])->save());
    $this->fresh = fn (): Business => $this->context->bypass(fn (): Business => $this->business->refresh());
    $this->audits = fn (): array => $this->context->bypass(fn (): array => AuditLog::query()->orderBy('id')->get()
        ->map(fn (AuditLog $log): array => [$log->action, $log->subject_type, $log->subject_id, $log->actor_id, $log->actor_label, $log->reason])->all());
});

it('verifies a pending business and records who did', function (): void {
    ($this->status)(BusinessStatus::Pending);

    app(VerifyBusiness::class)->handle($this->business);

    expect(($this->fresh)()->status)->toBe(BusinessStatus::Verified)
        ->and(($this->fresh)()->verified_at)->not->toBeNull()
        ->and(($this->audits)())->toBe([['business.verified', 'business', $this->business->id, $this->admin->id, 'admin #'.$this->admin->id, null]]);
});

it('suspends a business with a reason, keeping its stampers assigned', function (string $from): void {
    ($this->status)(BusinessStatus::from($from));
    $stamper = $this->tenants->stamper($this->business);

    app(SuspendBusiness::class)->handle($this->business, '  Unpaid invoices since August  ');

    expect(($this->fresh)()->status)->toBe(BusinessStatus::Suspended)
        ->and(($this->fresh)()->suspended_at)->not->toBeNull()
        ->and($this->context->bypass(fn () => $stamper->refresh()->unassigned_at))->toBeNull()
        ->and(($this->audits)())->toBe([['business.suspended', 'business', $this->business->id, $this->admin->id, 'admin #'.$this->admin->id, 'Unpaid invoices since August']]);
})->with(['pending', 'verified']);

it('closes the counter and its people\'s access once suspended', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->business, BusinessRole::Owner);
    $location = $this->tenants->locationOf($this->business);

    app(SuspendBusiness::class)->handle($this->business, 'Fraud under review');

    $open = DB::transaction(fn (): bool => ArchivedSites::isOpen($this->business->id, $location->id, lock: true, counter: true));
    $operating = $this->context->bypass(fn (): bool => Business::query()->operating()->whereKey($this->business->id)->exists());
    app(ResolveTenant::class)->handle($owner, 'business:'.$this->business->id);

    expect($open)->toBeFalse()
        ->and($operating)->toBeFalse()
        ->and($this->context->businessId())->toBeNull();
});

it('reinstates a suspended business to where it was', function (bool $wasVerified, BusinessStatus $back): void {
    $this->context->bypass(fn () => $this->business->forceFill([
        'status' => BusinessStatus::Suspended,
        'verified_at' => $wasVerified ? now()->subMonth() : null,
        'suspended_at' => now()->subDay(),
    ])->save());

    app(ReinstateBusiness::class)->handle($this->business);

    expect(($this->fresh)()->status)->toBe($back)
        ->and(($this->fresh)()->suspended_at)->toBeNull()
        ->and(($this->audits)()[0][0])->toBe('business.reinstated');
})->with([
    'verified before' => [true, BusinessStatus::Verified],
    'never verified' => [false, BusinessStatus::Pending],
]);

it('refuses a change that does not apply, and records nothing', function (string $action, string $state, string $reason): void {
    match ($state) {
        'archived' => $this->context->bypass(fn () => app(ArchiveBusiness::class)->handle($this->business)),
        default => ($this->status)(BusinessStatus::from($state)),
    };

    $run = match ($action) {
        'verify' => fn () => app(VerifyBusiness::class)->handle($this->business),
        'suspend' => fn () => app(SuspendBusiness::class)->handle($this->business, 'A reason'),
        'suspend without a reason' => fn () => app(SuspendBusiness::class)->handle($this->business, '   '),
        'reinstate' => fn () => app(ReinstateBusiness::class)->handle($this->business),
    };

    expect($run)->toThrow(BusinessStatusRefused::class, $reason)
        ->and(($this->audits)())->toBe([]);
})->with([
    'verify a verified one' => ['verify', 'verified', 'not waiting for verification'],
    'verify a suspended one' => ['verify', 'suspended', 'not waiting for verification'],
    'verify an archived one' => ['verify', 'archived', 'archived'],
    'suspend a suspended one' => ['suspend', 'suspended', 'already suspended'],
    'suspend an archived one' => ['suspend', 'archived', 'archived'],
    'suspend without a reason' => ['suspend without a reason', 'verified', 'Give a reason'],
    'reinstate a verified one' => ['reinstate', 'verified', 'not suspended'],
    'reinstate a pending one' => ['reinstate', 'pending', 'not suspended'],
]);

it('lets no tenant change the verification or suspension dates', function (string $column): void {
    $this->context->set($this->tenants->orgA, $this->business, businessRole: BusinessRole::Owner);

    expect(fn () => $this->business->forceFill([$column => now()])->save())->toThrow(LogicException::class, 'verification');
})->with(['verified_at', 'suspended_at']);

it('locks the business\'s stampers, then the business, as a tap does', function (): void {
    $this->tenants->stamper($this->business);
    DB::enableQueryLog();

    app(SuspendBusiness::class)->handle($this->business, 'Fraud under review');

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    $stampers = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "stampers"') && str_contains($sql, 'for update'));
    $business = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "businesses"') && str_contains($sql, 'for update'));

    expect($stampers)->toBeInt()
        ->and($business)->toBeInt()
        ->and($stampers)->toBeLessThan($business);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

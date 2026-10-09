<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Admin\RecordAudit;
use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\RetireTag;
use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\StamperRefused;
use App\Enums\PlatformRole;
use App\Enums\StamperStatus;
use App\Models\AuditLog;
use App\Models\NfcTag;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The audit log (CHW-34)
|--------------------------------------------------------------------------
|
| What the platform admin did, to what, when and why: business verification
| and suspension, and the tag provisioning actions. Platform data, written
| in the action's own transaction, never changed or deleted.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->entries = fn (): array => $this->context->bypass(fn (): array => AuditLog::query()->orderBy('id')->get()
        ->map(fn (AuditLog $log): array => [$log->action, $log->subject_type, $log->actor_label])->all());
});

it('is never changed or deleted', function (string $how): void {
    $entry = DB::transaction(fn (): AuditLog => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));

    $change = match ($how) {
        'update' => fn () => $this->context->bypass(fn () => AuditLog::query()->whereKey($entry->id)->update(['action' => 'business.suspended'])),
        'delete' => fn () => $this->context->bypass(fn () => AuditLog::query()->whereKey($entry->id)->delete()),
    };

    // In its own transaction: on Postgres a failed statement aborts the one around it.
    expect(fn () => DB::transaction($change))->toThrow(QueryException::class, 'audit_logs_append_only')
        ->and(($this->entries)())->toHaveCount(1);
})->with(['update', 'delete']);

it('is never emptied', function (): void {
    DB::transaction(fn (): AuditLog => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));

    expect(fn () => DB::statement('truncate table audit_logs'))->toThrow(QueryException::class, 'audit_logs_append_only');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'SQLite has no TRUNCATE');

it('reads as empty outside bypass(), as platform data', function (): void {
    DB::transaction(fn () => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));
    $this->context->set($this->tenants->orgA, orgAdmin: true);

    expect(AuditLog::query()->count())->toBe(0)
        ->and($this->context->bypass(fn (): int => AuditLog::query()->count()))->toBe(1);
});

it('names the admin who acted by id, never by email, or the console', function (): void {
    $admin = User::factory()->create(['email' => 'ops@example.test']);
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    DB::transaction(fn () => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));
    $this->actingAs($admin);
    DB::transaction(fn () => app(RecordAudit::class)->handle('business.verified', $this->tenants->a2, 'Checked the papers', ['note' => 'fine']));

    $second = $this->context->bypass(fn (): AuditLog => AuditLog::query()->orderByDesc('id')->firstOrFail());

    expect(($this->entries)())->toBe([['business.verified', 'business', 'console'], ['business.verified', 'business', 'admin #'.$admin->id]])
        ->and($second->actor_id)->toBe($admin->id)
        ->and($second->reason)->toBe('Checked the papers')
        ->and($second->context)->toBe(['note' => 'fine']);
});

it('never labels someone admin who is not the platform admin', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    DB::transaction(fn () => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));

    expect(($this->entries)())->toBe([['business.verified', 'business', 'user #'.$user->id]]);
});

it('keeps an admin who acted, anonymised rather than deleted, so the log still points at them', function (): void {
    $admin = User::factory()->create();
    $this->actingAs($admin);
    DB::transaction(fn () => app(RecordAudit::class)->handle('business.verified', $this->tenants->a1));

    app(DeleteAccount::class)->handle($admin);

    expect(User::query()->find($admin->id)?->name)->toBe('Deleted user')
        ->and($this->context->bypass(fn (): ?int => AuditLog::query()->value('actor_id')))->toBe($admin->id);
});

it('records every tag provisioning action, and nothing for a refused one', function (): void {
    $uid = '04A1B2C3D4E5F6';
    app(RegisterStamper::class)->handle($uid, $this->tenants->a1);
    app(MoveStamper::class)->handle($uid, $this->tenants->a2);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Disabled);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Disabled);
    app(RecordRekey::class)->handle($uid, from: 1);
    app(SetStamperStatus::class)->handle($uid, StamperStatus::Active);
    app(RetireTag::class)->handle($uid);

    try {
        app(RetireTag::class)->handle($uid);
    } catch (StamperRefused) {
    }

    expect(array_column(($this->entries)(), 0))->toBe(['tag.registered', 'tag.moved', 'stamper.disabled', 'tag.rekeyed', 'stamper.enabled', 'tag.retired'])
        ->and(array_unique(array_column(($this->entries)(), 1)))->toBe(['nfc_tag']);

    $rekeyed = $this->context->bypass(fn (): AuditLog => AuditLog::query()->where('action', 'tag.rekeyed')->firstOrFail());
    $tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->where('uid', $uid)->firstOrFail());

    expect($rekeyed->subject_id)->toBe($tag->id)
        ->and($rekeyed->context)->toBe(['from' => 1, 'to' => 2]);
});

it('records a command as the console', function (): void {
    expect(Artisan::call('punchcard:stamper:register', ['uid' => '04A1B2C3D4E5F6', 'business' => $this->tenants->a1->slug]))->toBe(0)
        ->and(($this->entries)())->toBe([['tag.registered', 'nfc_tag', 'console']]);
});

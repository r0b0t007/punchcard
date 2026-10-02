<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantProbeJob;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Queued jobs carry the tenant (ADR 0006)
|--------------------------------------------------------------------------
|
| A job runs in the tenant of the code that dispatched it: the organization
| and, if set, the business. It carries no user rights and no bypass(): a job
| acts for no user, and code that spans tenants calls bypass() itself. Sync
| jobs get the same treatment, then the dispatching code gets its own
| context back. The worker cases use the database queue, so the payload is
| really serialized and the worker resets scoped services between jobs.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    TenantProbeJob::$seen = [];
});

describe('on a worker', function (): void {
    it('runs a job in the business it was dispatched from, without the user\'s rights', function (): void {
        app(TenantContext::class)->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);
        TenantProbeJob::dispatch()->onConnection('database');
        app(TenantContext::class)->clear();

        TenantProbeJob::workDatabaseQueue();

        expect(TenantProbeJob::$seen)->toBe([[
            'organization' => $this->tenants->orgA->id,
            'business' => $this->tenants->a1->id,
            'org_admin' => false,
            'role' => null,
            'bypassed' => false,
            'locations' => ['A1 site'],
        ]]);
    });

    it('runs a job dispatched by an org admin across the organization', function (): void {
        app(TenantContext::class)->set($this->tenants->orgA, orgAdmin: true);
        TenantProbeJob::dispatch()->onConnection('database');
        app(TenantContext::class)->clear();

        TenantProbeJob::workDatabaseQueue();

        expect(TenantProbeJob::$seen)->toBe([[
            'organization' => $this->tenants->orgA->id,
            'business' => null,
            'org_admin' => false,
            'role' => null,
            'bypassed' => false,
            'locations' => ['A1 site', 'A2 site'],
        ]]);
    });

    it('gives a job dispatched without a tenant no tenant, even right after another tenant\'s job', function (): void {
        app(TenantContext::class)->set($this->tenants->orgB, $this->tenants->b1);
        TenantProbeJob::dispatch()->onConnection('database');
        app(TenantContext::class)->clear();
        TenantProbeJob::dispatch()->onConnection('database');

        TenantProbeJob::workDatabaseQueue();

        expect(TenantProbeJob::$seen[0]['locations'])->toBe(['B1 site'])
            ->and(TenantProbeJob::$seen[1])->toBe([
                'organization' => null,
                'business' => null,
                'org_admin' => false,
                'role' => null,
                'bypassed' => false,
                'locations' => [],
            ]);
    });

    it('does not carry bypass() into the job', function (): void {
        app(TenantContext::class)->set($this->tenants->orgA, $this->tenants->a1);
        app(TenantContext::class)->bypass(fn () => TenantProbeJob::dispatch()->onConnection('database'));
        app(TenantContext::class)->clear();

        TenantProbeJob::workDatabaseQueue();

        expect(TenantProbeJob::$seen[0]['bypassed'])->toBeFalse()
            ->and(TenantProbeJob::$seen[0]['locations'])->toBe(['A1 site']);
    });

    it('fails a job whose business is gone instead of running it without a tenant', function (): void {
        app(TenantContext::class)->set($this->tenants->orgA, $this->tenants->a2);
        TenantProbeJob::dispatch()->onConnection('database');
        app(TenantContext::class)->clear();
        app(TenantContext::class)->bypass(fn () => $this->tenants->a2->delete());

        TenantProbeJob::workDatabaseQueue();

        expect(TenantProbeJob::$seen)->toBe([])
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

describe('on the sync queue', function (): void {
    it('runs the job in the tenant without rights, then gives the caller its context back', function (): void {
        $context = app(TenantContext::class);
        $context->set($this->tenants->orgA, $this->tenants->a1, orgAdmin: true, businessRole: BusinessRole::Owner);

        TenantProbeJob::dispatchSync();

        expect(TenantProbeJob::$seen[0])->toMatchArray(['business' => $this->tenants->a1->id, 'org_admin' => false, 'role' => null, 'locations' => ['A1 site']])
            ->and($context->isOrgAdmin())->toBeTrue()
            ->and($context->businessRole())->toBe(BusinessRole::Owner)
            ->and($context->businessId())->toBe($this->tenants->a1->id);
    });

    it('runs a job dispatched inside bypass() without it, and keeps the caller bypassed', function (): void {
        $context = app(TenantContext::class);
        $context->set($this->tenants->orgA, $this->tenants->a1);

        $stillBypassed = $context->bypass(function () use ($context): bool {
            TenantProbeJob::dispatchSync();

            return $context->isBypassed();
        });

        expect(TenantProbeJob::$seen[0]['bypassed'])->toBeFalse()
            ->and($stillBypassed)->toBeTrue()
            ->and($context->isBypassed())->toBeFalse();
    });

    it('gives the caller its context back when the job throws', function (): void {
        $context = app(TenantContext::class);
        $context->set($this->tenants->orgA, $this->tenants->a1, businessRole: BusinessRole::Owner);

        expect(fn () => TenantProbeJob::dispatchSync(throw: true))->toThrow(RuntimeException::class, 'Probe failed on purpose.')
            ->and($context->businessRole())->toBe(BusinessRole::Owner)
            ->and($context->businessId())->toBe($this->tenants->a1->id);
    });
});

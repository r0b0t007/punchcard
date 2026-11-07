<?php

declare(strict_types=1);

use App\Actions\Customers\BuildCustomerExport;
use App\Actions\Customers\DeleteExpiredExports;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Enums\StampSource;
use App\Http\Middleware\SetTenant;
use App\Jobs\ExportCustomers;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\User;
use App\Notifications\CustomerExportReadyMail;
use App\Support\Auth\MailedLink;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Exporting customers and stamps (CHW-49, B4, design 21)
|--------------------------------------------------------------------------
|
| A business's owner, and no one else, asks for its customers and stamps
| for some days; it is made on the queue and mailed as a link that works a
| day, for them alone. The files hold what B4 and B5 show (first name and
| initial, never an email), of this business only. Asking and downloading
| are both in the audit log.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    Storage::fake(BuildCustomerExport::DISK);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui', 'locale' => 'en']), $this->tenants->a1, BusinessRole::Owner);
    $this->as = fn (User $user, string $tenant) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => $tenant]);
    $this->ask = fn (array $dates = ['from' => '2026-10-01', 'to' => '2026-11-07'], ?User $user = null, ?string $tenant = null) => ($this->as)($user ?? $this->owner, $tenant ?? 'business:'.$this->tenants->a1->id)
        ->post(route('customers.export'), $dates);

    // A customer who came to A1 (and maybe A2), with their visits summarised there.
    $this->visitor = function (string $name, array $at = ['2026-10-15 10:00:00'], ?Business $business = null): CardEnrollment {
        $business ??= $this->tenants->a1;
        $enrollment = $this->tenants->enroll(User::factory()->create(['name' => $name]), $this->tenants->cardA);

        foreach ($at as $moment) {
            $this->tenants->stamp($enrollment, $business, ['created_at' => $moment]);
        }

        app(SummariseCustomer::class)->handle($business->id, $this->tenants->orgA->id, $enrollment->user_id);

        return $enrollment;
    };

    // The export's two files, decrypted and unzipped: rows of cells, headers first.
    $this->read = function (string $export): array {
        $zip = tempnam(sys_get_temp_dir(), 'pct');
        file_put_contents($zip, Crypt::decryptString((string) Storage::disk(BuildCustomerExport::DISK)->get(BuildCustomerExport::path($this->tenants->a1->id, $export))));
        $archive = new ZipArchive;
        $archive->open($zip);
        $files = [];

        foreach (['customers.csv', 'stamps.csv'] as $name) {
            $csv = (string) $archive->getFromName($name);
            expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue();
            $files[$name] = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
        }

        $archive->close();
        unlink($zip);

        return $files;
    };
    $this->build = fn (string $from = '2026-10-01', string $to = '2026-11-07', string $locale = 'en') => app(BuildCustomerExport::class)
        ->handle($this->tenants->a1->id, $from, $to, $locale, CarbonImmutable::now());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('queues the owner\'s export, records it, and says where the link goes', function (): void {
    Queue::fake();

    ($this->ask)()->assertRedirect(route('customers.index'))->assertSessionHas('customer-export', MailedLink::maskedEmail($this->owner->email));

    Queue::assertPushed(ExportCustomers::class, fn (ExportCustomers $job): bool => $job->businessId === $this->tenants->a1->id && $job->ownerId === $this->owner->id
        && $job->from === '2026-10-01' && $job->to === '2026-11-07');
    $entry = $this->context->bypass(fn () => AuditLog::query()->sole());
    expect($entry->action)->toBe('customers.export_requested')
        ->and($entry->actor_label)->toBe('user #'.$this->owner->id)
        ->and($entry->subject_id)->toBe($this->tenants->a1->id)
        ->and($entry->context)->toBe(['from' => '2026-10-01', 'to' => '2026-11-07']);

    ($this->as)($this->owner, 'business:'.$this->tenants->a1->id)->get(route('customers.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('canExport', true)->where('today', '2026-11-07'));
});

it('lets the owner alone export: never staff, nor the organization\'s admin', function (string $who): void {
    Queue::fake();
    [$user, $tenant] = match ($who) {
        'staff' => [$this->tenants->member(User::factory()->create(), $this->tenants->a1), 'business:'.$this->tenants->a1->id],
        'org admin in A1' => [$this->tenants->admin(User::factory()->create(), $this->tenants->orgA), 'business:'.$this->tenants->a1->id],
        'org admin' => [$this->tenants->admin(User::factory()->create(), $this->tenants->orgA), 'organization:'.$this->tenants->orgA->id],
    };

    $response = ($this->ask)(user: $user, tenant: $tenant);

    expect($response->status())->toBeIn([302, 403]);
    Queue::assertNothingPushed();
    expect($this->context->bypass(fn () => AuditLog::query()->count()))->toBe(0);
})->with(['staff', 'org admin in A1', 'org admin']);

it('takes days up to today, at most 3 years apart, in order', function (array $dates, string $field): void {
    Queue::fake();

    ($this->ask)($dates)->assertSessionHasErrors($field);
    Queue::assertNothingPushed();
})->with([
    'to before from' => [['from' => '2026-11-01', 'to' => '2026-10-01'], 'to'],
    'tomorrow' => [['from' => '2026-11-01', 'to' => '2026-11-08'], 'to'],
    'over 3 years' => [['from' => '2023-11-06', 'to' => '2026-11-07'], 'to'],
    'not a day' => [['from' => 'last week', 'to' => '2026-11-07'], 'from'],
]);

it('limits exports to a few an hour', function (): void {
    Queue::fake();

    foreach (range(1, 3) as $ignored) {
        ($this->ask)()->assertSessionHasNoErrors();
    }

    ($this->ask)()->assertSessionHasErrors('from');
    Queue::assertPushed(ExportCustomers::class, 3);
});

it('writes this business\'s customers and stamps for the days asked, as B4 and B5 show them', function (): void {
    $salma = ($this->visitor)('Salma Tazi', ['2026-10-15 10:00:00', '2026-10-20 18:30:00']);
    ($this->visitor)('Old Visitor', ['2026-08-01 10:00:00']);
    ($this->visitor)('Only A2', ['2026-10-16 10:00:00'], $this->tenants->a2);
    $staff = $this->tenants->member(User::factory()->create(['name' => 'Karim Bennani']), $this->tenants->a1);
    $this->tenants->stamp($salma, $this->tenants->a1, ['source' => StampSource::Manual, 'staff_id' => $staff->id, 'reason' => '=SUM(A1:A9)', 'created_at' => '2026-10-21 09:00:00', 'idempotency_key' => 'k1']);
    $this->tenants->stamp($salma, $this->tenants->a1, ['source' => StampSource::Correction, 'qty' => -1, 'staff_id' => $staff->id, 'reason' => 'Twice', 'created_at' => '2026-10-21 09:05:00', 'idempotency_key' => 'k2']);
    $this->context->bypass(fn () => Follow::query()->create(['organization_id' => $this->tenants->orgA->id, 'user_id' => $salma->user_id, 'notify' => true]));

    $made = ($this->build)();
    ['customers.csv' => $customers, 'stamps.csv' => $stamps] = ($this->read)($made['export']);

    expect($made['customers'])->toBe(1)->and($made['stamps'])->toBe(4)
        ->and($customers[0])->toBe(['Reference', 'Name', 'Visits', 'First visit', 'Last visit', 'Usual gap (days)', 'Status', 'Rewards redeemed', 'Follows for offers'])
        ->and(array_slice($customers[1], 1, 5))->toBe(['Salma T.', '2', '2026-10-15', '2026-10-20', '5'])
        ->and($customers[1][8])->toBe('Yes')
        ->and($stamps[0])->toBe(['Date', 'Time', 'Site', 'Card', 'Customer reference', 'How', 'Stamps', 'Reason', 'Stamped by'])
        ->and(array_column(array_slice($stamps, 1), 5))->toBe(['QR scan', 'QR scan', 'By hand', 'Correction'])
        ->and($stamps[3][7])->toBe("'=SUM(A1:A9)")
        ->and($stamps[3][8])->toBe('Karim B.')
        ->and($stamps[4][6])->toBe('-1')
        ->and(array_unique(array_column(array_slice($stamps, 1), 4)))->toBe([$customers[1][0]])
        ->and(implode(' ', array_merge(...$customers, ...$stamps)))->not->toContain('@')->not->toContain('Only A2')->not->toContain('Tazi');
});

it('counts the days in the business\'s own time, and writes the headers in the owner\'s language', function (): void {
    // UTC+4: 21:00 UTC on 31 October is already 1 November there.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    ($this->visitor)('Late Visitor', ['2026-10-31 21:00:00']);
    ($this->visitor)('Earlier Visitor', ['2026-10-31 19:00:00']);

    ['customers.csv' => $customers, 'stamps.csv' => $stamps] = ($this->read)(($this->build)('2026-11-01', '2026-11-07', 'fr')['export']);

    expect($customers)->toHaveCount(2)
        ->and($customers[0][0])->toBe('Référence')
        ->and($customers[1][1])->toBe('Late V.')
        ->and($stamps[1][0])->toBe('2026-11-01')
        ->and($stamps[1][1])->toBe('01:00');
});

it('keeps an anonymised customer\'s and staff member\'s stamps, without a name', function (): void {
    $gone = ($this->visitor)('Gone Customer');
    $this->context->bypass(fn () => User::query()->whereKey($gone->user_id)->update(['anonymised_at' => now(), 'name' => 'Gone Customer']));

    ['customers.csv' => $customers, 'stamps.csv' => $stamps] = ($this->read)(($this->build)()['export']);

    expect($customers[1][1])->toBe('')->and($stamps)->toHaveCount(2);
});

it('keeps the file encrypted, and mails the owner a link that works a day', function (): void {
    Notification::fake();
    ($this->visitor)('Salma Tazi');

    (new ExportCustomers($this->tenants->a1->id, $this->owner->id, '2026-10-01', '2026-11-07'))->handle(app(BuildCustomerExport::class));

    $files = Storage::disk(BuildCustomerExport::DISK)->allFiles();
    expect($files)->toHaveCount(1)
        ->and(str_starts_with((string) Storage::disk(BuildCustomerExport::DISK)->get($files[0]), 'PK'))->toBeFalse();
    Notification::assertSentTo($this->owner, CustomerExportReadyMail::class, function (CustomerExportReadyMail $mail): bool {
        parse_str((string) parse_url($mail->link($this->owner), PHP_URL_QUERY), $query);

        return $mail->customers === 1 && $mail->stamps === 1 && (int) $query['expires'] === now()->addDay()->getTimestamp()
            && (int) $query['for'] === $this->owner->id;
    });
});

describe('the download', function (): void {
    beforeEach(function (): void {
        ($this->visitor)('Salma Tazi');
        $this->export = ($this->build)()['export'];
        $this->mail = new CustomerExportReadyMail($this->tenants->a1->id, 'A1', $this->export, '2026-10-01', '2026-11-07', 1, 1);
        $this->path = fn (): string => parse_url($this->mail->link($this->owner), PHP_URL_PATH).'?'.parse_url($this->mail->link($this->owner), PHP_URL_QUERY);
    });

    it('gives the owner who asked their zip, and records it first', function (): void {
        $response = $this->actingAs($this->owner)->get(($this->path)());

        $response->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename="punchcard-export-2026-10-01-2026-11-07.zip"');
        expect(str_starts_with((string) $response->getContent(), 'PK'))->toBeTrue();
        $entry = $this->context->bypass(fn () => AuditLog::query()->sole());
        expect($entry->action)->toBe('customers.export_downloaded')->and($entry->context)->toBe(['export' => $this->export]);
    });

    it('is not for anyone else, nor an owner no longer', function (string $who): void {
        $user = $who === 'another account' ? User::factory()->create() : $this->owner;

        if ($who === 'no longer owner') {
            $this->context->bypass(fn () => BusinessMember::query()->where('user_id', $this->owner->id)->update(['role' => BusinessRole::Staff]));
        }

        $this->actingAs($user)->get(($this->path)())->assertForbidden()
            ->assertInertia(fn (Assert $page): Assert => $page->component('auth/export-unavailable')->where('reason', 'not-yours'));
        expect($this->context->bypass(fn () => AuditLog::query()->count()))->toBe(0);
    })->with(['another account', 'no longer owner']);

    it('says a link a day old, or a file gone, has expired', function (string $case): void {
        $path = ($this->path)();
        $case === 'a day later' ? $this->travel(25)->hours() : Storage::disk(BuildCustomerExport::DISK)->delete(BuildCustomerExport::path($this->tenants->a1->id, $this->export));

        $this->actingAs($this->owner)->get($path)->assertStatus(410)
            ->assertInertia(fn (Assert $page): Assert => $page->component('auth/export-unavailable')->where('reason', 'expired'));
    })->with(['a day later', 'the file gone']);

    it('refuses a link changed by hand, and asks to sign in first', function (): void {
        $this->actingAs($this->owner)->get(str_replace('to=2026-11-07', 'to=2026-11-06', ($this->path)()))->assertForbidden();
        auth()->logout();
        $this->get(($this->path)())->assertRedirectContains('/login');
    });
});

it('deletes exports a day old, hourly, and keeps newer ones', function (): void {
    $disk = Storage::disk(BuildCustomerExport::DISK);
    $disk->put('1/old.zip.enc', 'x');
    $disk->put('1/new.zip.enc', 'x');
    touch($disk->path('1/old.zip.enc'), now()->subHours(25)->getTimestamp());
    touch($disk->path('1/new.zip.enc'), now()->subHours(23)->getTimestamp());

    expect(app(DeleteExpiredExports::class)->handle(CarbonImmutable::now()))->toBe(['deleted' => 1])
        ->and(Storage::disk(BuildCustomerExport::DISK)->allFiles())->toBe(['1/new.zip.enc']);
});

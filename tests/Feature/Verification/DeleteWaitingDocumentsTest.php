<?php

declare(strict_types=1);

use App\Actions\Verification\DeleteExpiredDocuments;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Notifications\DocumentsDeletedUnreviewedMail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Deleting verification documents never reviewed (CHW-58, retention.md)
|--------------------------------------------------------------------------
|
| A document still waiting for the platform's decision 12 months after it
| was sent goes in the nightly sweep, file and row. The owners of a
| business that can still send (pending, operating) get one email per
| business and night, naming which ones went, with the way back to the
| verification page; a suspended or closed business gets none. Decided
| documents keep their own date (3 months after the decision).
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 04:00:00');
    app()->setLocale('en');
    Notification::fake();
    $this->disk = Storage::fake(BusinessDocument::DISK);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->business = $this->tenants->a1;
    $this->context->bypass(fn () => $this->business->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save());
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui', 'locale' => 'en']), $this->business, BusinessRole::Owner);

    $this->waiting = fn (string $sentAt, DocumentKind $kind = DocumentKind::IdDocument, array $attributes = []): BusinessDocument => $this->context->bypass(function () use ($sentAt, $kind, $attributes): BusinessDocument {
        $document = BusinessDocument::factory()->for($this->business)->create([
            'kind' => $kind,
            'path' => $this->business->id.'/'.str()->ulid()->toBase32().'.enc',
            'created_at' => $sentAt,
            ...$attributes,
        ]);
        $this->disk->put($document->path, 'encrypted bytes');
        touch($this->disk->path($document->path), Carbon::parse($sentAt)->getTimestamp());

        return $document;
    });
    $this->sweep = fn (): array => app(DeleteExpiredDocuments::class)->handle(now());
    $this->exists = fn (BusinessDocument $document): bool => $this->context->bypass(fn (): bool => BusinessDocument::query()->whereKey($document->id)->exists());
});

it('deletes documents still waiting 12 months after they were sent, file and row, and keeps newer ones', function (): void {
    $due = ($this->waiting)('2025-10-23 04:00:00');
    $newer = ($this->waiting)('2025-10-23 04:00:01', DocumentKind::ProofOfAddress);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->exists)($due))->toBeFalse()
        ->and(($this->exists)($newer))->toBeTrue();
    $this->disk->assertMissing($due->path);
    $this->disk->assertExists($newer->path);
});

it('never counts 12 months short on 29 February', function (): void {
    Carbon::setTestNow('2028-02-29 04:00:00');
    $due = ($this->waiting)('2027-02-28 04:00:00');
    $notYet = ($this->waiting)('2027-03-01 03:00:00', DocumentKind::ProofOfAddress);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->exists)($due))->toBeFalse()
        ->and(($this->exists)($notYet))->toBeTrue();
});

it('leaves a decided document to its own date, however old', function (): void {
    $rejected = ($this->waiting)('2025-06-01 10:00:00', attributes: [
        'status' => DocumentStatus::Rejected, 'decided_at' => '2026-10-01 10:00:00', 'deletes_at' => '2027-01-01 00:00:00',
    ]);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($rejected))->toBeTrue();
    Notification::assertNothingSent();
});

it('emails the owners once for the business, naming what went, never its staff', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->business);
    ($this->waiting)('2025-09-01 10:00:00');
    ($this->waiting)('2025-09-02 10:00:00', DocumentKind::ProofOfAddress);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 2, 'orphans' => 0]);
    Notification::assertSentToTimes($this->owner, DocumentsDeletedUnreviewedMail::class, 1);
    Notification::assertNotSentTo($staff, DocumentsDeletedUnreviewedMail::class);
    Notification::assertSentTo($this->owner, fn (DocumentsDeletedUnreviewedMail $mail): bool => $mail->businessName === 'A1'
        && $mail->kinds === [DocumentKind::IdDocument, DocumentKind::ProofOfAddress]);
});

it('emails each business\'s own owners only, never another site\'s, another organization\'s or the org admins', function (): void {
    $siteOwner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);
    $otherOwner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    $orgAdmin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $this->context->bypass(function (): void {
        foreach ([$this->tenants->a2, $this->tenants->b1] as $business) {
            $business->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save();
        }
        BusinessDocument::factory()->for($this->tenants->a2)->create(['kind' => DocumentKind::ProofOfAddress, 'created_at' => '2025-09-01 10:00:00']);
        BusinessDocument::factory()->for($this->tenants->b1)->create(['kind' => DocumentKind::IdDocument, 'created_at' => '2025-09-01 10:00:00']);
    });

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 2, 'orphans' => 0]);
    Notification::assertNotSentTo([$this->owner, $orgAdmin], DocumentsDeletedUnreviewedMail::class);
    Notification::assertSentToTimes($siteOwner, DocumentsDeletedUnreviewedMail::class, 1);
    Notification::assertSentTo($siteOwner, fn (DocumentsDeletedUnreviewedMail $mail): bool => $mail->businessName === 'A2' && $mail->kinds === [DocumentKind::ProofOfAddress]);
    Notification::assertSentToTimes($otherOwner, DocumentsDeletedUnreviewedMail::class, 1);
    Notification::assertSentTo($otherOwner, fn (DocumentsDeletedUnreviewedMail $mail): bool => $mail->businessName === 'B1' && $mail->kinds === [DocumentKind::IdDocument]);
});

it('leaves a file that won\'t go to the files with no row, in the same run, and says so by id', function (): void {
    Log::spy();
    $document = ($this->waiting)('2025-09-01 10:00:00');
    $disk = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
    $disk->shouldReceive('delete')->with([$document->path])->andThrow(new RuntimeException('Permission denied: '.$document->path));
    $disk->shouldReceive('delete')->passthru();
    Storage::set(BusinessDocument::DISK, $disk);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 1])
        ->and(($this->exists)($document))->toBeFalse();
    $this->disk->assertMissing($document->path);
    Notification::assertSentToTimes($this->owner, DocumentsDeletedUnreviewedMail::class, 1);
    Log::shouldHaveReceived('warning')->once()->with('The documents sweep left files to the next step.', ['business_id' => $this->business->id, 'error' => RuntimeException::class]);
});

it('still counts the documents when the email can\'t be queued, and says so by id', function (): void {
    Log::spy();
    $document = ($this->waiting)('2025-09-01 10:00:00');
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('The queue went away.'));

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->exists)($document))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->with('The documents sweep could not email a business.', ['business_id' => $this->business->id, 'error' => RuntimeException::class]);
});

it('deletes a suspended or closed business\'s documents without emailing it', function (array $closed): void {
    $this->context->bypass(fn () => $this->business->forceFill($closed)->save());
    $document = ($this->waiting)('2025-09-01 10:00:00');

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->exists)($document))->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'suspended' => [['status' => BusinessStatus::Suspended]],
    'archived' => [['archived_at' => '2026-10-01 10:00:00']],
]);

it('keeps a document the owner replaced while the sweep was on its way, and tells nobody', function (): void {
    $old = ($this->waiting)('2025-09-01 10:00:00');
    $replaced = new stdClass;
    $replaced->done = false;
    DB::listen(function (QueryExecuted $query) use ($old, $replaced): void {
        // The business's row is read (and locked, on Postgres) after the sweep listed it: SubmitDocument got there first.
        if (! $replaced->done && str_contains($query->sql, 'from "businesses"')) {
            $replaced->done = true;
            $this->context->bypass(fn () => $old->delete());
            $this->disk->delete($old->path);
            ($this->waiting)('2026-10-23 03:59:00');
        }
    });

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 0])
        ->and($replaced->done)->toBeTrue()
        ->and($this->context->bypass(fn (): int => BusinessDocument::query()->count()))->toBe(1);
    Notification::assertNothingSent();
});

it('goes on with the other businesses when one fails, logging its id only', function (): void {
    Log::spy();
    $failing = ($this->waiting)('2025-09-01 10:00:00');
    $other = $this->context->bypass(fn (): BusinessDocument => BusinessDocument::factory()->for($this->tenants->a2)->create(['created_at' => '2025-09-01 10:00:00']));
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'from "businesses"') && in_array($this->business->id, $query->bindings, true)) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->exists)($other))->toBeFalse()
        ->and(($this->exists)($failing))->toBeTrue();
    $this->disk->assertExists($failing->path);
    Log::shouldHaveReceived('warning', ['The documents sweep skipped a business.', ['business_id' => $this->business->id, 'error' => RuntimeException::class]])->once();
});

it('changes nothing and emails nobody when run again', function (): void {
    ($this->waiting)('2025-09-01 10:00:00');

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 1, 'orphans' => 0])
        ->and(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 0]);
    Notification::assertSentToTimes($this->owner, DocumentsDeletedUnreviewedMail::class, 1);
    expect($this->disk->allFiles())->toBe([]);
});

it('tells the owner which documents went and how to send them again', function (array $kinds, string $subject, string $line, string $action): void {
    $mail = (new DocumentsDeletedUnreviewedMail('Café Hafa', $kinds))->toMail($this->owner);

    expect($mail->subject)->toBe($subject)
        ->and($mail->greeting)->toBe('Hello Yasmine,')
        ->and($mail->introLines)->toBe([$line])
        ->and($mail->actionText)->toBe($action)
        ->and($mail->actionUrl)->toBe(route('verification.show'))
        ->and($mail->outroLines)->toBe([str_contains($action, 'them')
            ? 'Café Hafa keeps working meanwhile. Send them again whenever you want the business verified.'
            : 'Café Hafa keeps working meanwhile. Send it again whenever you want the business verified.']);
})->with([
    'the ID' => [[DocumentKind::IdDocument], 'We deleted the document Café Hafa sent for verification',
        'You sent us the owner\'s ID for Café Hafa more than 12 months ago, and we never finished checking it. We don\'t keep verification documents longer than that, so we deleted it.', 'Send it again'],
    'the proof of address' => [[DocumentKind::ProofOfAddress], 'We deleted the document Café Hafa sent for verification',
        'You sent us the proof of address of Café Hafa more than 12 months ago, and we never finished checking it. We don\'t keep verification documents longer than that, so we deleted it.', 'Send it again'],
    'both' => [[DocumentKind::IdDocument, DocumentKind::ProofOfAddress], 'We deleted the documents Café Hafa sent for verification',
        'You sent us the ID and the proof of address of Café Hafa more than 12 months ago, and we never finished checking them. We don\'t keep verification documents longer than that, so we deleted them.', 'Send them again'],
]);

it('writes the email in the owner\'s language', function (): void {
    app()->setLocale('ar');
    $mail = (new DocumentsDeletedUnreviewedMail('مقهى الحافة', [DocumentKind::ProofOfAddress]))->toMail($this->owner);

    expect($mail->subject)->toBe('حذفنا الوثيقة التي أرسلها مقهى الحافة للتوثيق')
        ->and($mail->actionText)->toBe('إعادة الإرسال')
        ->and((new DocumentsDeletedUnreviewedMail('مقهى الحافة', [DocumentKind::IdDocument, DocumentKind::ProofOfAddress]))->toMail($this->owner)->subject)
        ->toBe('حذفنا الوثيقتين اللتين أرسلهما مقهى الحافة للتوثيق');
});

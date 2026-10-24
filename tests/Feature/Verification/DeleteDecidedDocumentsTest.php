<?php

declare(strict_types=1);

use App\Actions\Verification\DeleteDecidedDocuments;
use App\Enums\DocumentStatus;
use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Deleting verification documents (CHW-145, docs/privacy/retention.md)
|--------------------------------------------------------------------------
|
| A document is kept 3 months after the platform decides (accepted or
| rejected), then the nightly job deletes its file and its row. One still
| waiting is never deleted. A file left with no row (an upload that died
| before its row, a replaced file that wouldn't go, a business deleted with
| its rows) goes once it is a day old. Runs with no tenant set, as the
| scheduler does, and logs counts and ids only.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 04:00:00');
    $this->disk = Storage::fake(BusinessDocument::DISK);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);

    $this->document = fn (array $attributes = []): BusinessDocument => $this->context->bypass(function () use ($attributes): BusinessDocument {
        $document = BusinessDocument::factory()->for($this->tenants->a1)->create([
            'path' => $this->tenants->a1->id.'/'.str()->ulid()->toBase32().'.enc',
            ...$attributes,
        ]);
        $this->disk->put($document->path, 'encrypted bytes');
        touch($this->disk->path($document->path), now()->subMonths(4)->getTimestamp());

        return $document;
    });
    $this->decided = fn (string $deletesAt, DocumentStatus $status = DocumentStatus::Accepted): BusinessDocument => ($this->document)([
        'status' => $status,
        'decided_at' => Carbon::parse($deletesAt)->subMonths(3),
        'deletes_at' => $deletesAt,
    ]);
    $this->orphan = function (string $path, int $hoursOld): string {
        $this->disk->put($path, 'encrypted bytes');
        touch($this->disk->path($path), now()->subHours($hoursOld)->getTimestamp());

        return $path;
    };
    $this->sweep = fn (): array => app(DeleteDecidedDocuments::class)->handle(now());
    $this->exists = fn (BusinessDocument $document): bool => $this->context->bypass(fn (): bool => BusinessDocument::query()->whereKey($document->id)->exists());
});

it('deletes accepted and rejected documents whose day has come, file and row', function (): void {
    $accepted = ($this->decided)('2026-10-23 03:59:59');
    $rejected = ($this->decided)('2026-10-23 04:00:00', DocumentStatus::Rejected);

    expect(($this->sweep)())->toBe(['documents' => 2, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($accepted))->toBeFalse()
        ->and(($this->exists)($rejected))->toBeFalse();
    $this->disk->assertMissing([$accepted->path, $rejected->path]);
});

it('deletes a document on the night of the day its page names, whatever the hour of the decision', function (): void {
    $lateDecision = ($this->document)(['status' => DocumentStatus::Accepted, 'decided_at' => '2026-07-23 23:30:00', 'deletes_at' => DeleteDecidedDocuments::deletesAt(Carbon::parse('2026-07-23 23:30:00'))]);
    $nextDay = ($this->document)(['status' => DocumentStatus::Accepted, 'decided_at' => '2026-07-24 00:30:00', 'deletes_at' => DeleteDecidedDocuments::deletesAt(Carbon::parse('2026-07-24 00:30:00'))]);

    expect(($this->sweep)())->toBe(['documents' => 1, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($lateDecision))->toBeFalse()
        ->and(($this->exists)($nextDay))->toBeTrue();
});

it('keeps documents still waiting, and decided ones before their day', function (): void {
    $waiting = ($this->document)();
    $notYet = ($this->decided)('2026-10-23 04:00:01');

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($waiting))->toBeTrue()
        ->and(($this->exists)($notYet))->toBeTrue();
    $this->disk->assertExists([$waiting->path, $notYet->path]);
});

it('deletes the row of a document whose file is already gone', function (): void {
    $document = ($this->decided)('2026-10-22 04:00:00');
    $this->disk->delete($document->path);

    expect(($this->sweep)())->toBe(['documents' => 1, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($document))->toBeFalse();
});

it('keeps the row while its file won\'t go, logs its id only, and goes on with the rest', function (): void {
    Log::spy();
    $stuck = ($this->decided)('2026-10-22 04:00:00');
    $next = ($this->decided)('2026-10-22 04:00:00');
    $disk = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
    $disk->shouldReceive('delete')->with($stuck->path)->andThrow(new RuntimeException('Permission denied: '.$stuck->path));
    $disk->shouldReceive('delete')->passthru();
    Storage::set(BusinessDocument::DISK, $disk);

    expect(($this->sweep)())->toBe(['documents' => 1, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($stuck))->toBeTrue()
        ->and(($this->exists)($next))->toBeFalse();
    Log::shouldHaveReceived('warning')->once()->with('The documents sweep skipped a document.', ['document_id' => $stuck->id, 'error' => RuntimeException::class]);
});

it('deletes files with no row once a day old, never one with a row, a newer one or one it never wrote', function (): void {
    $kept = ($this->document)();
    $notOurs = ($this->orphan)($this->tenants->a2->id.'/.gitignore', 48);
    $old = ($this->orphan)($this->tenants->a2->id.'/01OLD.enc', 25);
    $deleted = ($this->orphan)('999/01GONE.enc', 48);
    $uploading = ($this->orphan)($this->tenants->a1->id.'/01NEW.enc', 23);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 2]);
    $this->disk->assertMissing([$old, $deleted]);
    $this->disk->assertExists([$kept->path, $uploading, $notOurs]);
});

it('goes on with the other folders and files when one fails, logging its path only', function (): void {
    Log::spy();
    $stuck = ($this->orphan)('7/01STUCK.enc', 25);
    ($this->orphan)('8/01OLD.enc', 25);
    ($this->orphan)('9/01OLD.enc', 25);
    $disk = Mockery::mock(FilesystemAdapter::class, [$this->disk->getDriver(), $this->disk->getAdapter(), $this->disk->getConfig()])->makePartial();
    $disk->shouldReceive('files')->with('8')->andThrow(new RuntimeException('Permission denied: 8'));
    $disk->shouldReceive('files')->passthru();
    $disk->shouldReceive('delete')->with($stuck)->andThrow(new RuntimeException('Permission denied: '.$stuck));
    $disk->shouldReceive('delete')->passthru();
    Storage::set(BusinessDocument::DISK, $disk);

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 1]);
    $this->disk->assertMissing('9/01OLD.enc');
    Log::shouldHaveReceived('warning', ['The documents sweep skipped a folder.', ['directory' => '8', 'error' => RuntimeException::class]])->once();
    Log::shouldHaveReceived('warning', ['The documents sweep skipped a file.', ['path' => $stuck, 'error' => RuntimeException::class]])->once();
});

it('still deletes the files with no row when the documents query fails, and says so', function (): void {
    Log::spy();
    ($this->decided)('2026-10-22 04:00:00');
    $orphan = ($this->orphan)('7/01OLD.enc', 25);
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'deletes_at')) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 1]);
    $this->disk->assertMissing($orphan);
    Log::shouldHaveReceived('warning', ['The documents sweep stopped early.', ['step' => 'documents', 'error' => RuntimeException::class]])->once();
});

it('changes nothing when run again', function (): void {
    ($this->decided)('2026-10-22 04:00:00');
    ($this->orphan)('7/01OLD.enc', 25);
    $waiting = ($this->document)();

    expect(($this->sweep)())->toBe(['documents' => 1, 'waiting' => 0, 'orphans' => 1])
        ->and(($this->sweep)())->toBe(['documents' => 0, 'waiting' => 0, 'orphans' => 0])
        ->and(($this->exists)($waiting))->toBeTrue();
    expect($this->disk->allFiles())->toBe([$waiting->path]);
});

it('runs every night at 04:00, once, on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => $event->description === 'punchcard:documents-sweep');

    expect($event?->expression)->toBe('0 4 * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

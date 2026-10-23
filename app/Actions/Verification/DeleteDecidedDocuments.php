<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The verification documents' retention (CHW-145, docs/privacy/retention.md):
 * each document whose deletes_at has come (3 months after the platform
 * accepted or rejected it) loses its file, then its row; one still waiting
 * has no deletes_at and stays. A file that won't go keeps its row for the
 * next run, so no file is ever left without one by this job.
 *
 * Then the files no row points at, once a day old (newer ones may be an
 * upload whose row isn't committed yet): an upload that died before its
 * row, a replaced file that wouldn't go (SubmitDocument), a business
 * deleted with its rows. Only the `.enc` files SubmitDocument writes.
 *
 * Runs in bypass(), with no tenant. A document, file or folder that fails
 * is logged by id or path (a business id and a random name), never the
 * message, and the rest go on. Returns the counts.
 */
final readonly class DeleteDecidedDocuments
{
    public function __construct(private TenantContext $context) {}

    /**
     * When a document decided at $decidedAt goes: retention_months later, at the start of that day (UTC), so the 04:00
     * run deletes it on the day its page names, whatever the hour of the decision.
     */
    public static function deletesAt(CarbonInterface $decidedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($decidedAt)->utc()->addMonths((int) config('punchcard.documents.retention_months'))->startOfDay();
    }

    /**
     * @return array{documents: int, orphans: int}
     */
    public function handle(CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $disk = Storage::disk(BusinessDocument::DISK);

        return $this->context->bypass(fn (): array => [
            'documents' => $this->deleteDue($disk, $now),
            'orphans' => $this->deleteOrphans($disk, $now->subDay()),
        ]);
    }

    private function deleteDue(Filesystem $disk, CarbonImmutable $now): int
    {
        $gone = 0;

        try {
            foreach (BusinessDocument::query()->whereNotNull('deletes_at')->where('deletes_at', '<=', $now)->lazyById(100) as $document) {
                try {
                    // A file already gone deletes quietly; one that won't go throws (the disk's `throw`).
                    $disk->delete($document->path);
                    $document->delete();
                    $gone++;
                } catch (Throwable $failed) {
                    Log::warning('The documents sweep skipped a document.', ['document_id' => $document->id, 'error' => $failed::class]);
                }
            }
        } catch (Throwable $failed) {
            // The query itself: the files with no row still get their turn.
            Log::warning('The documents sweep stopped early.', ['step' => 'documents', 'error' => $failed::class]);
        }

        return $gone;
    }

    private function deleteOrphans(Filesystem $disk, CarbonImmutable $before): int
    {
        $gone = 0;

        try {
            $directories = $disk->directories();
        } catch (Throwable $failed) {
            Log::warning('The documents sweep stopped early.', ['step' => 'orphans', 'error' => $failed::class]);

            return 0;
        }

        foreach ($directories as $directory) {
            try {
                $gone += $this->deleteOrphansIn($disk, $directory, $before);
            } catch (Throwable $failed) {
                Log::warning('The documents sweep skipped a folder.', ['directory' => $directory, 'error' => $failed::class]);
            }
        }

        return $gone;
    }

    /**
     * One business's folder: its `.enc` files only, none nested deeper. An empty folder stays: removing it could race
     * an upload writing into it.
     */
    private function deleteOrphansIn(Filesystem $disk, string $directory, CarbonImmutable $before): int
    {
        $gone = 0;
        $files = array_values(array_filter($disk->files($directory), fn (string $path): bool => str_ends_with($path, '.enc')));

        if ($files === []) {
            return 0;
        }

        $known = BusinessDocument::query()->whereIn('path', $files)->pluck('path')->all();

        foreach (array_diff($files, $known) as $path) {
            try {
                if ($disk->lastModified($path) < $before->getTimestamp()) {
                    $disk->delete($path);
                    $gone++;
                }
            } catch (Throwable $failed) {
                Log::warning('The documents sweep skipped a file.', ['path' => $path, 'error' => $failed::class]);
            }
        }

        return $gone;
    }
}

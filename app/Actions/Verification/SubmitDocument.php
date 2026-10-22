<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A business sends a verification document (CHW-145): the owner's ID or a
 * proof of its address, while it waits to be verified. The file is
 * encrypted with the documents' own key (DocumentCipher, never APP_KEY)
 * and stored on the private `documents` disk
 * under a random name, never the uploaded one. It replaces the one of that
 * kind still waiting or rejected: its file goes once this commits, the new
 * one if this fails. A verified, suspended or closed business sends
 * nothing. Who may send is the caller's (BusinessPolicy::update); the
 * type and size are DocumentRequest's. Runs in bypass(): the model takes
 * writes from its Actions only.
 */
final readonly class SubmitDocument
{
    public function __construct(private TenantContext $context, private DocumentCipher $cipher) {}

    public function handle(Business $business, User $sender, DocumentKind $kind, UploadedFile $file): BusinessDocument
    {
        // A business that can't send is refused before any work on its file; the check under the lock below decides.
        if (! $this->maySend($business)) {
            throw new DocumentRefused(__('This business has nothing to send: it is verified, suspended or closed.'));
        }

        $disk = Storage::disk(BusinessDocument::DISK);
        $path = $business->id.'/'.Str::ulid()->toBase32().'.enc';
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        $keyId = $this->cipher->keyId();

        // The disk throws: a file that can't be written fails here, before any row.
        $disk->put($path, $this->cipher->encrypt((string) $file->get()));

        try {
            [$document, $old] = DB::transaction(fn (): array => $this->context->bypass(function () use ($business, $sender, $kind, $path, $keyId, $mime, $size): array {
                // As the status changes lock it (ChangesBusinessStatus): never blocking the inserts that point at it (taps).
                $operating = Business::query()->whereKey($business->id)->operating()->lock('for no key update')->first();

                if (! $operating instanceof Business || $operating->status !== BusinessStatus::Pending) {
                    throw new DocumentRefused(__('This business has nothing to send: it is verified, suspended or closed.'));
                }

                $replaced = BusinessDocument::query()->where('business_id', $business->id)->where('kind', $kind)
                    ->whereIn('status', [DocumentStatus::Submitted, DocumentStatus::Rejected])->get();
                $replaced->each(fn (BusinessDocument $document): ?bool => $document->delete());

                $document = (new BusinessDocument)->forceFill([
                    'business_id' => $business->id,
                    'kind' => $kind,
                    'path' => $path,
                    'key_id' => $keyId,
                    'mime' => $mime,
                    'size' => $size,
                    'uploaded_by' => $sender->id,
                ]);
                $document->save();

                return [$document, $replaced->pluck('path')->all()];
            }));
        } catch (Throwable $failed) {
            // The refusal is what the sender hears, never a failed cleanup.
            rescue(fn (): bool => $disk->delete($path));

            throw $failed;
        }

        if ($old !== []) {
            // The new document is saved either way: a file that won't go is reported, never a failed upload. At once
            // here; after the caller's own transaction when there is one (Part of it, then).
            DB::afterCommit(fn () => rescue(fn (): bool => $disk->delete($old)));
        }

        return $document;
    }

    /** Waiting to be verified, and operating (neither it nor its organization archived, not suspended). */
    public function maySend(Business $business): bool
    {
        return $this->context->bypass(fn (): bool => Business::query()->whereKey($business->id)->operating()->where('status', BusinessStatus::Pending)->exists());
    }
}

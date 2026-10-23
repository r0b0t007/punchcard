<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use App\Actions\Admin\RecordAudit;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\UnableToReadFile;

/**
 * The platform admin opens a verification document (CHW-145, A1): its file
 * decrypted, and an audit entry on its business ("document.viewed", which
 * document and kind) committed before a byte is shown. A file that won't
 * read is no view: nothing is recorded. Who may is the caller's
 * (BusinessDocumentPolicy::open). Read in bypass(): the admin is in no
 * tenant outside the panel.
 */
final readonly class ReadDocument
{
    /** How long a link from the business's page works. */
    public const int LINK_MINUTES = 5;

    public function __construct(private TenantContext $context, private DocumentCipher $cipher, private RecordAudit $recordAudit) {}

    /** A link to open it: signed, for LINK_MINUTES. Made when the page renders, so a new one on each visit. */
    public static function link(BusinessDocument $document): string
    {
        return URL::temporarySignedRoute('documents.view', now()->addMinutes(self::LINK_MINUTES), ['document' => $document->id]);
    }

    /**
     * @return array{document: BusinessDocument, bytes: string}
     */
    public function handle(int $documentId, User $admin): array
    {
        return $this->context->bypass(function () use ($documentId, $admin): array {
            $document = BusinessDocument::query()->with('business')->findOrFail($documentId);
            $disk = Storage::disk(BusinessDocument::DISK);

            try {
                $stored = (string) $disk->get($document->path);
            } catch (UnableToReadFile) {
                // Gone already (replaced, or the retention job): as if the row were.
                throw (new ModelNotFoundException)->setModel(BusinessDocument::class, [$documentId]);
            }

            $bytes = $this->cipher->decrypt($stored);

            DB::transaction(fn (): AuditLog => $this->recordAudit->handle('document.viewed', $document->business, context: [
                'document_id' => $document->id,
                'kind' => $document->kind->value,
            ], actor: $admin));

            return ['document' => $document, 'bytes' => $bytes];
        });
    }
}

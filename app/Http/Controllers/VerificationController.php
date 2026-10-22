<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Verification\DescribeVerification;
use App\Actions\Verification\DocumentRefused;
use App\Actions\Verification\SubmitDocument;
use App\Enums\DocumentKind;
use App\Http\Requests\Verification\DocumentRequest;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Verify your business" (CHW-145, design 9a), whoever runs it:
 *
 * - GET /business/verification: where it stands and each document's
 *   status (DescribeVerification), never the files.
 * - POST /business/verification/{kind} (throttled, verification-upload):
 *   a document sent (SubmitDocument), replacing the one waiting or
 *   rejected.
 */
final class VerificationController extends Controller
{
    public function show(TenantContext $context, DescribeVerification $describeVerification): Response
    {
        $business = $this->business($context);
        Gate::authorize('update', $business);

        return Inertia::render('business/verification', $describeVerification->handle($business));
    }

    public function store(DocumentRequest $request, DocumentKind $kind, SubmitDocument $submitDocument): RedirectResponse
    {
        /** @var Business $business */
        $business = $request->business();
        /** @var User $sender */
        $sender = $request->user();
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        try {
            $submitDocument->handle($business, $sender, $kind, $file);
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':document sent. We check it and let you know.', ['document' => $kind->label()])]);
        } catch (DocumentRefused $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refused->getMessage()]);
        }

        return to_route('verification.show');
    }

    /** The business worked in (TenantContext); none (an org admin across the organization) is a 403. */
    private function business(TenantContext $context): Business
    {
        return $context->business() ?? abort(403);
    }
}

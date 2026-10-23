<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Verification\ReadDocument;
use App\Models\BusinessDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A verification document for the platform admin (CHW-145): the signed
 * link from the business's page in A1 (routes: signed, never cached). An
 * image shows in the tab; anything else (a PDF) is saved and opened outside
 * the browser. Either way it is never sniffed, run or sent a referrer: the
 * bytes are someone's upload.
 */
final class DocumentController extends Controller
{
    /** Shown in the tab; every other type is saved. */
    private const array INLINE = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function show(Request $request, int $document, ReadDocument $readDocument): Response
    {
        // GET also answers HEAD: no bytes go out then, so nothing is opened or recorded.
        abort_if($request->isMethod('HEAD'), Response::HTTP_METHOD_NOT_ALLOWED);
        Gate::authorize('open', BusinessDocument::class);

        /** @var User $admin */
        $admin = $request->user();
        ['document' => $opened, 'bytes' => $bytes] = $readDocument->handle($document, $admin);

        $inline = self::INLINE[$opened->mime] ?? null;
        $name = $opened->kind->value.'-'.$opened->business_id.'.'.($inline ?? ($opened->mime === 'application/pdf' ? 'pdf' : 'bin'));

        return response($bytes, 200, [
            'Content-Type' => $inline === null && $opened->mime !== 'application/pdf' ? 'application/octet-stream' : $opened->mime,
            'Content-Disposition' => ($inline === null ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}

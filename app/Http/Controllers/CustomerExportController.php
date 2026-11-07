<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Customers\DownloadCustomerExport;
use App\Actions\Customers\RequestCustomerExport;
use App\Http\Requests\Customers\ExportCustomersRequest;
use App\Models\Business;
use App\Models\User;
use App\Support\Auth\MailedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exporting a business's customers and stamps (CHW-49, design 21):
 *
 * - POST /business/customers/export (its owner, throttled): made on the queue and emailed (RequestCustomerExport);
 *   back to B4, which says where the link goes.
 * - GET /business/exports/{business}/{export} (signed in, never cached): the mailed link, signed for a day, for the
 *   owner who asked alone, still an owner. Another account, or an owner no longer, is told it isn't theirs; an
 *   expired link or a file gone is told to ask again. The zip is never sniffed, run or sent a referrer.
 */
final class CustomerExportController extends Controller
{
    public function store(ExportCustomersRequest $request, RequestCustomerExport $requestCustomerExport): RedirectResponse
    {
        /** @var Business $business authorized by the request */
        $business = $request->business();
        /** @var User $owner */
        $owner = $request->user();

        $requestCustomerExport->handle($business, $owner, $request->from(), $request->to());

        return to_route('customers.index')->with('customer-export', MailedLink::maskedEmail($owner->email));
    }

    public function download(Request $request, int $business, string $export, DownloadCustomerExport $download): Response
    {
        abort_if($request->isMethod('HEAD'), Response::HTTP_METHOD_NOT_ALLOWED);
        abort_unless(URL::hasCorrectSignature($request, absolute: false), Response::HTTP_FORBIDDEN);

        /** @var User $user */
        $user = $request->user();

        if ($request->query->getInt('for') !== $user->id || ! $download->owns($user, $business)) {
            return $this->unavailable($request, 'not-yours', Response::HTTP_FORBIDDEN);
        }

        if (! URL::signatureHasNotExpired($request) || ! $download->exists($business, $export)) {
            return $this->unavailable($request, 'expired', Response::HTTP_GONE);
        }

        $name = 'punchcard-export-'.$request->query('from', '').'-'.$request->query('to', '').'.zip';

        return response($download->handle($user, $business, $export), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9.-]/', '', $name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function unavailable(Request $request, string $reason, int $status): Response
    {
        return Inertia::render('auth/export-unavailable', ['reason' => $reason])->toResponse($request)->setStatusCode($status);
    }
}

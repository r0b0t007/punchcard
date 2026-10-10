<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\MarkQrStandOpened;
use App\Models\Business;
use App\Support\Cards\JoinQrCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable QR stand (CHW-31): the current business's name, logo and a
 * QR code to its join page. BusinessPolicy::update in the business being
 * worked in: its owner's or an org admin's working in it, never staff's
 * (franchise HQ, working across the organization, has no business here).
 *
 * - GET /business/qr-stand: the stand. Viewing it changes nothing.
 * - POST /business/qr-stand/printed: the Print button, just before the
 *   print dialog: ticks the setup checklist (MarkQrStandOpened).
 */
final class QrStandController extends Controller
{
    public function show(TenantContext $context): Response
    {
        $business = $this->business($context);
        $joinUrl = route('join.show', $business->slug);

        return Inertia::render('business/qr-stand', [
            'businessName' => $business->name,
            'logoUrl' => $business->organization->logoUrl(),
            'joinUrl' => $joinUrl,
            'qrCode' => JoinQrCode::dataUrl($joinUrl),
        ]);
    }

    public function printed(TenantContext $context, MarkQrStandOpened $markQrStandOpened): RedirectResponse
    {
        $markQrStandOpened->handle($this->business($context));

        return to_route('business.qr-stand');
    }

    /** The business being worked in, if the user runs it (BusinessPolicy::update); 403 otherwise. */
    private function business(TenantContext $context): Business
    {
        $business = $context->businessId() === null ? null : Business::query()->with('organization')->find($context->businessId());

        if (! $business instanceof Business) {
            abort(403);
        }

        Gate::authorize('update', $business);

        return $business;
    }
}

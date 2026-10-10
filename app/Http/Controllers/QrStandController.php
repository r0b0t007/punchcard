<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\MarkQrStandOpened;
use App\Models\Business;
use App\Support\Cards\JoinQrCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable QR stand (CHW-31): the current business's name, logo and a
 * QR code to its join page. BusinessPolicy::update in the business being
 * worked in: its owner's, never staff's (franchise HQ, working across the
 * organization, has no business here). Opening it ticks the setup checklist
 * (MarkQrStandOpened).
 */
final class QrStandController extends Controller
{
    public function show(TenantContext $context, MarkQrStandOpened $markQrStandOpened): Response
    {
        $business = $context->businessId() === null ? null : Business::query()->with('organization')->find($context->businessId());

        if (! $business instanceof Business) {
            abort(403);
        }

        Gate::authorize('update', $business);
        $markQrStandOpened->handle($business);

        $joinUrl = route('join.show', $business->slug);
        $logo = $business->organization->logo_path;

        return Inertia::render('business/qr-stand', [
            'businessName' => $business->name,
            'logoUrl' => $logo === null ? null : Storage::disk('public')->url($logo),
            'joinUrl' => $joinUrl,
            'qrCode' => JoinQrCode::dataUrl($joinUrl),
        ]);
    }
}

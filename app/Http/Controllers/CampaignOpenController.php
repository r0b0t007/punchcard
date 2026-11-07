<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Campaigns\RecordCampaignOpen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A tap on a campaign's notification (CHW-48): its signed link, signed in
 * or not (the phone may have been signed out since). A good signature
 * counts the open (RecordCampaignOpen) and opens what the notification
 * opens; a link changed or made up counts nothing and opens My cards. The
 * signature leaves the host out, as the link is relative.
 */
final class CampaignOpenController extends Controller
{
    public function __invoke(Request $request, int $send, RecordCampaignOpen $recordCampaignOpen): RedirectResponse
    {
        if (! $request->hasValidSignature(absolute: false)) {
            return redirect()->to(route('app.cards', [], false));
        }

        return redirect()->to($recordCampaignOpen->handle($send));
    }
}

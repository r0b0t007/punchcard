<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Push\DeletePushSubscription;
use App\Actions\Push\SavePushSubscription;
use App\Http\Requests\PushSubscriptionRequest;
use App\Http\Requests\UnsubscribePhoneRequest;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * A phone's notifications, on or off (CHW-41), from lib/push.ts once the
 * browser has subscribed or unsubscribed: no page, just 204.
 */
final class PushSubscriptionController extends Controller
{
    public function store(PushSubscriptionRequest $request, SavePushSubscription $savePushSubscription): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $savePushSubscription->handle($user, $request->endpoint(), $request->publicKey(), $request->authToken(), $request->contentEncoding());

        return response()->noContent();
    }

    /** This phone hears nothing more: the user's own row for it, if any. */
    public function destroy(UnsubscribePhoneRequest $request, DeletePushSubscription $deletePushSubscription): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $deletePushSubscription->handle($user, $request->endpoint());

        return response()->noContent();
    }
}

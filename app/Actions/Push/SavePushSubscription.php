<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A phone's notifications turned on (CHW-41): its push subscription, one row
 * per device (the endpoint). The same phone again updates its keys; a phone
 * another account had is theirs no more, it is whoever turned it on now
 * (HasPushSubscriptions). The user is locked: an account being deleted
 * (DeleteAccount locks it too) never gets a phone back.
 */
final readonly class SavePushSubscription
{
    public function handle(User $user, string $endpoint, string $publicKey, string $authToken, string $contentEncoding): void
    {
        DB::transaction(function () use ($user, $endpoint, $publicKey, $authToken, $contentEncoding): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $locked instanceof User || $locked->isAnonymised()) {
                return;
            }

            $locked->updatePushSubscription($endpoint, $publicKey, $authToken, $contentEncoding);
        });
    }
}

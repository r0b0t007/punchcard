<?php

declare(strict_types=1);

namespace App\Actions\Push;

use App\Models\User;

/**
 * A phone's notifications turned off (CHW-41): the user's own row for its
 * endpoint, if any. Another account's phone is never theirs to remove.
 */
final readonly class DeletePushSubscription
{
    public function handle(User $user, string $endpoint): void
    {
        $user->deletePushSubscription($endpoint);
    }
}

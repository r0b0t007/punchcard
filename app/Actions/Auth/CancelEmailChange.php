<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Drops a new address still waiting to be confirmed (CHW-23,
 * RequestEmailChange), from settings: its link stops working, and the
 * account keeps its address.
 */
final readonly class CancelEmailChange
{
    public function handle(User $user): void
    {
        $user->forceFill(['pending_email' => null, 'pending_email_expires_at' => null])->save();
    }
}

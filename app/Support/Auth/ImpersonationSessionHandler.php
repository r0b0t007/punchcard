<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session handler, naming in the session row the person using
 * it: the platform admin while signed in as an owner (CHW-144), never the
 * owner. So the owner's "Download my data" never lists the admin's IP and
 * browser, and the owner's "sign out other devices" never touches it. The
 * admin's own do: a new password or a deleted account ends the session with
 * their others, unrecorded, which the start entry's ends_at bounds.
 */
final class ImpersonationSessionHandler extends DatabaseSessionHandler
{
    protected function userId(): mixed
    {
        $impersonation = $this->container?->bound('session.store') === true ? Impersonation::of($this->container->make('session.store')) : null;

        return $impersonation instanceof Impersonation ? $impersonation->adminId : parent::userId();
    }
}

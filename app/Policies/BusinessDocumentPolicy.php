<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Verification documents (CHW-145): only the platform admin opens one, through
 * its signed link (DocumentController), outside the panel and its
 * Gate::before. Nobody in the business app does, its owners included: they
 * sent the file and are never shown it back. Sending is BusinessPolicy::update.
 */
final class BusinessDocumentPolicy
{
    public function open(User $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('admin'));
    }
}

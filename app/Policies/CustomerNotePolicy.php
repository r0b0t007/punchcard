<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerNote;
use App\Models\User;
use App\Policies\Concerns\ReadsTenant;

/** Notes on a business's customers (CHW-44, B5): whoever runs the business, never its staff. */
final class CustomerNotePolicy
{
    use ReadsTenant;

    public function delete(User $user, CustomerNote $note): bool
    {
        return $this->runs($note->organization_id, $note->business_id);
    }
}

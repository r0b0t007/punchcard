<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerNote;

/** A note on a customer deleted by someone who runs their business (CHW-44, B5), in its tenant. */
final readonly class DeleteNote
{
    public function handle(CustomerNote $note): void
    {
        $note->delete();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\BusinessCustomer;
use App\Models\CustomerNote;
use App\Models\User;

/** A note on a customer, by someone who runs their business (CHW-44, B5), in its tenant. */
final readonly class WriteNote
{
    public function handle(BusinessCustomer $customer, User $author, string $body): CustomerNote
    {
        return CustomerNote::query()->create([
            'business_id' => $customer->business_id,
            'user_id' => $customer->user_id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }
}

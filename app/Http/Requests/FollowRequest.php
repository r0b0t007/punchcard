<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/** "Follow for offers" on one of the customer's cards (CHW-27, C6): yes or no. */
class FollowRequest extends MyCardRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['follow' => ['required', 'boolean']];
    }
}

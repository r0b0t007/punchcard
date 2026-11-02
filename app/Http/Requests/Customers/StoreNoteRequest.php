<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\CustomerNote;
use Illuminate\Contracts\Validation\ValidationRule;

/** A note on one customer (CHW-44, B5), by whoever runs the business: up to CustomerNote::MAX_LENGTH characters. */
class StoreNoteRequest extends CustomerRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Words, not only invisible characters.
            'body' => ['required', 'string', 'max:'.CustomerNote::MAX_LENGTH, 'not_regex:/^[\p{C}\s]*$/u'],
        ];
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}

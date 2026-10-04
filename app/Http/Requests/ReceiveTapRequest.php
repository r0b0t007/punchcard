<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A tap URL, GET /t?e=…&c=… (CHW-25). Nothing is refused here: a missing or
 * malformed SUN message is recorded by ReceiveTap as a malformed tap (the tap
 * log is the fraud signal), so anything that isn't text reads as empty
 * instead of failing validation or raising a type error.
 */
class ReceiveTapRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /** The encrypted SUN message (e) or its MAC (c), as text. */
    public function sun(string $key): string
    {
        $value = $this->query($key);

        return is_string($value) ? $value : '';
    }
}

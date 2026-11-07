<?php

declare(strict_types=1);

namespace App\Http\Requests\Counter;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Stamps for a scanned customer (CHW-30, S2): 1 to 10, as the stepper
 * offers, and the device's idempotency key for this press, so a retry over a
 * slow network gives the same stamp.
 */
class StampScanRequest extends FormRequest
{
    public const int MAX_QTY = 10;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'between:1,'.self::MAX_QTY],
            // A UUID, as a stamp by hand's (StampCustomerRequest): never one of the keys only the system writes
            // (a referral's, a birthday's, CHW-47), which share the business's keys.
            'key' => ['required', 'uuid'],
        ];
    }

    public function qty(): int
    {
        return $this->integer('qty');
    }

    public function key(): string
    {
        return $this->string('key')->toString();
    }
}

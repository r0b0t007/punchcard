<?php

declare(strict_types=1);

namespace App\Http\Requests\Counter;

use App\Actions\Counter\ArmStamper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Next tap gives" (CHW-29, S1): one of the quantities counter mode offers. */
class ArmStamperRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', Rule::in(ArmStamper::QUANTITIES)],
        ];
    }

    public function qty(): int
    {
        return $this->integer('qty');
    }
}

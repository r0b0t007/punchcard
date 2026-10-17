<?php

declare(strict_types=1);

namespace App\Http\Requests\Stampers;

use App\Enums\StamperStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Switching a stamper off or on (B12, CHW-36). */
class ChangeStamperStatusRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StamperStatus::class)],
        ];
    }

    public function status(): StamperStatus
    {
        return StamperStatus::from($this->string('status')->toString());
    }
}

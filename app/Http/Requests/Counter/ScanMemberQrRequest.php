<?php

declare(strict_types=1);

namespace App\Http\Requests\Counter;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** A member QR read by the camera, or its short code typed (CHW-30), at one of the scanner's sites. */
class ScanMemberQrRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'location' => ['required', 'integer'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function location(): int
    {
        return $this->integer('location');
    }
}

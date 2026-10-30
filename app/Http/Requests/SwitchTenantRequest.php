<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\SetTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A choice from the tenant switcher (CHW-147): "business:7" or "org:5",
 * as SetTenant keeps it. Whether the user may work there is SelectTenant's.
 */
final class SwitchTenantRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['choice' => ['required', 'string', 'regex:'.SetTenant::CHOICE]];
    }

    public function choice(): string
    {
        return $this->string('choice')->toString();
    }
}

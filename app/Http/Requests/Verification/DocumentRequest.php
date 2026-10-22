<?php

declare(strict_types=1);

namespace App\Http\Requests\Verification;

use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A verification document (CHW-145): whoever runs the business worked in
 * (BusinessPolicy::update) sends a photo or a PDF, 10 MB at most. The type
 * is read from the file's content, never its name; nothing that can carry
 * a script (SVG, HTML) gets through.
 */
final class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && ($this->user()?->can('update', $business) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240'],
        ];
    }

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }
}

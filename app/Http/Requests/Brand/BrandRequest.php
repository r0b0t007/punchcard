<?php

declare(strict_types=1);

namespace App\Http\Requests\Brand;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The brand page (CHW-135, B17): OrganizationPolicy::manageBrand on the
 * organization worked for, so its org admin at network level or the owner of
 * an independent café's or chain's only business; never a franchisee or
 * staff. The page and removing the cover take no input.
 */
class BrandRequest extends FormRequest
{
    private ?Organization $organization = null;

    public function authorize(): bool
    {
        $organization = $this->organization();

        return $organization instanceof Organization && $this->user()?->can('manageBrand', $organization) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /** The organization worked for, read once. */
    public function organization(): ?Organization
    {
        return $this->organization ??= app(TenantContext::class)->organization();
    }
}

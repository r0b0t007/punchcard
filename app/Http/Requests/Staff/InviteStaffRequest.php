<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Actions\Account\DeleteAccount;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An invitation to the current business's staff (CHW-146), from its owner:
 * an address not on the staff yet, and optionally one open location of this
 * business (never another's).
 */
class InviteStaffRequest extends FormRequest
{
    private ?Business $business = null;

    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('create', [BusinessMember::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                'not_regex:/@'.preg_quote(DeleteAccount::RESERVED_EMAIL_DOMAIN, '/').'$/i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->business()?->members()->where('users.email', $value)->exists() === true) {
                        $fail(__('Already on your staff.'));
                    }
                },
            ],
            'location_id' => [
                'bail', 'nullable', 'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $ofThisBusiness = Location::query()->whereKey($value)->where('business_id', $this->business()?->id)->whereNull('archived_at')->exists();

                    if (! $ofThisBusiness) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
        ];
    }

    /** The business worked in (TenantContext), which the invitation is for. */
    public function business(): ?Business
    {
        $businessId = app(TenantContext::class)->businessId();

        return $this->business ??= $businessId === null ? null : Business::query()->find($businessId);
    }

    public function locationId(): ?int
    {
        return $this->filled('location_id') ? $this->integer('location_id') : null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}

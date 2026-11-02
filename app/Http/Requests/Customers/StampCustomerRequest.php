<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Actions\Counter\ScanLocations;
use App\Actions\Stamps\StampRequest;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Stamps added by hand for one customer, or taken back (CHW-44, B5), by
 * whoever runs the business: how many (1 to MAX_QTY), on which of their
 * cards the business sees, at which of its open sites, why (shown on the
 * history, with their name), and the dialog's idempotency key, so a double
 * press is one stamp.
 */
class StampCustomerRequest extends CustomerRequest
{
    public const int MAX_QTY = 50;

    private ?CardEnrollment $enrollment = null;

    private ?Location $location = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'between:1,'.self::MAX_QTY],
            'enrollment' => [
                'bail', 'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->enrollment() instanceof CardEnrollment) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
            'location' => [
                'bail', 'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->location() instanceof Location) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
            // Words, not only invisible characters StampRequest would trim away.
            'reason' => ['required', 'string', 'max:255', 'not_regex:/^[\p{C}\s]*$/u'],
            // The dialog's crypto.randomUUID(): 36 bytes, within StampRequest's 64.
            'key' => ['required', 'uuid'],
        ];
    }

    /** Taking stamps back, rather than adding them. */
    public function takesBack(): bool
    {
        return $this->routeIs('customers.corrections.store');
    }

    /** One of the customer's cards the business sees (a card stamped only at a sister franchisee is not stamped from here). */
    public function enrollment(): ?CardEnrollment
    {
        return $this->enrollment ??= CardEnrollment::query()
            ->where('user_id', $this->customer()->user_id)
            ->whereKey($this->integer('enrollment'))
            ->first();
    }

    /**
     * Where: for stamps added, one of the business's open sites, or the owner's own when they are limited to one;
     * for stamps taken back, any of its sites, an archived one too, so the ledger stays fixable (as AddStamps allows).
     */
    public function location(): ?Location
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            return null;
        }

        return $this->location ??= $this->takesBack()
            ? Location::query()->where('business_id', $business->id)->whereKey($this->integer('location'))->first()
            : app(ScanLocations::class)->find($business, app(TenantContext::class)->locationId(), $this->integer('location'));
    }

    public function stampRequest(): StampRequest
    {
        /** @var Location $location validated */
        $location = $this->location();
        /** @var User $owner */
        $owner = $this->user();
        $reason = $this->string('reason')->toString();
        $key = $this->string('key')->toString();

        return $this->takesBack()
            ? StampRequest::correction($location, $owner, $key, $reason, -$this->integer('qty'))
            : StampRequest::manual($location, $owner, $key, $reason, $this->integer('qty'));
    }
}

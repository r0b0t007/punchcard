<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Http\Middleware\SetTenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Works in one business by its id (CHW-295, a business's emails): the
 * tenant switcher's choice "business:{id}", honoured as ResolveTenant
 * honours it, only while the user works there and the business operates.
 * True when the TenantContext is now that business; otherwise the context
 * is left as it was, and the caller keeps the choice it had.
 */
final readonly class SelectBusiness
{
    public function __construct(private ResolveTenant $resolveTenant, private TenantContext $context) {}

    public function handle(User $user, int $businessId): bool
    {
        $before = $this->context->snapshot();
        $this->resolveTenant->handle($user, SetTenant::businessChoice($businessId));

        if ($this->context->businessId() === $businessId) {
            return true;
        }

        $this->context->restore($before);

        return false;
    }
}

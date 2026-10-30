<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Http\Middleware\SetTenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Works in one business, or across a network its user runs, by its choice
 * ("business:7", "org:5"; SetTenant): the tenant switcher (CHW-147) and a
 * business's email link (CHW-295). Honoured as ResolveTenant honours it,
 * only while the user works there and it operates, and only if the
 * switcher offers it (DescribeTenants::choices). True when the
 * TenantContext is now exactly that (a network: no business in it);
 * otherwise the context is left as it was, and the caller keeps the choice
 * it had.
 */
final readonly class SelectTenant
{
    public function __construct(private ResolveTenant $resolveTenant, private DescribeTenants $describeTenants, private TenantContext $context) {}

    public function handle(User $user, string $choice): bool
    {
        [$type, $id] = SetTenant::parseChoice($choice);

        // Only what the switcher offers (DescribeTenants): never a state the app can't show.
        if ($type === null || ! in_array($choice, $this->describeTenants->choices($user), true)) {
            return false;
        }

        $before = $this->context->snapshot();
        $this->resolveTenant->handle($user, $choice);

        $chosen = $type === 'business'
            ? $this->context->businessId() === $id
            : $this->context->organizationId() === $id && $this->context->businessId() === null;

        if ($chosen) {
            return true;
        }

        $this->context->restore($before);

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Whether the user owns a business that is set up and still operating
 * (CHW-31): such an owner keeps their dashboard while starting another one.
 * Read across tenants.
 */
final readonly class OwnsSetUpBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): bool
    {
        return $this->context->bypass(fn (): bool => Business::query()
            ->whereIn('id', BusinessMember::query()->where('user_id', $user->id)->where('role', BusinessRole::Owner)->select('business_id'))
            ->whereNotNull('onboarded_at')
            ->operating()
            ->exists());
    }
}

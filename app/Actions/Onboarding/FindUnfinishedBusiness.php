<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * The business the user is setting up (CHW-31): the newest one they own that
 * still operates and has not finished the onboarding wizard. Read across
 * tenants: it decides which tenant the wizard works in.
 */
final readonly class FindUnfinishedBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user): ?Business
    {
        return $this->context->bypass(fn (): ?Business => Business::query()
            ->whereIn('id', BusinessMember::query()->where('user_id', $user->id)->where('role', BusinessRole::Owner)->select('business_id'))
            ->whereNull('onboarded_at')
            ->operating()
            ->with('organization')
            ->latest('id')
            ->first());
    }
}

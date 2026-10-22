<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\BusinessMember;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The accounts the inactivity rule may act on (CHW-58,
 * docs/privacy/retention.md): customers whose account was last used before
 * $before (when it signed up, if nothing since). Never an anonymised account,
 * nor anyone with a role: an owner or staff member of any business, an org
 * admin, archived ones included (their account follows their membership), or
 * a platform admin.
 *
 * Built in bypass() only: outside it the membership tables read as empty,
 * and staff would pass for customers.
 */
final readonly class InactiveCustomers
{
    public function __construct(private TenantContext $context) {}

    /** @return Builder<User> */
    public function handle(CarbonInterface $before): Builder
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('The inactivity rule reads memberships across tenants: build it in bypass().');
        }

        return User::query()->notAnonymised()
            ->where(fn (Builder $query): Builder => $query->where('last_active_at', '<', $before)
                ->orWhere(fn (Builder $never): Builder => $never->whereNull('last_active_at')->where('created_at', '<', $before)))
            ->whereNotIn('id', BusinessMember::query()->select('user_id'))
            ->whereNotIn('id', OrganizationMember::query()->select('user_id'))
            ->whereDoesntHave('roles');
    }
}

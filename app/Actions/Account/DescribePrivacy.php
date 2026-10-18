<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * The Privacy settings page (CHW-58, C12, review 7a):
 *
 * - punchcard's news, on since or off since a moment (shown in the device's
 *   timezone: a customer has no site), or never chosen, and whether the
 *   email must be confirmed before turning it on;
 * - every program the customer holds a card in, the most recently used
 *   first, one row each: following its offers or not, since when (the
 *   follow's last change), and whether it can still be followed (a closed
 *   program can only be left). The same Follow as on the card's page (C6).
 *
 * Programs are read in bypass(), kept to the customer's own cards (as
 * ListMyCards does). A fixed number of queries, however many cards.
 */
final readonly class DescribePrivacy
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     marketing: array{on: bool, since: string|null, mustVerify: bool},
     *     programs: list<array{enrollmentId: int, organization: string, logoUrl: string|null, following: bool, changedAt: string|null, canFollow: bool}>,
     * }
     */
    public function handle(User $user): array
    {
        $on = $user->marketing_opt_in_at !== null;

        return [
            'marketing' => [
                'on' => $on,
                'since' => ($on ? $user->marketing_opt_in_at : $user->marketing_opt_out_at)?->toIso8601String(),
                'mustVerify' => ! $user->hasVerifiedEmail(),
            ],
            'programs' => $this->programs($user),
        ];
    }

    /**
     * @return list<array{enrollmentId: int, organization: string, logoUrl: string|null, following: bool, changedAt: string|null, canFollow: bool}>
     */
    private function programs(User $user): array
    {
        return $this->context->bypass(function () use ($user): array {
            // One card per program: the most recently used (lastUsedFirst) carries its row.
            $enrollments = CardEnrollment::query()->where('user_id', $user->id)->lastUsedFirst()->get(['id', 'organization_id'])
                ->unique('organization_id');
            $organizations = Organization::query()->whereKey($enrollments->pluck('organization_id')->all())
                ->get(['id', 'name', 'logo_path', 'archived_at'])->keyBy('id');
            $follows = Follow::query()->where('user_id', $user->id)->get(['organization_id', 'notify', 'updated_at'])->keyBy('organization_id');

            $programs = [];

            foreach ($enrollments as $enrollment) {
                $organization = $organizations->get($enrollment->organization_id);

                if (! $organization instanceof Organization) {
                    continue;
                }

                $follow = $follows->get($organization->id);
                $programs[] = [
                    'enrollmentId' => $enrollment->id,
                    'organization' => $organization->name,
                    'logoUrl' => $organization->logoUrl(),
                    'following' => $follow?->notify === true,
                    'changedAt' => $follow?->updated_at?->toIso8601String(),
                    'canFollow' => $organization->archived_at === null,
                ];
            }

            return $programs;
        });
    }
}

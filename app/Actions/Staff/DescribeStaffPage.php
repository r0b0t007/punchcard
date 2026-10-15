<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Location;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff page of a business (CHW-146, B13 for the pilot), as its owner
 * sees it: who works there (owners first; staff may be removed, owners are
 * handed over elsewhere, CHW-140), the open locations to invite to, and the
 * invitations still
 * waiting, each with its date in its location's timezone (the business's
 * first location's for the whole business). An invitation to a location
 * archived since is left out: its link no longer works (FindStaffInvitation).
 */
final readonly class DescribeStaffPage
{
    /**
     * @return array{
     *     members: array<int, array{id: int, name: string, email: string, role: string, roleLabel: string, location: string|null, removable: bool}>,
     *     locations: array<int, array{id: int, name: string}>,
     *     invitations: array<int, array{id: int, email: string, location: string|null, expiresAt: string, timezone: string}>,
     * }
     */
    public function handle(Business $business): array
    {
        $locations = Location::query()->where('business_id', $business->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'timezone']);
        $businessTimezone = $locations->sortBy('id')->first()->timezone ?? (string) config('app.timezone');

        $invitations = StaffInvitation::query()
            ->where('business_id', $business->id)
            ->pending()
            ->where(fn (Builder $query) => $query->whereNull('location_id')->orWhereIn('location_id', $locations->pluck('id')))
            ->with('location:id,name,timezone')
            ->latest('id')
            ->get();

        $memberships = BusinessMember::query()->where('business_id', $business->id)->get();
        $users = User::query()->whereIn('id', $memberships->pluck('user_id'))->get()->keyBy('id');
        $memberLocations = Location::query()->whereIn('id', $memberships->pluck('location_id')->filter())->pluck('name', 'id');

        return [
            'members' => $memberships
                ->filter(fn (BusinessMember $membership): bool => $users->has($membership->user_id))
                ->sortBy(fn (BusinessMember $membership): string => ($membership->role === BusinessRole::Owner ? '0' : '1').$users->get($membership->user_id)?->name)
                ->map(function (BusinessMember $membership) use ($users, $memberLocations): array {
                    /** @var User $user */
                    $user = $users->get($membership->user_id);

                    return [
                        'id' => $membership->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $membership->role->value,
                        'roleLabel' => $membership->role->getLabel(),
                        'location' => $membership->location_id === null ? null : $memberLocations->get($membership->location_id),
                        'removable' => $membership->role === BusinessRole::Staff,
                    ];
                })
                ->values()
                ->all(),
            'locations' => $locations->map(fn (Location $location): array => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'invitations' => $invitations->map(fn (StaffInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'location' => $invitation->location?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'timezone' => $invitation->location->timezone ?? $businessTimezone,
            ])->values()->all(),
        ];
    }
}

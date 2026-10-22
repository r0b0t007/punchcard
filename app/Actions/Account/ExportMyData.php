<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\ConsentEvent;
use App\Models\CounterScan;
use App\Models\Follow;
use App\Models\Location;
use App\Models\LoginLink;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Reward;
use App\Models\StaffInvitation;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Download my data (CHW-58, C12): everything punchcard keeps about the
 * customer, as one JSON document (format_version 1, UTC ISO 8601 dates).
 *
 * - the account and its consents (the current state and every change);
 * - each card, in every organization, with every stamp (where, how many,
 *   how, by whom: you, staff or punchcard, and the staff's reason) and
 *   every reward;
 * - follows, taps for as long as they are kept (punchcard.taps.retention_days),
 *   member QR scans, sign-in links and staff invitations sent to the
 *   email, passkeys, sessions, and roles at businesses and organizations.
 *
 * Never another person: staff are "staff", never named; a referrer is a
 * yes or no. Never a secret: no password, 2FA secret, passkey credential,
 * tap counter, tag, claim token or session id or payload.
 *
 * In bypass(): a customer has no tenant, so every read is kept to the
 * customer, their enrollments or their own rewards (Reward::ownedBy).
 */
final readonly class ExportMyData
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, CarbonImmutable $now): array
    {
        return $this->context->bypass(function () use ($user, $now): array {
            $enrollments = CardEnrollment::query()->where('user_id', $user->id)->orderBy('id')->get();
            $enrollmentIds = $enrollments->modelKeys();
            $stamps = StampEvent::query()->whereIn('enrollment_id', $enrollmentIds)->orderBy('id')
                ->get(['enrollment_id', 'business_id', 'location_id', 'source', 'qty', 'reason', 'created_at'])->groupBy('enrollment_id');
            $rewards = Reward::query()->whereIn('enrollment_id', $enrollmentIds)->orderBy('id')->get()->groupBy('enrollment_id');
            $follows = Follow::query()->where('user_id', $user->id)->orderBy('id')->get(['organization_id', 'notify', 'created_at', 'updated_at']);
            $consents = ConsentEvent::query()->where('user_id', $user->id)->orderBy('id')->get();
            $taps = Tap::query()->where('user_id', $user->id)->where('created_at', '>=', $now->subDays((int) config('punchcard.taps.retention_days')))->orderBy('id')
                ->get(['business_id', 'location_id', 'status', 'ip', 'user_agent', 'created_at']);
            $scans = CounterScan::query()->where('user_id', $user->id)->orderBy('id')->get(['business_id', 'location_id', 'scanned_at', 'stamp_event_id']);
            $invitations = StaffInvitation::query()->where('email', $user->email)->orderBy('id')->get(['business_id', 'created_at', 'accepted_at']);
            $businessRoles = BusinessMember::query()->where('user_id', $user->id)->orderBy('id')->get(['organization_id', 'business_id', 'role', 'created_at']);
            $organizationRoles = OrganizationMember::query()->where('user_id', $user->id)->orderBy('id')->get(['organization_id', 'role', 'created_at']);

            $cards = LoyaltyCard::query()->whereKey($enrollments->pluck('card_id')->unique()->all())->pluck('name', 'id');
            $organizations = Organization::query()->whereKey(collect([$enrollments, $follows, $consents, $businessRoles, $organizationRoles])
                ->flatMap(fn (Collection $rows): array => $rows->pluck('organization_id')->all())->filter()->unique()->all())->pluck('name', 'id');
            $businesses = Business::query()->whereKey(collect([$stamps->flatten(), $taps, $scans, $invitations, $businessRoles])
                ->flatMap(fn (Collection $rows): array => $rows->pluck('business_id')->all())
                ->merge($rewards->flatten()->pluck('redeemed_business_id'))->filter()->unique()->all())->pluck('name', 'id');
            $locations = Location::query()->whereKey(collect([$stamps->flatten(), $taps, $scans])
                ->flatMap(fn (Collection $rows): array => $rows->pluck('location_id')->all())
                ->merge($rewards->flatten()->pluck('redeemed_location_id'))->filter()->unique()->all())->pluck('name', 'id');

            return [
                'format_version' => 1,
                'generated_at' => $now->toIso8601String(),
                'account' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $this->date($user->email_verified_at),
                    'pending_email' => $user->pending_email,
                    'locale' => $user->locale,
                    'created_at' => $this->date($user->created_at),
                    'last_active_at' => $this->date($user->last_active_at),
                    'inactivity_warned_at' => $this->date($user->inactivity_warned_at),
                    'inactivity_deletes_at' => $this->date($user->inactivity_deletes_at),
                    'adult_confirmed_at' => $this->date($user->adult_confirmed_at),
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                ],
                'consents' => [
                    'punchcard_news' => [
                        'on' => $user->marketing_opt_in_at !== null,
                        'on_since' => $this->date($user->marketing_opt_in_at),
                        'off_since' => $this->date($user->marketing_opt_out_at),
                    ],
                    'log' => $consents->map(fn (ConsentEvent $event): array => [
                        'kind' => $event->kind,
                        'organization' => $event->organization_id === null ? null : $organizations->get($event->organization_id),
                        'granted' => $event->granted,
                        'source' => $event->source,
                        'at' => $this->date($event->created_at),
                    ])->all(),
                ],
                'cards' => $enrollments->map(fn (CardEnrollment $enrollment): array => [
                    'organization' => $organizations->get($enrollment->organization_id),
                    'card' => $cards->get($enrollment->card_id),
                    'joined_at' => $this->date($enrollment->created_at),
                    'current_stamps' => $enrollment->current_stamps,
                    'lifetime_stamps' => $enrollment->lifetime_stamps,
                    'completed' => $enrollment->completed_count,
                    'last_stamp_at' => $this->date($enrollment->last_stamp_at),
                    'referral_code' => $enrollment->referral_code,
                    'joined_through_referral' => $enrollment->referred_by !== null,
                    'stamps' => $stamps->get($enrollment->id, collect())->map(fn (StampEvent $stamp): array => [
                        'at' => $this->date($stamp->created_at),
                        'business' => $businesses->get($stamp->business_id),
                        'location' => $locations->get($stamp->location_id),
                        'qty' => $stamp->qty,
                        'source' => $stamp->source->value,
                        'by' => $this->givenBy($stamp->source),
                        'reason' => $stamp->reason,
                    ])->values()->all(),
                    'rewards' => $rewards->get($enrollment->id, collect())->map(fn (Reward $reward): array => [
                        'type' => $reward->reward_type->value,
                        'value' => $reward->reward_value,
                        'text' => $reward->reward_text,
                        'milestone' => $reward->milestone,
                        'status' => $reward->status->value,
                        'unlocked_at' => $this->date($reward->unlocked_at),
                        'expires_at' => $this->date($reward->expires_at),
                        'redeemed_at' => $this->date($reward->redeemed_at),
                        'redeemed_business' => $reward->redeemed_business_id === null ? null : $businesses->get($reward->redeemed_business_id),
                        'redeemed_location' => $reward->redeemed_location_id === null ? null : $locations->get($reward->redeemed_location_id),
                    ])->values()->all(),
                ])->values()->all(),
                'follows' => $follows->map(fn (Follow $follow): array => [
                    'organization' => $organizations->get($follow->organization_id),
                    'following' => $follow->notify,
                    'since' => $this->date($follow->created_at),
                    'changed_at' => $this->date($follow->updated_at),
                ])->all(),
                'taps' => $taps->map(fn (Tap $tap): array => [
                    'at' => $this->date($tap->created_at),
                    'business' => $tap->business_id === null ? null : $businesses->get($tap->business_id),
                    'location' => $tap->location_id === null ? null : $locations->get($tap->location_id),
                    'status' => $tap->status->value,
                    'ip' => $tap->ip,
                    'user_agent' => $tap->user_agent,
                ])->all(),
                'member_qr_scans' => $scans->map(fn (CounterScan $scan): array => [
                    'at' => $this->date($scan->scanned_at),
                    'business' => $businesses->get($scan->business_id),
                    'location' => $locations->get($scan->location_id),
                    'gave_stamp' => $scan->stamp_event_id !== null,
                ])->all(),
                'sign_in_links' => LoginLink::query()->where('email', $user->email)->orderBy('id')->get(['purpose', 'created_at', 'used_at'])
                    ->map(fn (LoginLink $link): array => [
                        'purpose' => $link->purpose,
                        'sent_at' => $this->date($link->created_at),
                        'used_at' => $this->date($link->used_at),
                    ])->all(),
                // Never who sent it: only the business.
                'staff_invitations' => $invitations->map(fn (StaffInvitation $invitation): array => [
                    'business' => $businesses->get($invitation->business_id),
                    'sent_at' => $this->date($invitation->created_at),
                    'accepted_at' => $this->date($invitation->accepted_at),
                ])->all(),
                // Not tenant tables: plain reads, kept to the user.
                'passkeys' => DB::table('passkeys')->where('user_id', $user->id)->orderBy('id')->get(['name', 'created_at', 'last_used_at'])
                    ->map(fn (object $passkey): array => [
                        'name' => $passkey->name,
                        'created_at' => $this->date($passkey->created_at),
                        'last_used_at' => $this->date($passkey->last_used_at),
                    ])->all(),
                'sessions' => DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['ip_address', 'user_agent', 'last_activity'])
                    ->map(fn (object $session): array => [
                        'ip' => $session->ip_address,
                        'user_agent' => $session->user_agent,
                        'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity)->toIso8601String(),
                    ])->all(),
                'memberships' => [
                    ...$businessRoles->map(fn (BusinessMember $member): array => [
                        'organization' => $organizations->get($member->organization_id),
                        'business' => $businesses->get($member->business_id),
                        'role' => $member->role->value,
                        'since' => $this->date($member->created_at),
                    ])->all(),
                    ...$organizationRoles->map(fn (OrganizationMember $member): array => [
                        'organization' => $organizations->get($member->organization_id),
                        'business' => null,
                        'role' => $member->role->value,
                        'since' => $this->date($member->created_at),
                    ])->all(),
                ],
            ];
        });
    }

    /** Who gave a stamp: the customer's own tap, a staff member (never named), or punchcard's rules. */
    private function givenBy(StampSource $source): string
    {
        return match ($source) {
            StampSource::Nfc => 'you',
            StampSource::Qr, StampSource::Manual, StampSource::Correction => 'staff',
            StampSource::Bonus, StampSource::Birthday, StampSource::Referral => 'punchcard',
        };
    }

    /** A stored moment as UTC ISO 8601; a raw column (passkeys) is read as UTC. */
    private function date(DateTimeInterface|string|null $moment): ?string
    {
        return $moment === null ? null : CarbonImmutable::parse($moment, 'UTC')->utc()->toIso8601String();
    }
}

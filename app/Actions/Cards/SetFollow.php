<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Follow for offers" on a card's page (CHW-27, C6): the customer's consent
 * to hear about the offers of the program the card belongs to. Off until
 * they turn it on; turning it off keeps the row with notify false, so the
 * consent's last change stays dated. In bypass(): only the customer sets
 * their own follow (Follow), on a card they hold (MyCardRequest).
 *
 * - Consent needs a verified email: whoever gives it owns the inbox the
 *   offers go to. Turning it off never does.
 * - A closed program (archived organization) takes no new follow. Archiving
 *   writes the organization's row: read locked, the check and the follow
 *   never interleave with it.
 * - The user is locked first: one customer's requests run one at a time,
 *   and an account being deleted (DeleteAccount locks it too) never gets
 *   a follow back.
 */
final readonly class SetFollow
{
    public function __construct(private TenantContext $context) {}

    public function handle(CardEnrollment $enrollment, bool $follow): void
    {
        $this->context->bypass(fn () => DB::transaction(function () use ($enrollment, $follow): void {
            $user = User::query()->whereKey($enrollment->user_id)->lockForUpdate()->first();

            if (! $user instanceof User || $user->isAnonymised()) {
                return;
            }

            $keys = ['user_id' => $enrollment->user_id, 'organization_id' => $enrollment->organization_id];

            if (! $follow) {
                Follow::query()->where($keys)->first()?->forceFill(['notify' => false])->save();

                return;
            }

            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['follow' => __('Verify your email address to follow offers.')]);
            }

            $organization = Organization::query()->whereKey($enrollment->organization_id)->lockForUpdate()->first(['id', 'archived_at']);

            if (! $organization instanceof Organization || $organization->archived_at !== null) {
                throw ValidationException::withMessages(['follow' => __('This café has closed: there are no offers to follow.')]);
            }

            Follow::query()->updateOrCreate($keys, ['notify' => true]);
        }));
    }
}

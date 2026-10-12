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
 * - A closed program (archived organization) takes no new follow: the
 *   form says so. Turning one off always works.
 * - Two requests turning it on at once keep one row (createOrFirst).
 * - The user is locked first, so an account being deleted (DeleteAccount,
 *   which locks it too) never gets a follow back.
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

            if (Organization::query()->whereKey($enrollment->organization_id)->whereNotNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['follow' => __('This café has closed: there are no offers to follow.')]);
            }

            $existing = Follow::query()->createOrFirst($keys, ['notify' => true]);

            if (! $existing->wasRecentlyCreated && ! $existing->notify) {
                $existing->forceFill(['notify' => true])->save();
            }
        }));
    }
}

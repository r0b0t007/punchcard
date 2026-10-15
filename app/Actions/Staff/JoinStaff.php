<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Auth\SetPassword;
use App\Actions\Fortify\CreateNewUser;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Joins a business's staff from an invitation's link (CHW-146), all or
 * nothing: someone new gets their account first (the invited address), an
 * account without a password
 * chooses one (staff never sign in by email link), then the account joins
 * (AcceptStaffInvitation). Should the invitation stop working meanwhile
 * (used in another tab, replaced, the site closed), none of it stays. The
 * account that joined, or null. The caller signs a new account in.
 */
final readonly class JoinStaff
{
    public function __construct(
        private CreateNewUser $createNewUser,
        private SetPassword $setPassword,
        private AcceptStaffInvitation $acceptStaffInvitation,
    ) {}

    /**
     * @param  string  $state  DescribeStaffInvitation::JOIN, CHOOSE_PASSWORD or SIGN_UP
     * @param  array<string, string>  $input  name, password and its confirmation, adult and marketing, as asked
     */
    public function handle(StaffInvitation $invitation, string $state, ?User $user, array $input, string $sessionId): ?User
    {
        DB::beginTransaction();

        try {
            if ($state === DescribeStaffInvitation::SIGN_UP) {
                // Confirmed as it joins (AcceptStaffInvitation): the link came to its inbox.
                $user = $this->createNewUser->create([...$input, 'email' => $invitation->email]);
            }

            if (! $user instanceof User) {
                DB::rollBack();

                return null;
            }

            if ($state === DescribeStaffInvitation::CHOOSE_PASSWORD) {
                $this->setPassword->handle($user, $input['password'] ?? '', $sessionId);
            }

            if (! $this->acceptStaffInvitation->handle($invitation->id, $user)) {
                DB::rollBack();

                return null;
            }

            DB::commit();

            return $user;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}

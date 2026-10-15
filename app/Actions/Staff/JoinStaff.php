<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Auth\SetPassword;
use App\Actions\Fortify\CreateNewUser;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Joins a business's staff from an invitation's link (CHW-146), all or
 * nothing: someone new gets their account first (the invited address), then
 * the account joins (AcceptStaffInvitation, which confirms the address), and
 * only then does an account without a password choose one (staff never sign
 * in by email link): the password's own effects (signing other devices out,
 * a new remember cookie) only follow a join that happened. Should the
 * invitation stop working meanwhile (used in another tab, replaced, the site
 * closed), none of it stays. The account that joined, or null. The caller
 * signs a new account in.
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
        try {
            return DB::transaction(function () use ($invitation, $state, $user, $input, $sessionId): User {
                if ($state === DescribeStaffInvitation::SIGN_UP) {
                    $user = $this->createNewUser->create([...$input, 'email' => $invitation->email]);
                }

                if (! $user instanceof User || ! $this->acceptStaffInvitation->handle($invitation->id, $user)) {
                    throw new InvitationGone;
                }

                if ($state === DescribeStaffInvitation::CHOOSE_PASSWORD) {
                    $this->setPassword->handle($user, $input['password'] ?? '', $sessionId);
                }

                return $user;
            });
        } catch (InvitationGone) {
            return null;
        }
    }
}

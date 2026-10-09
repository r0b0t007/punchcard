<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Business sign-up (CHW-31, B1 for the pilot): the owner's account, created
 * as any account is (CreateNewUser validates it), with an independent
 * organization and a pending business they own. Both or neither: the
 * onboarding wizard then sets the business up.
 */
final readonly class SignUpBusinessOwner
{
    public function __construct(
        private CreateNewUser $createNewUser,
        private CreateIndependentBusiness $createIndependentBusiness,
    ) {}

    /**
     * @param  array<string, string>  $account  name, email, password and its confirmation
     */
    public function handle(array $account, string $businessName): User
    {
        return DB::transaction(function () use ($account, $businessName): User {
            $owner = $this->createNewUser->create($account);
            $this->createIndependentBusiness->handle($owner, $businessName);

            return $owner;
        });
    }
}

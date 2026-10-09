<?php

namespace App\Actions\Fortify;

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreateIndependentBusiness $createIndependentBusiness) {}

    /**
     * Validate and create a newly registered user. With a business name (the
     * business sign-up, CHW-31) they also get an independent business they
     * own, created with the account or not at all; the onboarding wizard sets
     * it up.
     *
     * @param  array<string, string|null>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'business_name' => ['nullable', 'string', 'max:120'],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $businessName = trim((string) ($input['business_name'] ?? ''));

            if ($businessName !== '') {
                $this->createIndependentBusiness->handle($user, $businessName);
            }

            return $user;
        });
    }
}

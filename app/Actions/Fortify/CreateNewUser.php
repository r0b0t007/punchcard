<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Auth\RecordConsent;
use App\Concerns\ConsentRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use ConsentRules, PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly RecordConsent $recordConsent) {}

    /**
     * Validate and create a newly registered user, with their consent dated (CHW-23).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            ...$this->consentRules(),
        ], $this->consentMessages())->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        return $this->recordConsent->handle($user, filter_var($input['marketing'] ?? false, FILTER_VALIDATE_BOOLEAN));
    }
}

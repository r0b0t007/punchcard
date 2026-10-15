<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Actions\Staff\DescribeStaffInvitation;
use App\Actions\Staff\FindStaffInvitation;
use App\Concerns\ConsentRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Joining a business's staff from an invitation's link (CHW-146). What it
 * asks depends on who opened it (DescribeStaffInvitation): someone new gives
 * a name, a password and their consent, as any sign-up; an account without a
 * password chooses one; anyone else, nothing. The link is checked here once,
 * and the controller reads the same invitation.
 */
class AcceptStaffInvitationRequest extends FormRequest
{
    use ConsentRules, PasswordValidationRules, ProfileValidationRules;

    private ?StaffInvitation $invitation = null;

    private bool $looked = false;

    private ?string $state = null;

    private bool $described = false;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Opening the page (GET) asks nothing yet.
        if ($this->isMethod('GET')) {
            return [];
        }

        return match ($this->state()) {
            DescribeStaffInvitation::SIGN_UP => [
                'name' => $this->nameRules(),
                'password' => $this->passwordRules(),
                ...$this->consentRules(),
            ],
            DescribeStaffInvitation::CHOOSE_PASSWORD => ['password' => $this->passwordRules()],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->consentMessages();
    }

    /** The invitation, if its link is signed and it can still be used (FindStaffInvitation). */
    public function invitation(): ?StaffInvitation
    {
        if (! $this->looked) {
            $this->looked = true;
            $this->invitation = $this->hasValidSignature(absolute: false)
                ? app(FindStaffInvitation::class)->handle((int) $this->route('invitation'))
                : null;
        }

        return $this->invitation;
    }

    /** What the invitation asks of whoever opened it, worked out once (rules and the controller agree); null without a usable one. */
    public function state(): ?string
    {
        if (! $this->described) {
            $this->described = true;
            $invitation = $this->invitation();
            $user = $this->user();
            $this->state = $invitation instanceof StaffInvitation
                ? app(DescribeStaffInvitation::class)->handle($invitation, $user instanceof User ? $user : null)
                : null;
        }

        return $this->state;
    }
}

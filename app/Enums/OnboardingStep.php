<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The onboarding wizard's steps, in order (CHW-31, spec B2). A business
 * stores the step it has reached (businesses.onboarding_step, null for the
 * first), so its owner resumes there.
 */
enum OnboardingStep: string
{
    case Business = 'business';
    case Location = 'location';
    case Logo = 'logo';
    case Card = 'card';
    case Shipping = 'shipping';

    /** Where the step comes in the wizard, from 0. */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** The step after this one; null after the last. */
    public function next(): ?self
    {
        return self::cases()[$this->position() + 1] ?? null;
    }
}

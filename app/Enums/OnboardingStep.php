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

    /** The step after this one; null after the last. */
    public function next(): ?self
    {
        $steps = self::cases();
        $index = array_search($this, $steps, true);

        return $steps[$index + 1] ?? null;
    }
}

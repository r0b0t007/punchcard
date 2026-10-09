<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Records a wizard step as done (CHW-31): the business moves on to the next
 * step, never back (doing an earlier step again changes nothing), and after
 * the last one it is onboarded. Called by each step's Action once its own
 * write, authorized in the tenant, has been made: the progress itself is the
 * wizard's, written in bypass() under a row lock, so two tabs can't move it
 * back.
 */
final readonly class CompleteStep
{
    public function __construct(private TenantContext $context) {}

    public function handle(Business $business, OnboardingStep $done): void
    {
        $this->context->bypass(fn () => DB::transaction(function () use ($business, $done): void {
            $locked = Business::query()->lock('for no key update')->findOrFail($business->id);
            $reached = $locked->onboarding_step ?? OnboardingStep::Business;

            if ($locked->onboarded_at !== null || $done->position() < $reached->position()) {
                return;
            }

            $next = $done->next();
            $locked->forceFill($next instanceof OnboardingStep ? ['onboarding_step' => $next] : ['onboarded_at' => now()])->save();
            $business->forceFill($locked->only(['onboarding_step', 'onboarded_at']))->syncOriginal();
        }));
    }
}

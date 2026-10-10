<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Tenancy\CreateIndependentBusiness;
use App\Enums\BusinessCategory;
use App\Enums\OnboardingStep;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\SingleBusinessAccounts;
use Illuminate\Support\Facades\DB;

/**
 * The wizard's first step (CHW-31): the business's name and category. With
 * no business to finish, it starts one (anyone signed in may: a customer who
 * owns a café, or an owner opening another), an independent organization of
 * its own. Otherwise it renames the business being set up, and its
 * organization when that is the only business in it. The slug never
 * changes: printed QR codes carry it.
 */
final readonly class SaveBusinessStep
{
    public function __construct(
        private CreateIndependentBusiness $createIndependentBusiness,
        private CompleteStep $completeStep,
    ) {}

    public function handle(User $user, ?Business $business, string $name, BusinessCategory $category): Business
    {
        return DB::transaction(function () use ($user, $business, $name, $category): Business {
            if (! $business instanceof Business) {
                $business = $this->createIndependentBusiness->handle($user, $name, $category);
            } else {
                $business->forceFill(['name' => $name, 'category' => $category])->save();

                if (SingleBusinessAccounts::isTheAccount($business->organization_id)) {
                    $business->organization->forceFill(['name' => $name])->save();
                }
            }

            $this->completeStep->handle($business, OnboardingStep::Business);

            return $business;
        });
    }
}

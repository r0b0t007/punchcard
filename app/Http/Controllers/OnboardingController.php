<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\CancelSetup;
use App\Actions\Onboarding\DescribeCardStep;
use App\Actions\Onboarding\DescribeShippingStep;
use App\Actions\Onboarding\SaveBusinessStep;
use App\Actions\Onboarding\SaveCardStep;
use App\Actions\Onboarding\SaveFirstLocation;
use App\Actions\Onboarding\SaveLogoStep;
use App\Actions\Onboarding\SaveShippingStep;
use App\Enums\BusinessCategory;
use App\Enums\OnboardingStep;
use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\Onboarding\BusinessDetailsRequest;
use App\Http\Requests\Onboarding\FirstCardRequest;
use App\Http\Requests\Onboarding\FirstLocationRequest;
use App\Http\Requests\Onboarding\KitShippingRequest;
use App\Http\Requests\Onboarding\LogoRequest;
use App\Http\Requests\Onboarding\OnboardingRequest;
use App\Models\Business;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The onboarding wizard (CHW-31, spec B2), on the business being set up
 * (ResolveOnboardingBusiness): one page per step, each saved by its own
 * Action, which moves the business on (CompleteStep).
 *
 * - GET /onboarding: resume at the step reached.
 * - GET /onboarding/{step}: a step done or the current one; a later step
 *   sends back to the current one. With no business yet, only the first.
 * - POST /onboarding/business, PUT /onboarding/location, POST
 *   /onboarding/logo (and /onboarding/logo/skip), PUT /onboarding/card: save
 *   a step. PUT /onboarding/shipping saves the last one: the business is set
 *   up, and the dashboard works in it.
 *   A step past the one reached is never saved: back to the current one.
 * - POST /onboarding/cancel: close the business being set up (CancelSetup).
 */
final class OnboardingController extends Controller
{
    public function resume(Request $request): RedirectResponse
    {
        return $this->toStep($this->reached(ResolveOnboardingBusiness::of($request)));
    }

    public function show(Request $request, OnboardingStep $step): Response|RedirectResponse
    {
        $business = ResolveOnboardingBusiness::of($request);
        $reached = $this->reached($business);

        if ($step->position() > $reached->position()) {
            return $this->toStep($reached);
        }

        return Inertia::render('onboarding/'.$step->value, [
            'steps' => [
                'all' => array_map(fn (OnboardingStep $step): string => $step->value, OnboardingStep::cases()),
                'current' => $reached->value,
            ],
            ...match ($step) {
                OnboardingStep::Business => $this->businessProps($business),
                OnboardingStep::Location => $this->locationProps($business),
                OnboardingStep::Logo => $this->logoProps($business),
                OnboardingStep::Card => $this->cardProps($business),
                OnboardingStep::Shipping => $this->shippingProps($business, $request),
            },
        ]);
    }

    public function saveBusiness(BusinessDetailsRequest $request, SaveBusinessStep $saveBusinessStep): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $saveBusinessStep->handle(
            $user,
            ResolveOnboardingBusiness::of($request),
            $request->string('name')->toString(),
            BusinessCategory::from($request->string('category')->toString()),
        );

        return $this->toStep(OnboardingStep::Location);
    }

    public function saveLocation(FirstLocationRequest $request, SaveFirstLocation $saveFirstLocation): RedirectResponse
    {
        if (($ahead = $this->ahead($request, OnboardingStep::Location)) instanceof RedirectResponse) {
            return $ahead;
        }

        $saveFirstLocation->handle(
            $this->business($request),
            $request->string('name')->toString(),
            $request->string('address')->toString(),
            $request->string('timezone')->toString(),
        );

        return $this->toStep(OnboardingStep::Logo);
    }

    public function saveLogo(LogoRequest $request, SaveLogoStep $saveLogoStep): RedirectResponse
    {
        if (($ahead = $this->ahead($request, OnboardingStep::Logo)) instanceof RedirectResponse) {
            return $ahead;
        }

        $saveLogoStep->handle($this->business($request), $request->file('logo'));

        return $this->toStep(OnboardingStep::Card);
    }

    public function skipLogo(OnboardingRequest $request, SaveLogoStep $saveLogoStep): RedirectResponse
    {
        if (($ahead = $this->ahead($request, OnboardingStep::Logo)) instanceof RedirectResponse) {
            return $ahead;
        }

        $saveLogoStep->handle($this->business($request), null);

        return $this->toStep(OnboardingStep::Card);
    }

    public function saveCard(FirstCardRequest $request, SaveCardStep $saveCardStep): RedirectResponse
    {
        if (($ahead = $this->ahead($request, OnboardingStep::Card)) instanceof RedirectResponse) {
            return $ahead;
        }

        $saveCardStep->handle($this->business($request), $request->string('reward_text')->toString(), $request->integer('stamps_required'));

        return $this->toStep(OnboardingStep::Shipping);
    }

    public function saveShipping(KitShippingRequest $request, SaveShippingStep $saveShippingStep): RedirectResponse
    {
        if (($ahead = $this->ahead($request, OnboardingStep::Shipping)) instanceof RedirectResponse) {
            return $ahead;
        }

        $business = $this->business($request);
        $saveShippingStep->handle($business, $request->shipping());

        // Set up: the dashboard works in this business from now on (the tenant switcher can change it).
        $request->session()->put(SetTenant::SESSION_KEY, 'business:'.$business->id);

        return to_route('dashboard');
    }

    public function cancel(OnboardingRequest $request, CancelSetup $cancelSetup): RedirectResponse
    {
        $business = ResolveOnboardingBusiness::of($request);

        if ($business instanceof Business) {
            $cancelSetup->handle($business);
        }

        return to_route('dashboard');
    }

    /** The step the business has reached; the first when there is none yet. */
    private function reached(?Business $business): OnboardingStep
    {
        return $business->onboarding_step ?? OnboardingStep::Business;
    }

    /** The business being set up; a step after the first can't be saved without one. */
    private function business(Request $request): Business
    {
        return ResolveOnboardingBusiness::of($request) ?? abort(404);
    }

    /**
     * Back to the step reached when the request saves a later one: steps are
     * done in order, even by a request that skips the pages.
     */
    private function ahead(Request $request, OnboardingStep $step): ?RedirectResponse
    {
        $reached = $this->reached(ResolveOnboardingBusiness::of($request));

        return $step->position() > $reached->position() ? $this->toStep($reached) : null;
    }

    private function toStep(OnboardingStep $step): RedirectResponse
    {
        return to_route('onboarding.step', $step->value);
    }

    /** @return array<string, mixed> */
    private function businessProps(?Business $business): array
    {
        return [
            'business' => $business instanceof Business ? ['name' => $business->name, 'category' => $business->category?->value] : null,
            'categories' => array_map(fn (BusinessCategory $category): array => ['value' => $category->value, 'label' => $category->getLabel()], BusinessCategory::cases()),
        ];
    }

    /** @return array<string, mixed> */
    private function locationProps(?Business $business): array
    {
        $location = $business?->firstLocation;

        return [
            'location' => [
                'name' => $location->name ?? $business?->name,
                'address' => $location?->address,
                'timezone' => $location->timezone ?? 'Africa/Casablanca',
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }

    /** @return array<string, mixed> */
    private function cardProps(?Business $business): array
    {
        if (! $business instanceof Business) {
            return [];
        }

        $step = app(DescribeCardStep::class)->handle($business);

        return [
            'businessName' => $business->name,
            'logoUrl' => $business->organization->logoUrl(),
            'brandColor' => $business->organization->brand_color,
            'card' => ['rewardText' => $step['rewardText'], 'stampsRequired' => $step['stampsRequired']],
        ];
    }

    /** @return array<string, mixed> */
    private function shippingProps(?Business $business, Request $request): array
    {
        if (! $business instanceof Business) {
            return [];
        }

        /** @var User $owner */
        $owner = $request->user();
        $step = app(DescribeShippingStep::class)->handle($business, $owner);
        unset($step['order']);

        return ['kit' => $step];
    }

    /** @return array<string, mixed> */
    private function logoProps(?Business $business): array
    {
        return ['logoUrl' => $business?->organization->logoUrl()];
    }
}

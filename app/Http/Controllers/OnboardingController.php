<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\CancelSetup;
use App\Actions\Onboarding\SaveBusinessStep;
use App\Actions\Onboarding\SaveFirstLocation;
use App\Actions\Onboarding\SaveLogoStep;
use App\Enums\BusinessCategory;
use App\Enums\OnboardingStep;
use App\Http\Middleware\ResolveOnboardingBusiness;
use App\Http\Middleware\SetTenant;
use App\Http\Requests\Onboarding\BusinessDetailsRequest;
use App\Http\Requests\Onboarding\FirstLocationRequest;
use App\Http\Requests\Onboarding\LogoRequest;
use App\Http\Requests\Onboarding\OnboardingRequest;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
 *   /onboarding/logo (and /onboarding/logo/skip): save a step.
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
                default => [],
            },
        ]);
    }

    public function saveBusiness(BusinessDetailsRequest $request, SaveBusinessStep $saveBusinessStep): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $business = $saveBusinessStep->handle(
            $user,
            ResolveOnboardingBusiness::of($request),
            $request->string('name')->toString(),
            $request->enum('category', BusinessCategory::class) ?? BusinessCategory::Other,
        );

        // A business just started: the wizard (and the dashboard after it) works in it from now on.
        $request->session()->put(SetTenant::SESSION_KEY, 'business:'.$business->id);

        return $this->toStep(OnboardingStep::Location);
    }

    public function saveLocation(FirstLocationRequest $request, SaveFirstLocation $saveFirstLocation): RedirectResponse
    {
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
        $saveLogoStep->handle($this->business($request), $request->file('logo'));

        return $this->toStep(OnboardingStep::Card);
    }

    public function skipLogo(OnboardingRequest $request, SaveLogoStep $saveLogoStep): RedirectResponse
    {
        $saveLogoStep->handle($this->business($request), null);

        return $this->toStep(OnboardingStep::Card);
    }

    public function cancel(OnboardingRequest $request, CancelSetup $cancelSetup): RedirectResponse
    {
        $business = ResolveOnboardingBusiness::of($request);

        if ($business instanceof Business) {
            $cancelSetup->handle($business);
            $request->session()->forget(SetTenant::SESSION_KEY);
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
        $location = $business instanceof Business
            ? Location::query()->where('business_id', $business->id)->open()->oldest('id')->first()
            : null;

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
    private function logoProps(?Business $business): array
    {
        $path = $business?->organization->logo_path;

        return ['logoUrl' => $path === null ? null : Storage::disk('public')->url($path)];
    }
}

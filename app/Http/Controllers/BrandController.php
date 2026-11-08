<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Branding\DescribeBrand;
use App\Actions\Branding\RemoveCover;
use App\Actions\Branding\UpdateBrandColor;
use App\Actions\Branding\UpdateCover;
use App\Actions\Branding\UpdateLogo;
use App\Http\Requests\Brand\BrandColorRequest;
use App\Http\Requests\Brand\BrandCoverRequest;
use App\Http\Requests\Brand\BrandLogoRequest;
use App\Http\Requests\Brand\BrandRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's brand (CHW-135, B17, design 24), for whoever may change
 * it (BrandRequest, OrganizationPolicy::manageBrand).
 *
 * - GET /brand: the page and its previews (DescribeBrand).
 * - PATCH /brand: the brand colour (UpdateBrandColor).
 * - POST /brand/logo, /brand/cover: a new logo or cover (UpdateLogo, UpdateCover).
 * - DELETE /brand/cover: no cover (RemoveCover).
 */
final class BrandController extends Controller
{
    public function edit(BrandRequest $request, DescribeBrand $describeBrand): Response
    {
        return Inertia::render('business/brand', $describeBrand->handle($this->organizationOf($request)));
    }

    public function update(BrandColorRequest $request, UpdateBrandColor $updateBrandColor): RedirectResponse
    {
        $color = $request->validated('brand_color');
        $updateBrandColor->handle($this->organizationOf($request), is_string($color) ? $color : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand colour saved.')]);

        return to_route('brand.edit');
    }

    public function logo(BrandLogoRequest $request, UpdateLogo $updateLogo): RedirectResponse
    {
        $logo = $request->file('logo');
        assert($logo instanceof UploadedFile);

        $updateLogo->handle($this->organizationOf($request), $logo);

        return to_route('brand.edit');
    }

    public function cover(BrandCoverRequest $request, UpdateCover $updateCover): RedirectResponse
    {
        $cover = $request->file('cover');
        assert($cover instanceof UploadedFile);

        $updateCover->handle($this->organizationOf($request), $cover);

        return to_route('brand.edit');
    }

    public function destroyCover(BrandRequest $request, RemoveCover $removeCover): RedirectResponse
    {
        $removeCover->handle($this->organizationOf($request));

        return to_route('brand.edit');
    }

    private function organizationOf(BrandRequest $request): Organization
    {
        $organization = $request->organization();
        assert($organization instanceof Organization);

        return $organization;
    }
}

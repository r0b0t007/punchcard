<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs up an independent café (ADR 0006): an `independent` organization with
 * one business. The owner runs the business and administers the organization,
 * which owns the card program. The business waits in the verification queue.
 * Runs in bypass(): signup creates a tenant, so there is none yet.
 */
final readonly class CreateIndependentBusiness
{
    /** Attempts per slug before giving up: a readable one, then random suffixes. */
    private const int SLUG_ATTEMPTS = 3;

    public function __construct(private TenantContext $context) {}

    public function handle(User $owner, string $name, ?string $category = null): Business
    {
        return $this->context->bypass(fn (): Business => DB::transaction(function () use ($owner, $name, $category): Business {
            /** @var Organization $organization */
            $organization = $this->createWithUniqueSlug(new Organization, $name, fn (Organization $organization) => $organization->forceFill([
                'name' => $name,
                'type' => OrganizationType::Independent,
            ]));

            /** @var Business $business */
            $business = $this->createWithUniqueSlug(new Business, $name, fn (Business $business) => $business->forceFill([
                'organization_id' => $organization->id,
                'name' => $name,
                'category' => $category,
                'status' => BusinessStatus::Pending,
            ]));

            $organization->admins()->attach($owner, ['role' => OrganizationRole::OrgAdmin->value]);
            $business->members()->attach($owner, ['role' => BusinessRole::Owner->value]);

            return $business;
        }));
    }

    /**
     * Saves the model with a slug from its name: "cafe-hafa", "cafe-hafa-2"...
     * If a concurrent signup takes the slug first, retries with a random suffix.
     * Each attempt runs in a savepoint, so a collision does not abort the
     * surrounding transaction (Postgres would otherwise refuse further queries).
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  callable(TModel): mixed  $fill
     * @return TModel
     */
    private function createWithUniqueSlug(Model $model, string $name, callable $fill): Model
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $this->firstFreeSlug($model, $base);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($model, $fill, $slug): Model {
                    $fresh = $model->newInstance();
                    $fill($fresh);
                    $fresh->forceFill(['slug' => $slug])->save();

                    return $fresh;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::SLUG_ATTEMPTS) {
                    throw $exception;
                }

                $slug = $base.'-'.Str::lower(Str::random(5));
            }
        }
    }

    private function firstFreeSlug(Model $model, string $base): string
    {
        $slug = $base;

        for ($suffix = 2; $model->newQuery()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}

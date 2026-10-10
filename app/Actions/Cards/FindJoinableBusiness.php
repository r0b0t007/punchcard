<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\Business;
use App\Support\Tenancy\TenantContext;

/**
 * The business a join page (/j/{slug}, CHW-31) is for: one that operates,
 * pending verification or not, as for taps (CHW-22). A suspended or closed
 * business has none. Read across tenants: the page is public.
 */
final readonly class FindJoinableBusiness
{
    public function __construct(private TenantContext $context) {}

    public function handle(string $slug): ?Business
    {
        return $this->context->bypass(fn (): ?Business => Business::query()
            ->where('slug', $slug)
            ->operating()
            ->with('organization')
            ->first());
    }
}

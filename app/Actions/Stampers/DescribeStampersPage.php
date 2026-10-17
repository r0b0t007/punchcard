<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Stamper;
use App\Models\Tap;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The stampers page (B12, CHW-36): each open site of the business with its
 * stampers, enabled or not, their last tap and their taps today, the day in
 * the site's own timezone (LocalMoment::dayStart, as the daily cap counts
 * it). Taps on the stamper only (Tap::onTheStamper): a copied link replayed
 * says nothing about it being used. Taps are platform data, read in
 * bypass() for this business's own stampers.
 */
final readonly class DescribeStampersPage
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{sites: list<array{id: int, name: string, timezone: string, stampers: list<array{id: int, label: string|null, enabled: bool, held: bool, lastTapAt: string|null, tapsToday: int}>}>}
     */
    public function handle(Business $business): array
    {
        $sites = Location::query()->open()->where('business_id', $business->id)->orderBy('name')->get(['id', 'name', 'timezone']);
        $stampers = Stamper::query()->current()->where('business_id', $business->id)->whereIn('location_id', $sites->modelKeys())->orderBy('id')->get();

        return ['sites' => array_values($sites->map(fn (Location $site): array => [
            'id' => $site->id,
            'name' => $site->name,
            'timezone' => LocalMoment::timezoneOf($site),
            'stampers' => array_values($stampers->where('location_id', $site->id)->map(fn (Stamper $stamper): array => [
                'id' => $stamper->id,
                'label' => $stamper->label,
                'enabled' => $stamper->status === StamperStatus::Active,
                // Off until its keys are changed (SetStamperStatus): the owner can't switch it on.
                'held' => $stamper->held_at !== null,
                ...$this->taps($stamper, $business, LocalMoment::timezoneOf($site)),
            ])->all()),
        ])->all())];
    }

    /** @return array{lastTapAt: string|null, tapsToday: int} */
    private function taps(Stamper $stamper, Business $business, string $timezone): array
    {
        return $this->context->bypass(function () use ($stamper, $business, $timezone): array {
            $taps = fn () => Tap::query()->where('stamper_id', $stamper->id)->where('business_id', $business->id)->onTheStamper();
            $last = $taps()->latest('created_at')->value('created_at');

            return [
                'lastTapAt' => $last instanceof DateTimeInterface || is_string($last) ? Carbon::parse($last)->toIso8601String() : null,
                'tapsToday' => $taps()->where('created_at', '>=', LocalMoment::dayStart($timezone))->count(),
            ];
        });
    }
}

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The stampers page (B12, CHW-36): each open site of the business with its
 * stampers, enabled or not, their last tap and their taps today, the day in
 * the site's own timezone (LocalMoment::dayStart, as the daily cap counts
 * it). Taps on the stamper only (Tap::onTheStamper): a copied link replayed
 * says nothing about it being used. Taps are platform data, read in
 * bypass() for this business's own stampers: two grouped queries for the
 * whole page, however many stampers.
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
        $timezones = $sites->mapWithKeys(fn (Location $site): array => [$site->id => LocalMoment::timezoneOf($site)])->all();
        [$lastTaps, $tapsToday] = $this->taps($business, $stampers, $timezones);

        return ['sites' => array_values($sites->map(fn (Location $site): array => [
            'id' => $site->id,
            'name' => $site->name,
            'timezone' => $timezones[$site->id],
            'stampers' => array_values($stampers->where('location_id', $site->id)->map(fn (Stamper $stamper): array => [
                'id' => $stamper->id,
                'label' => $stamper->label,
                'enabled' => $stamper->status === StamperStatus::Active,
                // Off until its keys are changed (SetStamperStatus): the owner can't switch it on.
                'held' => $stamper->held_at !== null,
                'lastTapAt' => isset($lastTaps[$stamper->id]) ? Carbon::parse($lastTaps[$stamper->id])->toIso8601String() : null,
                'tapsToday' => $tapsToday[$stamper->id] ?? 0,
            ])->all()),
        ])->all())];
    }

    /**
     * Each stamper's last tap and its taps since its site's day began.
     *
     * @param  Collection<int, Stamper>  $stampers
     * @param  array<int, string>  $timezones  by location id
     * @return array{0: array<int, string>, 1: array<int, int>} by stamper id
     */
    private function taps(Business $business, Collection $stampers, array $timezones): array
    {
        if ($stampers->isEmpty()) {
            return [[], []];
        }

        return $this->context->bypass(function () use ($business, $stampers, $timezones): array {
            $taps = fn (): Builder => Tap::query()->where('business_id', $business->id)->whereIn('stamper_id', $stampers->modelKeys())->onTheStamper()->groupBy('stamper_id');
            $today = $taps()->where(function (Builder $sinceDayStart) use ($stampers, $timezones): void {
                foreach ($stampers->groupBy('location_id') as $locationId => $atSite) {
                    $sinceDayStart->orWhere(fn (Builder $site): Builder => $site->whereIn('stamper_id', $atSite->modelKeys())->where('created_at', '>=', LocalMoment::dayStart($timezones[$locationId])));
                }
            });

            return [
                $taps()->selectRaw('stamper_id, max(created_at) as last_tap_at')->pluck('last_tap_at', 'stamper_id')->map(fn (mixed $at): string => (string) $at)->all(),
                $today->selectRaw('stamper_id, count(*) as taps')->pluck('taps', 'stamper_id')->map(fn (mixed $count): int => (int) $count)->all(),
            ];
        });
    }
}

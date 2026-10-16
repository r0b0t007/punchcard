<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\StampEvent;
use Illuminate\Support\Carbon;

/**
 * The counter screen of a business (CHW-29, S1), as one who works there
 * sees it: the current stampers they may arm (staff limited to one location,
 * that location's only), each with its location, whether it is enabled, its
 * last tap (the last stamp it gave: the business's own ledger, never the
 * platform's tap log) and its arming while it lasts.
 */
final readonly class DescribeCounter
{
    /**
     * @return array{
     *     businessName: string,
     *     stampers: array<int, array{id: int, label: string|null, location: string|null, enabled: bool, lastTapAt: string|null, armedQty: int|null, armedSecondsLeft: int|null}>,
     *     armSeconds: int,
     *     quantities: array<int, int>,
     * }
     */
    public function handle(Business $business, ?int $locationId): array
    {
        $stampers = Stamper::query()
            ->current()
            ->where('business_id', $business->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with('location:id,name')
            ->orderBy('location_id')
            ->orderBy('id')
            ->get();

        $lastTaps = StampEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('stamper_id', $stampers->pluck('id'))
            ->groupBy('stamper_id')
            ->selectRaw('stamper_id, max(created_at) as last_tap_at')
            ->pluck('last_tap_at', 'stamper_id');

        return [
            'businessName' => $business->name,
            'stampers' => $stampers->map(function (Stamper $stamper) use ($lastTaps): array {
                $armed = $stamper->armed_qty !== null && $stamper->armed_until?->isFuture() === true;
                $lastTap = $lastTaps->get($stamper->id);

                return [
                    'id' => $stamper->id,
                    'label' => $stamper->label,
                    'location' => $stamper->location?->name,
                    'enabled' => $stamper->status === StamperStatus::Active,
                    'lastTapAt' => is_string($lastTap) ? Carbon::parse($lastTap)->toIso8601String() : null,
                    'armedQty' => $armed ? $stamper->armed_qty : null,
                    'armedSecondsLeft' => $armed ? (int) max(0, ceil(now()->diffInSeconds($stamper->armed_until))) : null,
                ];
            })->values()->all(),
            'armSeconds' => ArmStamper::SECONDS,
            'quantities' => ArmStamper::QUANTITIES,
        ];
    }
}

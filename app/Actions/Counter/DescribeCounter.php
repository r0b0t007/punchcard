<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use App\Actions\Stamps\ArmingCounts;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The counter screen of a business (CHW-29, S1), as one who works there
 * sees it: the current stampers they may arm (staff limited to one location,
 * that location's only), each with its location, whether it is enabled, its
 * last tap and its arming while it lasts. The last tap is the last one the
 * stamper received, waiting for sign-in or refused by the cooldown or cap
 * included (staff see it landed and never arm again for it); never a
 * malformed, forged or replayed URL. Read from the platform's tap log in
 * bypass(), this business's stampers only.
 */
final readonly class DescribeCounter
{
    public function __construct(private TenantContext $context, private ArmingCounts $armingCounts) {}

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

        // One newest-first lookup per stamper on (stamper_id, created_at): it stops at the first real tap, however
        // long the stamper's history, on every poll (a counter has a few stampers).
        $lastTaps = $this->context->bypass(fn () => $stampers->mapWithKeys(fn (Stamper $stamper): array => [
            $stamper->id => Tap::query()
                ->where('stamper_id', $stamper->id)
                ->where('business_id', $business->id)
                ->onTheStamper()
                ->latest('created_at')
                ->value('created_at'),
        ]));

        // As the tap will take it: an arming by someone no longer working here shows idle (CHW-309). Asked once per
        // armer and location, not per stamper, on every poll.
        $armerKey = fn (Stamper $stamper): string => $stamper->armed_by.':'.$stamper->location_id;
        $armingCounts = $stampers->filter(fn (Stamper $stamper): bool => $stamper->isArmed())
            ->unique($armerKey)
            ->mapWithKeys(fn (Stamper $stamper): array => [$armerKey($stamper) => $this->armingCounts->handle($stamper)]);

        return [
            'businessName' => $business->name,
            'stampers' => $stampers->map(function (Stamper $stamper) use ($lastTaps, $armingCounts, $armerKey): array {
                $armed = $stamper->isArmed() && $armingCounts->get($armerKey($stamper)) === true;
                $lastTap = $lastTaps->get($stamper->id);

                return [
                    'id' => $stamper->id,
                    'label' => $stamper->label,
                    'location' => $stamper->location?->name,
                    'enabled' => $stamper->status === StamperStatus::Active,
                    'lastTapAt' => $lastTap instanceof DateTimeInterface || is_string($lastTap) ? Carbon::parse($lastTap)->toIso8601String() : null,
                    'armedQty' => $armed ? $stamper->armed_qty : null,
                    'armedSecondsLeft' => $armed ? (int) max(0, ceil(now()->diffInSeconds($stamper->armed_until))) : null,
                ];
            })->values()->all(),
            'armSeconds' => ArmStamper::SECONDS,
            'quantities' => ArmStamper::QUANTITIES,
        ];
    }
}

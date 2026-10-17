<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Tap;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The taps refused at a business in the last DAYS days (B12, CHW-36), newest
 * first, PER_PAGE at a time: when (and the site's timezone to show it in),
 * where, which stamper, why (TapRejection::visibleToBusiness, with the signs
 * of tampering marked), and the customer's first name only when the
 * business may see them: through its own, tenant-scoped view of the card's
 * enrollments, as RecentStamps does (a franchise customer who only visited a
 * sibling stays nameless). Never the tap's IP or user agent. Taps are
 * platform data, read in bypass() for this business only.
 */
final readonly class RejectedTaps
{
    public const int DAYS = 30;

    public const int PER_PAGE = 25;

    public function __construct(private TenantContext $context) {}

    /**
     * @return LengthAwarePaginator<int, array{id: int, at: string, timezone: string, site: string|null, stamper: string|null, stamperId: int|null, reason: string, tampering: bool, customer: string|null}>
     */
    public function handle(Business $business): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Tap> $taps */
        $taps = $this->context->bypass(fn (): LengthAwarePaginator => Tap::query()
            ->where('business_id', $business->id)
            ->where('status', TapStatus::Rejected)
            ->whereIn('rejection', TapRejection::visibleToBusiness())
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->with(['location:id,name,timezone', 'stamper:id,label'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'stamper_id', 'location_id', 'card_id', 'user_id', 'rejection', 'created_at']));

        $names = $this->firstNames($taps->getCollection()->all());

        return $taps->through(fn (Tap $tap): array => [
            'id' => $tap->id,
            'at' => ($tap->created_at ?? now())->toIso8601String(),
            'timezone' => LocalMoment::timezoneOf($tap->location),
            'site' => $tap->location?->name,
            'stamper' => $tap->stamper?->label,
            'stamperId' => $tap->stamper_id,
            'reason' => ($tap->rejection ?? TapRejection::Replay)->value,
            'tampering' => $tap->rejection?->isTampering() ?? false,
            'customer' => $names[$tap->card_id.':'.$tap->user_id] ?? null,
        ]);
    }

    /**
     * The first names the business may see, by "card:user", from its own scoped enrollments.
     *
     * @param  array<Tap>  $taps
     * @return array<string, string>
     */
    private function firstNames(array $taps): array
    {
        $known = array_filter($taps, fn (Tap $tap): bool => $tap->card_id !== null && $tap->user_id !== null);

        if ($known === []) {
            return [];
        }

        return CardEnrollment::query()
            ->whereIn('card_id', array_map(fn (Tap $tap): ?int => $tap->card_id, $known))
            ->whereIn('user_id', array_map(fn (Tap $tap): ?int => $tap->user_id, $known))
            ->with('user:id,name')
            ->get()
            ->mapWithKeys(fn (CardEnrollment $enrollment): array => [$enrollment->card_id.':'.$enrollment->user_id => $enrollment->user?->firstName()])
            ->filter()
            ->all();
    }
}

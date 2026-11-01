<?php

declare(strict_types=1);

use App\Actions\Campaigns\CampaignAudience;
use App\Actions\Campaigns\DescribeAudience;
use App\Enums\CampaignSegment;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Who a campaign reaches (CHW-42, B9)
|--------------------------------------------------------------------------
|
| A business's campaign goes to its organization's followers who came in:
| following for offers, with a presence stamp at this business (the CHW-27
| franchise rule). Never someone who only visited a sister franchisee, never
| another organization's, never an anonymised account. Who will actually
| get it: those with a phone with notifications on.
|
*/

beforeEach(function (): void {
    $this->tenants = Tenants::make();
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);

    // A customer of the card, following its organization (or not), with presence stamps at the given businesses.
    $this->customer = function (LoyaltyCard $card, array $visits, bool $follows = true, bool $phone = true): User {
        $customer = User::factory()->create();
        $enrollment = $this->tenants->enroll($customer, $card);
        app(TenantContext::class)->bypass(fn () => (new Follow)->forceFill(['organization_id' => $card->organization_id, 'user_id' => $customer->id, 'notify' => $follows])->save());

        foreach ($visits as [$business, $daysAgo, $qty, $source]) {
            $this->tenants->stamp($enrollment, $business, ['qty' => $qty, 'source' => $source, 'created_at' => now()->subDays($daysAgo)]);
        }

        if ($phone) {
            $customer->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.$customer->id, 'BKey', 'Secret');
        }

        return $customer;
    };
    $this->visit = fn (Business $at, int $daysAgo = 1, int $qty = 1, StampSource $source = StampSource::Qr): array => [$at, $daysAgo, $qty, $source];
    $this->audience = fn (Business $business, CampaignSegment $segment = CampaignSegment::All): array => app(CampaignAudience::class)->handle($business)[$segment->value];
});

it('reaches the organization\'s followers who came in to this business, and nobody else', function (): void {
    $a1 = $this->tenants->a1;
    $here = ($this->customer)($this->tenants->cardA, [($this->visit)($a1)]);
    $onlySister = ($this->customer)($this->tenants->cardA, [($this->visit)($this->tenants->a2)]);
    $notFollowing = ($this->customer)($this->tenants->cardA, [($this->visit)($a1)], follows: false);
    $neverCame = ($this->customer)($this->tenants->cardA, []);
    $bonusOnly = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, source: StampSource::Bonus)]);
    $otherOrganization = ($this->customer)($this->tenants->cardB, [($this->visit)($this->tenants->b1)]);
    // Holds A's card and came in to A1, but follows only B for offers.
    $followsOnlyB = ($this->customer)($this->tenants->cardB, [($this->visit)($this->tenants->b1)]);
    $cardOfA = $this->tenants->enroll($followsOnlyB, $this->tenants->cardA);
    $this->tenants->stamp($cardOfA, $a1);
    $anonymised = ($this->customer)($this->tenants->cardA, [($this->visit)($a1)]);
    $anonymised->forceFill(['anonymised_at' => now()])->save();

    expect(($this->audience)($a1))->toBe([$here->id])
        ->and(($this->audience)($this->tenants->a2))->toBe([$onlySister->id])
        ->and(($this->audience)($this->tenants->b1))->toBe([$otherOrganization->id, $followsOnlyB->id]);
});

it('reaches the followers not seen here in 14 days or more, by this business\'s stamps only', function (): void {
    // To the second, as the database keeps it: exactly 14 days is quiet.
    $this->freezeSecond();
    $a1 = $this->tenants->a1;
    $fourteen = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 14)]);
    $thirteen = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 13)]);
    $quietHereBusyThere = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 30), ($this->visit)($this->tenants->a2, 1)]);
    $cameBack = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 30, 2), ($this->visit)($a1, 2)]);
    // A correction today is the staff's, not a visit: still not seen in 30 days.
    $correctedToday = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 30, 2)]);
    $enrollment = app(TenantContext::class)->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $correctedToday->id)->sole());
    $this->tenants->stamp($enrollment, $a1, ['qty' => -1, 'source' => StampSource::Correction, 'reason' => 'Given by mistake']);

    expect(($this->audience)($a1, CampaignSegment::Quiet))->toBe([$fourteen->id, $quietHereBusyThere->id, $correctedToday->id]);
});

it('reaches the 10 followers with the most stamps here in 90 days, the latest first among equals', function (): void {
    $a1 = $this->tenants->a1;
    $regulars = collect(range(1, 9))->map(fn (int $n): User => ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 3, 20 - $n)]));
    $tiedEarlier = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 5, 2)]);
    $tiedLater = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 4, 2)]);
    $longAgo = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 91, 50)]);
    $elsewhere = ($this->customer)($this->tenants->cardA, [($this->visit)($this->tenants->a2, 1, 50), ($this->visit)($a1, 1, 1)]);
    // 30 stamps given here by mistake, 29 of them taken back with a correction: 1, below everyone above.
    $corrected = ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 2, 30)]);
    $enrollment = app(TenantContext::class)->bypass(fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $corrected->id)->sole());
    $this->tenants->stamp($enrollment, $a1, ['qty' => -29, 'source' => StampSource::Correction, 'reason' => 'Given by mistake']);

    expect(($this->audience)($a1, CampaignSegment::Top))->toBe([...$regulars->pluck('id')->all(), $tiedLater->id]);
});

it('counts the followers in each segment, and who among them will get it on a phone', function (): void {
    $a1 = $this->tenants->a1;
    ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 20)]);
    ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 20)], phone: false);
    ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 1)]);
    // Seen here only before the top's 90 days: quiet, never in the top.
    ($this->customer)($this->tenants->cardA, [($this->visit)($a1, 100)]);

    expect(app(DescribeAudience::class)->handle($a1))->toBe([
        'all' => ['followers' => 4, 'reach' => 3],
        'quiet_14d' => ['followers' => 3, 'reach' => 2],
        'top_10' => ['followers' => 3, 'reach' => 2],
    ]);

    // No VAPID key on the server: nobody can get one.
    config(['webpush.vapid.public_key' => null]);
    expect(app(DescribeAudience::class)->handle($a1)['all'])->toBe(['followers' => 4, 'reach' => 0]);
});

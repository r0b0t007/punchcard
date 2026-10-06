<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RewardStatus;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gives a customer an available reward on the card a stamper's business
 * honours, as if they had just filled it: for local work and the end-to-end
 * redemption test, which spends one each run (CHW-26). Runs only in local and
 * testing, like punchcard:fake-tap (anyone with it could mint rewards).
 */
#[Signature('punchcard:fake-reward {email : The customer\'s email} {stamper : A stamper id of the café}')]
#[Description('Give a customer an available reward at a stamper\'s café (local and testing only)')]
class FakeRewardCommand extends Command
{
    public function handle(TenantContext $context): int
    {
        if (! $this->laravel->environment(FakeTap::ENVIRONMENTS)) {
            $this->error('Fake rewards are given only in local and testing.');

            return self::FAILURE;
        }

        $reward = $context->bypass(function (): ?Reward {
            $user = User::query()->where('email', $this->argument('email'))->first();
            $businessId = Stamper::query()->whereKey($this->argument('stamper'))->value('business_id');
            $card = $businessId === null ? null : LoyaltyCard::query()->honouredBy((int) $businessId)->first();

            if (! $user instanceof User || ! $card instanceof LoyaltyCard) {
                return null;
            }

            $enrollment = CardEnrollment::query()->firstOrCreate(['card_id' => $card->id, 'user_id' => $user->id]);
            $milestone = (int) Reward::query()->where('enrollment_id', $enrollment->id)->max('milestone') + 1;

            return tap((new Reward)->forceFill([
                'enrollment_id' => $enrollment->id,
                'mode' => $card->mode,
                'milestone' => $milestone,
                'reward_type' => $card->reward_type,
                'reward_value' => $card->reward_value,
                'reward_text' => $card->reward_text,
                'unlocked_at' => now(),
                'status' => RewardStatus::Available,
            ]))->save();
        });

        if (! $reward instanceof Reward) {
            $this->error('No customer with that email, or no café with an active card behind that stamper.');

            return self::FAILURE;
        }

        $this->line("Reward {$reward->id}: {$reward->reward_text}");

        return self::SUCCESS;
    }
}

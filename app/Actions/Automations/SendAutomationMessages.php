<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Enums\AutomationTrigger;
use App\Enums\CustomerSegment;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Reward;
use App\Models\User;
use App\Notifications\AutomationMessage;
use App\Support\Automations\AutomationWindow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * One batch of a business's automation messages (CHW-46), from its queued
 * job (PushAutomationMessages), in the business's tenant: only that
 * business's sends, each claimed by RunAutomations. Each is checked again
 * as it goes, so a queue running late never sends what no longer holds:
 *
 * - claimed within AutomationWindow::LATE_MINUTES, never sent yet;
 * - the business still sends now (AutomationWindow::sendsNow: allowed, open,
 *   not suspended, its daytime), the automation still on;
 * - the customer's account still there; a win-back's customer still
 *   in the same quiet spell; both still following the business with
 *   notifications on, a reminder's customer still with "Rewards ready" on
 *   and the reward theirs and still available.
 *
 * Then the automation's text as it is now: ours, or the owner's. One failing
 * never stops the others; logged by id only. Each is marked sent once a
 * phone's push service accepts it (MarkAutomationSent).
 *
 * In bypass(): every read kept to the business and its organization.
 */
final readonly class SendAutomationMessages
{
    public function __construct(private TenantContext $context) {}

    /** @param list<int> $sendIds handed out by RunAutomations, all of the tenant's business */
    public function handle(array $sendIds): void
    {
        $businessId = $this->context->businessId() ?? throw new LogicException('Automation messages are sent in their business\'s tenant.');

        $this->context->bypass(function () use ($businessId, $sendIds): void {
            $business = Business::query()->findOrFail($businessId);

            if (! AutomationWindow::sendsNow($business, now())) {
                return;
            }

            $sends = AutomationSend::query()->whereKey($sendIds)->where('business_id', $business->id)->whereNull('sent_at')
                ->where('created_at', '>', now()->subMinutes(AutomationWindow::LATE_MINUTES))->orderBy('id')->get();
            $automations = Automation::query()->where('business_id', $business->id)->where('active', true)->get()->keyBy('id');
            $users = User::query()->whereKey($sends->pluck('user_id')->unique()->all())->notAnonymised()->get()->keyBy('id');

            foreach ($sends as $send) {
                $automation = $automations->get($send->automation_id);
                $user = $users->get($send->user_id);

                if (! $automation instanceof Automation || ! $user instanceof User) {
                    continue;
                }

                $message = $this->message($send, $automation, $business, $user);

                if (! $message instanceof AutomationMessage) {
                    continue;
                }

                try {
                    $user->notifyNow($message);
                } catch (Throwable) {
                    Log::warning('An automation message failed.', ['send' => $send->id, 'user' => $user->id]);
                }
            }
        });
    }

    /** The message for the send, or null when it no longer applies. */
    private function message(AutomationSend $send, Automation $automation, Business $business, User $user): ?AutomationMessage
    {
        $reward = null;

        if (! Follow::query()->listeningTo($business)->where('user_id', $send->user_id)->exists()
            || ($send->trigger === AutomationTrigger::WinBack && ! $this->stillQuiet($send, $business))) {
            return null;
        }

        if ($send->trigger === AutomationTrigger::RewardReminder) {
            $reward = $user->push_rewards ? $this->rewardWaiting($send, $business, $user) : null;

            if (! $reward instanceof Reward) {
                return null;
            }
        }

        return new AutomationMessage(
            $send->id,
            $send->trigger,
            $business->id,
            $business->name,
            $automation->title,
            $automation->body,
            $reward->enrollment_id ?? $this->cardHere($send->user_id, $business),
            $reward?->reward_text,
        );
    }

    /** Still in the quiet spell the send was claimed for. */
    private function stillQuiet(AutomationSend $send, Business $business): bool
    {
        $quietAt = BusinessCustomer::query()->where('business_id', $business->id)->where('user_id', $send->user_id)
            ->inSegment(CustomerSegment::Quiet, now())->value('quiet_at');

        return $quietAt !== null && 'quiet:'.$quietAt->toDateString() === $send->occasion;
    }

    /** The reward the send reminds of: the customer's own, of this organization, still available. */
    private function rewardWaiting(AutomationSend $send, Business $business, User $user): ?Reward
    {
        if (preg_match('/^reward:(\d+)$/', $send->occasion, $match) !== 1) {
            return null;
        }

        return Reward::query()
            ->whereKey((int) $match[1])
            ->where('organization_id', $business->organization_id)
            ->availableAt(now())
            ->ownedBy($user)
            ->first();
    }

    /** The customer's card of the organization they last stamped at this business, if any. */
    private function cardHere(int $userId, Business $business): ?int
    {
        $id = CardEnrollment::query()
            ->where('organization_id', $business->organization_id)
            ->where('user_id', $userId)
            ->whereHas('stampEvents', fn ($events) => $events->where('business_id', $business->id))
            ->orderByDesc('last_stamp_at')
            ->orderByDesc('id')
            ->value('id');

        return is_int($id) ? $id : null;
    }
}

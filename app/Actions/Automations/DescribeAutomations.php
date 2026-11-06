<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\LoyaltyCard;
use App\Support\Automations\AutomationText;
use App\Support\Campaigns\CampaignText;

/**
 * B11's props (CHW-46): each automation of the business, in the cap's order,
 * as saved or as it starts (off, 7 days, 1 stamp, our message), with:
 *
 * - its message as a customer gets it, in the owner's language for ours
 *   (AutomationText), the reminder's with the reward of the card the
 *   business runs;
 * - how many people it reached in the last SENT_DAYS days (a phone's push
 *   service accepted it);
 * - the limits its form keeps (SaveAutomationRequest).
 *
 * Read in the business's tenant.
 */
final readonly class DescribeAutomations
{
    public const int SENT_DAYS = 30;

    /** @return array<string, mixed> */
    public function handle(Business $business): array
    {
        $saved = Automation::query()->where('business_id', $business->id)->get()->keyBy(fn (Automation $automation): string => $automation->trigger->value);
        $sent = AutomationSend::query()->where('business_id', $business->id)
            ->where('sent_at', '>=', now()->subDays(self::SENT_DAYS))
            // People, not messages: a customer reminded of two rewards is one.
            ->selectRaw('automation_id, count(distinct user_id) as people')->groupBy('automation_id')->pluck('people', 'automation_id');
        $reward = LoyaltyCard::query()->honouredBy($business->id)->where('active', true)->value('reward_text');

        return [
            'business' => $business->name,
            'automations' => array_map(function (AutomationTrigger $trigger) use ($saved, $sent, $business, $reward): array {
                $automation = $saved->get($trigger->value) ?? new Automation(['trigger' => $trigger]);

                return [
                    'trigger' => $trigger->value,
                    'name' => $trigger->label(),
                    'active' => $automation->active,
                    'days' => $automation->days,
                    'stamps' => $automation->stamps,
                    'title' => $automation->title,
                    'body' => $automation->body,
                    // Ours, for the preview and for "Use our message instead".
                    'ours' => [
                        'title' => AutomationText::title($trigger, $business->name),
                        'body' => AutomationText::body($trigger, null, is_string($reward) ? $reward : __('your reward'), $automation->stamps),
                    ],
                    'sent' => $automation->exists ? (int) $sent->get($automation->id, 0) : 0,
                ];
            }, [AutomationTrigger::RewardReminder, AutomationTrigger::Birthday, AutomationTrigger::WinBack]),
            'limits' => [
                'days' => ['min' => SaveAutomation::DAYS_MIN, 'max' => SaveAutomation::DAYS_MAX],
                'stamps' => ['min' => SaveAutomation::STAMPS_MIN, 'max' => SaveAutomation::STAMPS_MAX],
                'title' => CampaignText::titleRoom($business->name),
                'body' => CampaignText::BODY,
            ],
        ];
    }
}

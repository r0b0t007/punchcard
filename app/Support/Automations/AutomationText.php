<?php

declare(strict_types=1);

namespace App\Support\Automations;

use App\Enums\AutomationTrigger;
use App\Support\Campaigns\CampaignText;

/**
 * An automation message's words (CHW-46), in the current locale: ours, or
 * the owner's (titled with the business's name, as a campaign's,
 * CampaignText). What a customer gets (AutomationMessage) and what B11
 * shows the owner, so the two never differ.
 */
final class AutomationText
{
    /** The title: the owner's after the business's name, or ours. */
    public static function title(AutomationTrigger $trigger, string $business, ?string $owners = null): string
    {
        if ($owners !== null) {
            return CampaignText::notificationTitle($business, $owners);
        }

        return match ($trigger) {
            AutomationTrigger::WinBack => __('We miss you at :business', ['business' => $business]),
            AutomationTrigger::Birthday => __('Happy birthday from :business!', ['business' => $business]),
            AutomationTrigger::RewardReminder => __('Your reward is waiting at :business', ['business' => $business]),
        };
    }

    /** The message: the owner's as written, or ours (with the reward's text, or the birthday's stamps). */
    public static function body(AutomationTrigger $trigger, ?string $owners = null, ?string $reward = null, ?int $stamps = null): string
    {
        return $owners ?? match ($trigger) {
            AutomationTrigger::WinBack => __('Come back soon: your card is waiting.'),
            AutomationTrigger::Birthday => trans_choice(':count stamp on your card, our treat.|:count stamps on your card, our treat.', $stamps ?? 1),
            AutomationTrigger::RewardReminder => __('Don\'t forget: :reward is yours.', ['reward' => (string) $reward]),
        };
    }
}

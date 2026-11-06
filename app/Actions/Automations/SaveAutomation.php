<?php

declare(strict_types=1);

namespace App\Actions\Automations;

use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * An automation as the owner sets it on B11 (CHW-46): on or off, its one
 * setting (the reminder's wait in days, the birthday's stamps), and the
 * owner's own title and message, or none for ours. Made the first time,
 * kept for its trigger after (Automation: never deleted, its trigger never
 * changed). Two saves at once (a double click on a new one) make it once:
 * the row is found or made (createOrFirst), then locked while it changes.
 * In the business's tenant (SaveAutomationRequest).
 */
final readonly class SaveAutomation
{
    public const int DAYS_MIN = 1;

    public const int DAYS_MAX = 60;

    public const int STAMPS_MIN = 1;

    public const int STAMPS_MAX = 3;

    /**
     * @param  array{active: bool, days?: int, stamps?: int, title: ?string, body: ?string}  $values
     * @return array{automation: Automation, wasActive: bool} the automation saved, and whether it was on before
     */
    public function handle(Business $business, AutomationTrigger $trigger, array $values): array
    {
        return DB::transaction(function () use ($business, $trigger, $values): array {
            $made = Automation::query()->createOrFirst(['business_id' => $business->id, 'trigger' => $trigger]);
            $automation = Automation::query()->whereKey($made->id)->lockForUpdate()->firstOrFail();
            $wasActive = $automation->active;

            $automation->fill([
                'active' => $values['active'],
                'title' => $values['title'],
                'body' => $values['body'],
                // Only the setting this trigger has; the others keep their defaults.
                ...match ($trigger) {
                    AutomationTrigger::RewardReminder => ['days' => $values['days'] ?? $automation->days],
                    AutomationTrigger::Birthday => ['stamps' => $values['stamps'] ?? $automation->stamps],
                    AutomationTrigger::WinBack => [],
                },
            ])->save();

            return ['automation' => $automation, 'wasActive' => $wasActive];
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Automations;

use App\Actions\Automations\SaveAutomation;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Business;
use App\Support\Campaigns\CampaignText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An automation from whoever runs the current business, while it is allowed
 * automations (CHW-46, B11, AutomationPolicy): on or off, the reminder's
 * wait (SaveAutomation::DAYS_MIN to DAYS_MAX days) or the birthday's stamps
 * (STAMPS_MIN to STAMPS_MAX), and the owner's own title (beside the
 * business's name, CampaignText) and message, or neither for ours.
 */
class SaveAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('update', [Automation::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'days' => [$this->trigger() === AutomationTrigger::RewardReminder ? 'required' : 'prohibited', 'integer', 'between:'.SaveAutomation::DAYS_MIN.','.SaveAutomation::DAYS_MAX],
            'stamps' => [$this->trigger() === AutomationTrigger::Birthday ? 'required' : 'prohibited', 'integer', 'between:'.SaveAutomation::STAMPS_MIN.','.SaveAutomation::STAMPS_MAX],
            // Both always sent (the page sends the automation whole): left out is not "ours".
            'title' => ['present', 'nullable', 'string', 'max:'.CampaignText::titleRoom((string) $this->business()?->name)],
            'body' => ['present', 'nullable', 'string', 'max:'.CampaignText::BODY],
        ];
    }

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::from((string) $this->route('trigger'));
    }

    /**
     * What SaveAutomation takes: a blank title or message is ours (TrimStrings has trimmed them).
     *
     * @return array{active: bool, days?: int, stamps?: int, title: ?string, body: ?string}
     */
    public function values(): array
    {
        $text = fn (string $field): ?string => $this->filled($field) ? $this->string($field)->toString() : null;

        return [
            'active' => $this->boolean('active'),
            ...($this->has('days') ? ['days' => $this->integer('days')] : []),
            ...($this->has('stamps') ? ['stamps' => $this->integer('stamps')] : []),
            'title' => $text('title'),
            'body' => $text('body'),
        ];
    }
}

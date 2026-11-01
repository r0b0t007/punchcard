<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use App\Actions\Campaigns\CreateCampaign;
use App\Enums\CampaignSegment;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\LoyaltyCard;
use App\Support\Campaigns\CampaignText;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A campaign from whoever runs the current business (CHW-42, B9): the
 * composer's idempotency key (one campaign per composer, however often it is
 * posted), an audience, a title that fits beside the business's name and a message
 * (CampaignText), and optionally a card it opens: one of the organization's
 * that this business honours, never another's. Now, or later: a day and time
 * in the business's time (its first open site's) that exists that day (not in
 * a clock change's gap), from a minute ahead to the end of the
 * CreateCampaign::SCHEDULE_DAYS-th day.
 */
class StoreCampaignRequest extends FormRequest
{
    private ?Business $business = null;

    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('create', [Campaign::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'segment' => ['required', Rule::enum(CampaignSegment::class)],
            'title' => ['required', 'string', 'max:'.CampaignText::titleRoom((string) $this->business()?->name)],
            'body' => ['required', 'string', 'max:'.CampaignText::BODY],
            'when' => ['sometimes', 'in:now,later'],
            'send_on' => ['exclude_unless:when,later', 'required', 'date_format:Y-m-d'],
            'send_time' => [
                'exclude_unless:when,later', 'bail', 'required', 'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $at = $this->sendAt();

                    // A day it can't read: the day's own rule says so.
                    if (! $at instanceof CarbonImmutable) {
                        return;
                    }

                    if ($at->setTimezone($this->timezone())->format('Y-m-d H:i') !== $this->string('send_on')->toString().' '.$value) {
                        $fail(__('That time doesn’t exist that day: the clocks change.'));
                    } elseif ($at->lessThan(now()->addMinute()) || ! $at->lessThan(CreateCampaign::latestSendAt($this->timezone(), now()))) {
                        $fail(__('Choose a time from now to :days days ahead.', ['days' => CreateCampaign::SCHEDULE_DAYS]));
                    }
                },
            ],
            'card_id' => [
                'bail', 'nullable', 'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $honoured = LoyaltyCard::query()->whereKey($value)->honouredBy((int) $this->business()?->id)->exists();

                    if (! $honoured) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
        ];
    }

    /** The business worked in (TenantContext), which the campaign is from. */
    public function business(): ?Business
    {
        return $this->business ??= app(TenantContext::class)->business();
    }

    public function audience(): CampaignSegment
    {
        return CampaignSegment::from($this->string('segment')->toString());
    }

    public function cardId(): ?int
    {
        return $this->filled('card_id') ? $this->integer('card_id') : null;
    }

    /** Later: the day and time asked, read in the business's time, as UTC; now, or a day or time it can't read: null. */
    public function sendAt(): ?CarbonImmutable
    {
        if ($this->input('when') !== 'later') {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('send_on')->toString().' '.$this->string('send_time')->toString(), $this->timezone());
        } catch (InvalidFormatException) {
            return null;
        }

        return $at instanceof CarbonImmutable ? $at->utc() : null;
    }

    /** The business's time: its first open site's. */
    private function timezone(): string
    {
        return LocalMoment::timezoneOf($this->business()?->firstLocation()->first());
    }
}

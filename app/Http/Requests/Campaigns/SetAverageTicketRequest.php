<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use App\Models\Business;
use App\Models\Campaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * The business's average ticket (CHW-48, B10), from whoever runs it: in
 * centimes, as the page turns the dirhams typed into them (lib/money.ts),
 * from 1 to 10,000 dirhams.
 */
class SetAverageTicketRequest extends FormRequest
{
    public const int MIN = 100;

    public const int MAX = 1_000_000;

    /** The business worked in, read once for the authorization and the controller. */
    private ?Business $business = null;

    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && Gate::allows('setTicket', [Campaign::class, $business]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'average_ticket' => ['required', 'integer', 'between:'.self::MIN.','.self::MAX],
        ];
    }

    /**
     * In dirhams, as the owner typed it, never the centimes sent.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $dirhams = fn (int $centimes): string => (string) Number::format($centimes / 100, locale: app()->getLocale());
        $range = __('Your average ticket is between :min and :max dirhams.', ['min' => $dirhams(self::MIN), 'max' => $dirhams(self::MAX)]);

        return ['average_ticket.required' => $range, 'average_ticket.integer' => $range, 'average_ticket.between' => $range];
    }

    public function business(): ?Business
    {
        return $this->business ??= app(TenantContext::class)->business();
    }
}

<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * What every sign-up form asks (CHW-23, CNDP): that they are 16 or older
 * (required: no account otherwise), and news by email, only if ticked.
 * RecordConsent dates both. An unticked box says what it asks, never the
 * field's name.
 */
trait ConsentRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function consentRules(): array
    {
        return [
            'adult' => ['accepted'],
            'marketing' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function consentMessages(): array
    {
        return ['adult.accepted' => __('Confirm you\'re 16 or older to create an account.')];
    }
}

<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * What every sign-up form asks (CHW-23, CNDP): that they are 16 or older
 * (required: no account otherwise), and news by email, only if ticked.
 * RecordConsent dates both.
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
}

<?php

declare(strict_types=1);

namespace App\Enums;

/** Who pays: one invoice for the organization, or each business for itself (ADR 0006). */
enum BillingEntity: string
{
    case Organization = 'organization';
    case Business = 'business';
}

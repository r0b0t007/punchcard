<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The tabs of a business's customers (CHW-44, B4), over who came in with a
 * presence stamp there (BusinessCustomer). Thresholds in
 * config('punchcard.customers').
 */
enum CustomerSegment: string
{
    /** Everyone who came in. */
    case All = 'all';

    /** Enough visits, still coming: neither quiet nor lost. */
    case Regulars = 'regulars';

    /** Late for their usual visit, not lost yet. */
    case Quiet = 'quiet';

    /** No visit in lost_days days. */
    case Lost = 'lost';

    /** A first visit within new_days days. */
    case New = 'new';
}

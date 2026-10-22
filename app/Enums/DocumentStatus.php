<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a verification document stands (CHW-145): sent, then the platform's decision. */
enum DocumentStatus: string
{
    /** Sent by the business, waiting for the platform admin. */
    case Submitted = 'submitted';

    /** The business was verified with it. */
    case Accepted = 'accepted';

    /** The platform admin asked for a new one, with a reason. */
    case Rejected = 'rejected';
}

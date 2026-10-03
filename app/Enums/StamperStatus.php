<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A business pausing its stamper: a disabled one rejects every tap and keeps
 * the tag's assignment. A lost or stolen tag is retired on nfc_tags instead,
 * and replacing a tag ends the assignment (stampers.unassigned_at).
 */
enum StamperStatus: string
{
    case Active = 'active';

    case Disabled = 'disabled';
}

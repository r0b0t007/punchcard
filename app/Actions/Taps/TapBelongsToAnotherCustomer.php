<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use RuntimeException;

/**
 * ApplyTap refused a tap received or claimed by another customer (CHW-25).
 * Its own class, so a claim skips exactly this and lets real errors surface.
 */
final class TapBelongsToAnotherCustomer extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This tap was received or claimed by another customer.');
    }
}

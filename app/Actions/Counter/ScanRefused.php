<?php

declare(strict_types=1);

namespace App\Actions\Counter;

use RuntimeException;

/** A scan that can't stamp or redeem now, and why (StampScan, RedeemScan, CHW-30). */
final class ScanRefused extends RuntimeException
{
    public function __construct(public readonly ScanRefusal $reason)
    {
        parent::__construct('The scan cannot be used: '.$reason->value.'.');
    }
}

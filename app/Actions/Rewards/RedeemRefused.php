<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\RedeemRefusal;
use RuntimeException;

/** A reward was not redeemed, or its redeem window not opened; nothing was written. */
final class RedeemRefused extends RuntimeException
{
    public function __construct(public readonly RedeemRefusal $refusal)
    {
        parent::__construct("Redeem refused: {$refusal->value}.");
    }
}

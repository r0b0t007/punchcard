<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a Wallet pass lives (CHW-38, CHW-40). */
enum WalletPlatform: string
{
    case Apple = 'apple';
    case Google = 'google';
}

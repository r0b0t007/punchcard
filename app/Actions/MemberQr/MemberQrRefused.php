<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use RuntimeException;

/** A scanned member QR that does not count, and why (VerifyMemberQr, CHW-28). */
final class MemberQrRefused extends RuntimeException
{
    public function __construct(public readonly MemberQrRefusal $reason)
    {
        parent::__construct('The member QR does not count: '.$reason->value.'.');
    }
}

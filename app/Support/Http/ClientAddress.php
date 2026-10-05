<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Who a request comes from, for rate limits (the tap endpoint, CHW-25).
 */
final class ClientAddress
{
    /**
     * The rate limit key for a client address. An IPv6 client is its /64 (one
     * device can rotate through a whole /64); an IPv4 client is its address,
     * also when a dual-stack socket reports it as ::ffff:a.b.c.d.
     */
    public static function rateLimitKey(?string $ip): string
    {
        $packed = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;

        if ($packed === false) {
            return 'ip:'.($ip ?? 'unknown');
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return 'ip:'.inet_ntop(substr($packed, 12));
        }

        return strlen($packed) === 16 ? 'ip6:'.bin2hex(substr($packed, 0, 8)) : 'ip:'.$ip;
    }
}

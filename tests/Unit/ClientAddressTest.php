<?php

declare(strict_types=1);

use App\Support\Http\ClientAddress;

it('keys a client address for rate limits', function (?string $ip, string $key): void {
    expect(ClientAddress::rateLimitKey($ip))->toBe($key);
})->with([
    'IPv4' => ['203.0.113.7', 'ip:203.0.113.7'],
    'IPv4 on a dual-stack socket' => ['::ffff:203.0.113.7', 'ip:203.0.113.7'],
    'IPv6, by its /64' => ['2001:db8:1:2:abcd::3', 'ip6:20010db800010002'],
    'another address in the same /64' => ['2001:db8:1:2::1', 'ip6:20010db800010002'],
    'no address' => [null, 'ip:unknown'],
    'not an address' => ['nonsense', 'ip:nonsense'],
]);

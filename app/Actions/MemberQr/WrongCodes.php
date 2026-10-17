<?php

declare(strict_types=1);

namespace App\Actions\MemberQr;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Counts wrong member QR codes (Forged) under a key, against guessing
 * (CHW-30): the attempt is counted before it is checked, so a burst can't
 * pass the limit at once, and given back unless it was a wrong code. A late,
 * replayed, malformed or right code costs nothing. Past the limit within the
 * decay, every attempt gets the refusal given, until the count runs out.
 */
final class WrongCodes
{
    /**
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @param  Closure(): Throwable  $tooMany
     * @return T
     *
     * @throws MemberQrRefused
     */
    public static function counted(string $key, int $limit, int $decaySeconds, Closure $attempt, Closure $tooMany): mixed
    {
        if (RateLimiter::hit($key, $decaySeconds) > $limit) {
            throw $tooMany();
        }

        try {
            $result = $attempt();
        } catch (MemberQrRefused $refused) {
            if ($refused->reason !== MemberQrRefusal::Forged) {
                RateLimiter::decrement($key, $decaySeconds);
            }

            throw $refused;
        }

        RateLimiter::decrement($key, $decaySeconds);

        return $result;
    }
}

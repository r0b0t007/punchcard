<?php

declare(strict_types=1);

namespace App\Support\Auth;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * The links mailed to an inbox (CHW-23, CHW-146: sign-in and confirm links,
 * a new address, a staff invitation) and the address they go to.
 */
final class MailedLink
{
    /**
     * A route's URL, signed until a time. Always on the app's own address
     * (app.url), never the request's host, which a client can name: a link
     * mailed to someone else's host would hand them its signature. The
     * signature leaves the host out (checked with hasValidSignature(absolute:
     * false)), so it also works behind a proxy.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function signed(string $route, DateTimeInterface $expires, array $parameters): string
    {
        return self::onTheApp(URL::temporarySignedRoute($route, $expires, $parameters, absolute: false));
    }

    /** A path and query on the app's own address (app.url). */
    public static function onTheApp(string $pathAndQuery): string
    {
        return rtrim((string) config('app.url'), '/').$pathAndQuery;
    }

    /** An address, half hidden, for a page anyone with the link may see: s***@example.com. */
    public static function maskedEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}

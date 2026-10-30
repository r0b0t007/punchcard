<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * The links mailed to an inbox (CHW-23, CHW-146: sign-in and confirm links,
 * a new address, a staff invitation) and the address they go to.
 */
final class MailedLink
{
    /** The business pages a business's email opens (toBusiness), by the name in the link. */
    public const array BUSINESS_PAGES = ['verification' => 'verification.show', 'stampers' => 'stampers.index'];

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

    /**
     * A route's URL, signed with no end, as signed() is otherwise: for a link
     * that only opens what its user may open anyway, checked each time
     * (CHW-295, a business's emails). The signature keeps another site from
     * sending the user to it.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function signedForGood(string $route, array $parameters): string
    {
        return self::onTheApp(URL::signedRoute($route, $parameters, absolute: false));
    }

    /**
     * The link a business's email carries to one of its pages (CHW-295, OpenBusinessController): signed for good, for
     * the user it is mailed to alone. It names the business, whichever one their session has selected. Only ever
     * mailed to a user: a link to the page itself would open whichever business is selected, so anyone else is a bug.
     *
     * @param  key-of<self::BUSINESS_PAGES>  $page
     */
    public static function toBusiness(int $businessId, string $page, object $for): string
    {
        if (! $for instanceof User) {
            throw new LogicException('A business\'s email links to it for the user it is mailed to.');
        }

        return self::signedForGood('business.open', ['business' => $businessId, 'page' => $page, 'for' => $for->id]);
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

<?php

declare(strict_types=1);

namespace App\Support\Referrals;

/**
 * An address as its inbox sees it (CHW-47): lower case, without a "+tag",
 * and for Gmail without dots, its two domains as one. Two accounts whose
 * addresses read the same here share an inbox, so a referrer's own aliases
 * are never their friends.
 */
final class CanonicalEmail
{
    private const array GMAIL = ['gmail.com', 'googlemail.com'];

    public static function of(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plus = strpos($local, '+');
        $local = $plus === false ? $local : substr($local, 0, $plus);

        if (in_array($domain, self::GMAIL, true)) {
            return str_replace('.', '', $local).'@gmail.com';
        }

        return $local.'@'.$domain;
    }
}

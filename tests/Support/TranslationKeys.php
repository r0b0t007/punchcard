<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Collects the translation keys the app uses, so tests can check lang/*.json.
 *
 * Keys are English source strings. They come from:
 * - t('…') and tn('…') calls in resources/js (single- or multi-line);
 * - English strings kept in data and translated where rendered
 *   (`title:`, `description:`, `label:`, `buttonText:` in resources/js, and
 *   error messages handed to AlertError);
 * - __(), @lang(), trans(), trans_choice(), Lang::get()/choice() in app/ and
 *   resources/views;
 * - strings that vendor code translates for our users (VENDOR_KEYS).
 *
 * Keys built at runtime (t(variable), t(`template`)) cannot be found statically:
 * keep keys literal, or list them where they are defined as data.
 */
final class TranslationKeys
{
    /** Fortify, passkeys (server and @laravel/passkeys client) and Laravel's auth emails. */
    public const array VENDOR_KEYS = [
        'The provided password was incorrect.',
        'The provided two factor authentication code was invalid.',
        'The provided two factor recovery code was invalid.',
        'Invalid credential format.',
        'Passkey registration session expired. Please try again.',
        'Passkey verification session expired. Please try again.',
        'An unknown error occurred.',
        'The passkey operation was cancelled.',
        'This device is already registered as a passkey.',
        'Reset your password',
        'You are receiving this email because we received a password reset request for your account.',
        'Reset Password',
        'This password reset link will expire in :count minutes.',
        'If you did not request a password reset, no further action is required.',
        'Verify your email address',
        'Please click the button below to verify your email address.',
        'Verify Email Address',
        'If you did not create an account, no further action is required.',
        'Hello!',
        'Whoops!',
        'Regards,',
        "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:",
        'All rights reserved.',
    ];

    private const string STRING = '(?:\'((?:\\\\.|[^\'\\\\])*)\'|"((?:\\\\.|[^"\\\\])*)")';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [...self::frontend(), ...self::backend(), ...self::VENDOR_KEYS];
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function frontend(): array
    {
        // shadcn primitives (components/ui) stay in: their sr-only labels go through t() too.
        $files = Finder::create()
            ->files()
            ->in(resource_path('js'))
            ->name(['*.ts', '*.tsx'])
            ->notName(['*.test.ts', 'i18n.ts', 'use-translation.ts'])
            ->exclude(['actions', 'routes', 'wayfinder']);

        $patterns = [
            '/\btn?\(\s*'.self::STRING.'/s',
            '/\b(?:title|description|label|buttonText)\s*:\s*'.self::STRING.'/',
            '/\bsetErrors?\(\s*(?:\(?[^)]*\)?\s*=>\s*)?\[?[^\'"\]]*'.self::STRING.'/',
        ];

        $keys = [];

        foreach ($files as $file) {
            $content = $file->getContents();

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $keys[] = self::unescapeJs(self::quoted($match));
                }
            }
        }

        return array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
    }

    /**
     * @return list<string>
     */
    public static function backend(): array
    {
        $files = Finder::create()
            ->files()
            ->in([app_path(), resource_path('views')])
            ->name('*.php');

        $keys = [];

        foreach ($files as $file) {
            preg_match_all('/(?:(?<![\w>:$])(?:__|trans|trans_choice)|@lang|Lang::(?:get|choice))\(\s*'.self::STRING.'/', $file->getContents(), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = stripcslashes(self::quoted($match));

                // Dotted keys (validation.required, passwords.sent) live in lang/{locale}/*.php.
                if ($key !== '' && preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) !== 1) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * The body of whichever quote style STRING matched (single or double).
     *
     * @param  array<int, string>  $match
     */
    private static function quoted(array $match): string
    {
        return $match[1] !== '' ? $match[1] : ($match[2] ?? '');
    }

    private static function unescapeJs(string $value): string
    {
        return strtr($value, ['\\\'' => '\'', '\\"' => '"', '\\\\' => '\\', '\\n' => "\n"]);
    }
}

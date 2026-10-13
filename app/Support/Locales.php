<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read-only view of the supported UI locales in config/punchcard.php.
 */
final class Locales
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::names());
    }

    /**
     * @return array<string, string> code => native name, in switcher order
     */
    public static function names(): array
    {
        /** @var array<string, string> */
        return config('punchcard.locales');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::codes(), true);
    }

    public static function default(): string
    {
        /** @var string */
        return config('punchcard.default_locale');
    }

    /**
     * @return 'ltr'|'rtl'
     */
    public static function direction(string $locale): string
    {
        /** @var list<string> $rtl */
        $rtl = config('punchcard.rtl_locales');

        return in_array($locale, $rtl, true) ? 'rtl' : 'ltr';
    }

    /**
     * A few strings in every locale, with its direction: for a page that
     * picks its language on the device (the offline page, CHW-35).
     *
     * @param  list<string>  $keys
     * @return array<string, array{dir: 'ltr'|'rtl', strings: array<string, string>}>
     */
    public static function phrasebook(array $keys): array
    {
        $phrasebook = [];

        foreach (self::codes() as $locale) {
            $strings = [];

            foreach ($keys as $key) {
                $strings[$key] = __($key, locale: $locale);
            }

            $phrasebook[$locale] = ['dir' => self::direction($locale), 'strings' => $strings];
        }

        return $phrasebook;
    }

    /**
     * The JSON translations for one locale (English source string => translation).
     *
     * @return array<string, string>
     */
    public static function translations(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! self::isSupported($locale) || ! is_file($path)) {
            return [];
        }

        /** @var array<string, string> */
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}

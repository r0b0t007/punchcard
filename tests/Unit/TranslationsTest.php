<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Tests\Support\TranslationKeys;

/*
|--------------------------------------------------------------------------
| Translations stay complete
|--------------------------------------------------------------------------
|
| Every English key the app shows (t() in React, __() in PHP, vendor strings
| we surface) must exist in lang/fr.json and lang/ar.json, keep the same
| :placeholders, and no stale keys may linger. lang/en.json stays empty:
| English is the key itself.
|
*/

/**
 * @return array<string, string>
 */
function jsonTranslations(string $locale): array
{
    /** @var array<string, string> */
    return json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function placeholdersIn(string $text): array
{
    preg_match_all('/:([a-zA-Z_]+)/', $text, $matches);
    $names = array_values(array_unique(array_map(strtolower(...), $matches[1])));
    sort($names);

    return $names;
}

dataset('translated locales', ['fr', 'ar']);

it('translates every key the app uses', function (string $locale): void {
    $missing = array_values(array_diff(TranslationKeys::all(), array_keys(jsonTranslations($locale))));

    expect($missing)->toBe([]);
})->with('translated locales');

it('has no stale keys', function (string $locale): void {
    $stale = array_values(array_diff(array_keys(jsonTranslations($locale)), TranslationKeys::all()));

    expect($stale)->toBe([]);
})->with('translated locales');

it('keeps the placeholders of each key', function (string $locale): void {
    $mismatched = [];

    foreach (jsonTranslations($locale) as $key => $translation) {
        if (placeholdersIn($key) !== placeholdersIn($translation)) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
})->with('translated locales');

it('has no empty translations', function (string $locale): void {
    expect(array_keys(array_filter(jsonTranslations($locale), fn (string $value): bool => trim($value) === '')))->toBe([]);
})->with('translated locales');

it('keeps English as the key', function (): void {
    expect(jsonTranslations('en'))->toBe([]);
});

it('defines the same PHP message keys for French and Arabic', function (string $file): void {
    $fr = Arr::dot(require lang_path("fr/{$file}.php"));
    $ar = Arr::dot(require lang_path("ar/{$file}.php"));

    expect(array_keys($ar))->toBe(array_keys($fr));

    foreach ($fr as $key => $message) {
        expect(placeholdersIn($ar[$key]))->toBe(placeholdersIn($message), "{$file}.{$key}");
    }
})->with(['auth', 'passwords', 'validation']);

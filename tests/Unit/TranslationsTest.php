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

dataset('translated locales', ['fr', 'ar']);

it('translates every key the app uses', function (string $locale): void {
    $missing = array_values(array_diff(TranslationKeys::all(), array_keys(TranslationKeys::json($locale))));

    expect($missing)->toBe([]);
})->with('translated locales');

it('has no stale keys', function (string $locale): void {
    $stale = array_values(array_diff(array_keys(TranslationKeys::json($locale)), TranslationKeys::all()));

    expect($stale)->toBe([]);
})->with('translated locales');

it('keeps the placeholders of each key', function (string $locale): void {
    $mismatched = [];

    foreach (TranslationKeys::json($locale) as $key => $translation) {
        if (TranslationKeys::placeholders($key) !== TranslationKeys::placeholders($translation)) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
})->with('translated locales');

it('has no empty translations', function (string $locale): void {
    expect(array_keys(array_filter(TranslationKeys::json($locale), fn (string $value): bool => trim($value) === '')))->toBe([]);
})->with('translated locales');

it('keeps English as the key', function (): void {
    expect(TranslationKeys::json('en'))->toBe([]);
});

it('defines the same PHP message keys for French and Arabic', function (string $file): void {
    $fr = Arr::dot(require lang_path("fr/{$file}.php"));
    $ar = Arr::dot(require lang_path("ar/{$file}.php"));

    expect(array_keys($ar))->toBe(array_keys($fr));

    foreach ($fr as $key => $message) {
        expect(TranslationKeys::placeholders($ar[$key]))->toBe(TranslationKeys::placeholders($message), "{$file}.{$key}");
    }
})->with(['auth', 'passwords', 'validation']);

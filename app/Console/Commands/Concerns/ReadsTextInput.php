<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * An argument's or option's text. Laravel types every input as any scalar or
 * an array; the commands here declare text ones only. A number, as code or a
 * test may pass one (artisan('...', ['--business' => 5])), reads as its
 * digits; a flag or a list reads as no text.
 */
trait ReadsTextInput
{
    /** A required argument's text. */
    protected function textArgument(string $name): string
    {
        return self::text($this->argument($name)) ?? '';
    }

    /** An option's text, or null when it was not given. */
    protected function textOption(string $name): ?string
    {
        return self::text($this->option($name));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}

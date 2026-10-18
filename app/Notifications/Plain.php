<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Someone's own text in a mail's Markdown (a name, a label): its brackets
 * escaped, and its backslashes first (\[ would turn an escape back off), the
 * one way it could become a link (no autolinks, and Blade
 * escapes <url> first), so nobody sends a link of their own from punchcard's
 * address. Ordinary text reads the same in the plain-text part.
 */
final class Plain
{
    public static function text(string $text): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }
}

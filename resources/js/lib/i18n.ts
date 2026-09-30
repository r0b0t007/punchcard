/**
 * Client-side counterpart of Laravel's JSON translations. Keys are the English
 * source strings (so a missing translation reads as English), placeholders use
 * `:name`, and plural strings use Laravel's `a|b` / `{0} …|[2,*] …` syntax, so the
 * same lang/*.json entry works with `__()`/`trans_choice()` on the server.
 */

export type Translations = Record<string, string>;
export type Replacements = Record<string, string | number>;

/**
 * Translate `key`, replace placeholders and, when `replacements.count` is a
 * number and the message has `|` segments, pick the plural form.
 */
export function translate(
    translations: Translations,
    locale: string,
    key: string,
    replacements: Replacements = {},
): string {
    let message = translations[key] ?? key;

    if (typeof replacements.count === 'number' && message.includes('|')) {
        message = choose(message, replacements.count, locale);
    }

    return replace(message, replacements);
}

/**
 * Port of Illuminate\Translation\MessageSelector::getPluralIndex for the
 * locales punchcard ships. Unknown locales use the English rule.
 */
export function pluralIndex(locale: string, count: number): number {
    const n = Math.abs(count);
    const mod100 = Math.trunc(n) % 100;

    switch (locale.split(/[-_]/)[0]) {
        case 'fr':
            return n === 0 || n === 1 ? 0 : 1;
        case 'ar':
            if (n === 0) {
                return 0;
            }

            if (n === 1) {
                return 1;
            }

            if (n === 2) {
                return 2;
            }

            if (mod100 >= 3 && mod100 <= 10) {
                return 3;
            }

            return mod100 >= 11 && mod100 <= 99 ? 4 : 5;
        default:
            return n === 1 ? 0 : 1;
    }
}

const CONDITION = /^[{[]([-?\d|*,.]*)[}\]]([\s\S]*)/;

/** Port of MessageSelector::choose. */
function choose(message: string, count: number, locale: string): string {
    const segments = message.split('|');

    for (const segment of segments) {
        const explicit = matchCondition(segment, count);

        if (explicit !== null) {
            return explicit.trim();
        }
    }

    const plain = segments.map((segment) =>
        segment.replace(/^[{[][-?\d|*,.]*[}\]]/, ''),
    );
    const index = pluralIndex(locale, count);

    return (
        plain.length === 1 || plain[index] === undefined
            ? plain[0]
            : plain[index]
    ).trim();
}

function matchCondition(segment: string, count: number): string | null {
    const match = CONDITION.exec(segment);

    if (!match) {
        return null;
    }

    const [, condition, value] = match;

    if (condition.includes(',')) {
        const [from, to] = condition.split(',', 2);

        if (to === '*' && count >= Number(from)) {
            return value;
        }

        if (from === '*' && count <= Number(to)) {
            return value;
        }

        if (count >= Number(from) && count <= Number(to)) {
            return value;
        }
    }

    return Number(condition) === count ? value : null;
}

/** Port of Translator::makeReplacements: `:name`, `:Name` and `:NAME`. */
function replace(message: string, replacements: Replacements): string {
    const pairs: [string, string][] = [];

    for (const [key, raw] of Object.entries(replacements)) {
        const value = String(raw);
        pairs.push(
            [`:${ucfirst(key)}`, ucfirst(value)],
            [`:${key.toUpperCase()}`, value.toUpperCase()],
            [`:${key}`, value],
        );
    }

    if (pairs.length === 0) {
        return message;
    }

    // Like PHP's strtr: longest placeholder first, single pass, no re-replacing.
    pairs.sort(([a], [b]) => b.length - a.length);
    const lookup = new Map(pairs);
    const pattern = new RegExp(
        pairs.map(([placeholder]) => escapeRegExp(placeholder)).join('|'),
        'g',
    );

    return message.replace(
        pattern,
        (placeholder) => lookup.get(placeholder) ?? placeholder,
    );
}

function ucfirst(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

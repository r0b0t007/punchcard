import { describe, expect, it } from 'vite-plus/test';
import {
    interpolate,
    pluralIndex,
    translate,
    translateNodes,
} from '@/lib/i18n';

describe('translate', () => {
    const fr = {
        Language: 'Langue',
        'Hello :name': 'Bonjour :name',
        ':count stamps left':
            '{0} Aucun tampon restant|{1} :count tampon restant|[2,*] :count tampons restants',
        'apple|apples': 'pomme|pommes',
    };

    it('returns the translation for the active locale', () => {
        expect(translate(fr, 'fr', 'Language')).toBe('Langue');
    });

    it('falls back to the key, which is the English source string', () => {
        expect(translate(fr, 'fr', 'Missing string')).toBe('Missing string');
        expect(translate({}, 'en', 'Hello :name', { name: 'Sara' })).toBe(
            'Hello Sara',
        );
    });

    it('replaces :placeholders like Laravel, including case variants', () => {
        expect(translate(fr, 'fr', 'Hello :name', { name: 'sara' })).toBe(
            'Bonjour sara',
        );
        expect(
            translate({}, 'en', ':Name / :NAME / :name', { name: 'sara' }),
        ).toBe('Sara / SARA / sara');
        expect(
            translate({}, 'en', ':user_name and :user', {
                user: 'A',
                user_name: 'B',
            }),
        ).toBe('B and A');
    });

    it('picks explicit {n} and [a,b] segments when count is given', () => {
        const key = ':count stamps left';
        expect(translate(fr, 'fr', key, { count: 0 })).toBe(
            'Aucun tampon restant',
        );
        expect(translate(fr, 'fr', key, { count: 1 })).toBe('1 tampon restant');
        expect(translate(fr, 'fr', key, { count: 7 })).toBe(
            '7 tampons restants',
        );
    });

    it('uses the plural rule when segments carry no condition', () => {
        expect(translate(fr, 'fr', 'apple|apples', { count: 0 })).toBe('pomme');
        expect(translate(fr, 'fr', 'apple|apples', { count: 2 })).toBe(
            'pommes',
        );
        expect(translate({}, 'en', 'apple|apples', { count: 0 })).toBe(
            'apples',
        );
        expect(translate({}, 'en', 'apple|apples', { count: 1 })).toBe('apple');
    });

    it('selects the Arabic plural forms', () => {
        const ar = {
            ':count stamps':
                'لا طوابع|طابع واحد|طابعان|:count طوابع|:count طابعًا|:count طابع',
        };
        const t = (count: number) =>
            translate(ar, 'ar', ':count stamps', { count });
        expect(t(0)).toBe('لا طوابع');
        expect(t(1)).toBe('طابع واحد');
        expect(t(2)).toBe('طابعان');
        expect(t(5)).toBe('5 طوابع');
        expect(t(11)).toBe('11 طابعًا');
        expect(t(100)).toBe('100 طابع');
    });

    it('does not treat a pipe as plural without a count', () => {
        expect(translate({}, 'en', 'A | B')).toBe('A | B');
    });
});

describe('pluralIndex', () => {
    it('matches Laravel MessageSelector for en, fr and ar', () => {
        expect([0, 1, 2, 5].map((n) => pluralIndex('en', n))).toEqual([
            1, 0, 1, 1,
        ]);
        expect([0, 1, 2, 5].map((n) => pluralIndex('fr', n))).toEqual([
            0, 0, 1, 1,
        ]);
        expect(
            [0, 1, 2, 3, 10, 11, 99, 100, 102, 103, 111].map((n) =>
                pluralIndex('ar', n),
            ),
        ).toEqual([0, 1, 2, 3, 3, 4, 4, 5, 5, 3, 4]);
    });
});

describe('interpolate', () => {
    const link = { type: 'link' };

    it('splits a message around non-string placeholders, keeping word order', () => {
        expect(interpolate('Or, return to :link', { link })).toEqual([
            'Or, return to ',
            link,
        ]);
        expect(interpolate('ارجع إلى :link الآن', { link })).toEqual([
            'ارجع إلى ',
            link,
            ' الآن',
        ]);
    });

    it('supports several placeholders and repeats', () => {
        const a = { id: 'a' };
        const b = { id: 'b' };
        expect(interpolate(':b then :a then :b', { a, b })).toEqual([
            b,
            ' then ',
            a,
            ' then ',
            b,
        ]);
    });

    it('does not match a placeholder that is a prefix of a longer word', () => {
        expect(interpolate(':linked and :link', { link })).toEqual([
            ':linked and ',
            link,
        ]);
    });

    it('returns the message unchanged when there are no nodes', () => {
        expect(interpolate('Plain text', {})).toEqual(['Plain text']);
    });
});

describe('translateNodes', () => {
    const button = { type: 'button' };

    it('splits on the raw translation, so user values never become nodes', () => {
        expect(
            translateNodes(
                {},
                'en',
                'Remove :name? :button',
                { button },
                {
                    name: ':button',
                },
            ),
        ).toEqual(['Remove :button? ', button]);
    });

    it('translates, pluralises and replaces text around the nodes', () => {
        const fr = {
            ':count codes left, :link':
                '{1} :count code restant, :link|[2,*] :count codes restants, :link',
        };
        expect(
            translateNodes(
                fr,
                'fr',
                ':count codes left, :link',
                { link: button },
                { count: 3 },
            ),
        ).toEqual(['3 codes restants, ', button]);
    });
});

import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import LanguageSwitcher from '@/components/language-switcher';
import LoyaltyCard from '@/components/loyalty-card';
import type { LoyaltyCardProps } from '@/components/loyalty-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

// Local-only design QA page (routes/web.php). Not translated on purpose.
const LOGO =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#fbf6ee"/><path d="M18 26h24v14a10 10 0 0 1-10 10h-4a10 10 0 0 1-10-10z" fill="#3b2a20"/><path d="M42 30h4a5 5 0 0 1 0 10h-4" fill="none" stroke="#3b2a20" stroke-width="4"/><path d="M26 14c0 4 4 4 4 8M34 14c0 4 4 4 4 8" fill="none" stroke="#c2563a" stroke-width="3" stroke-linecap="round"/></svg>',
    );

const CARDS: { caption: string; card: LoyaltyCardProps }[] = [
    {
        caption: 'Default espresso, dot, 4 of 10',
        card: {
            businessName: 'Café Hafa',
            cardName: 'Carte café',
            stampsRequired: 10,
            stampsCollected: 4,
            rewardText: 'Un thé à la menthe offert',
        },
    },
    {
        caption: 'Brand #0F4C81, star, complete',
        card: {
            businessName: 'Blue Bottle Tanger',
            stampsRequired: 8,
            stampsCollected: 8,
            stampStyle: 'star',
            brandColor: '#0F4C81',
            rewardText: 'Free flat white',
        },
    },
    {
        caption: 'Brand #F2A541 (saffron card), heart, 3 of 12',
        card: {
            businessName: 'Salon Yasmine',
            stampsRequired: 12,
            stampsCollected: 3,
            stampStyle: 'heart',
            brandColor: '#F2A541',
            rewardText: 'Brushing offert',
        },
    },
    {
        caption: 'Brand #FFFFFF, check, 15 of 20',
        card: {
            businessName: 'Le Barbier',
            stampsRequired: 20,
            stampsCollected: 15,
            stampStyle: 'check',
            brandColor: '#FFFFFF',
            rewardText: '−50 % sur la coupe',
        },
    },
    {
        caption: 'Brand #16A34A (mid-tone), ring, 33 of 50',
        card: {
            businessName: 'Jus Frais',
            stampsRequired: 50,
            stampsCollected: 33,
            stampStyle: 'ring',
            brandColor: '#16A34A',
            rewardText: 'Grand jus offert',
        },
    },
    {
        caption: 'Brand #7C2D12, logo stamps, 6 of 10',
        card: {
            businessName: 'Torréfaction Atlas',
            stampsRequired: 10,
            stampsCollected: 6,
            stampStyle: 'logo',
            logoUrl: LOGO,
            brandColor: '#7C2D12',
            rewardText: '250 g de café offerts',
        },
    },
    {
        caption: 'Arabic name, 5 stamps',
        card: {
            businessName: 'مقهى الياسمين',
            stampsRequired: 5,
            stampsCollected: 2,
            rewardText: 'قهوة مجانية',
        },
    },
];

export default function Components() {
    return (
        <>
            <Head title="Components" />
            <main className="mx-auto flex max-w-6xl flex-col gap-10 px-4 py-8">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="font-display text-3xl font-bold">
                        punchcard components
                    </h1>
                    <div className="flex flex-wrap gap-2">
                        <AppearanceTabs />
                        <LanguageSwitcher />
                    </div>
                </header>

                <section className="flex flex-col gap-4">
                    <h2 className="text-lg font-semibold">LoyaltyCard</h2>
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {CARDS.map(({ caption, card }) => (
                            <figure
                                key={caption}
                                className="flex flex-col gap-2"
                            >
                                <LoyaltyCard {...card} />
                                <figcaption className="text-sm text-muted-foreground">
                                    {caption}
                                </figcaption>
                            </figure>
                        ))}
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-lg font-semibold">Controls</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button>Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="destructive">Destructive</Button>
                        <Button size="lg">Large 56px</Button>
                    </div>
                    <Input className="max-w-sm" placeholder="Input 44px" />
                    <div className="flex flex-wrap gap-2">
                        <span className="rounded-full bg-stamp px-3 py-1 text-sm font-medium text-stamp-foreground">
                            2 rewards ready
                        </span>
                        <span className="rounded-full border border-success px-3 py-1 text-sm text-success">
                            Active
                        </span>
                        <span className="rounded-full border border-warning px-3 py-1 text-sm text-warning">
                            Going quiet
                        </span>
                        <span className="rounded-full border border-destructive px-3 py-1 text-sm text-destructive">
                            Lost
                        </span>
                    </div>
                </section>
            </main>
        </>
    );
}

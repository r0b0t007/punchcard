import { Head, router } from '@inertiajs/react';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { index, status } from '@/routes/cards';

type CardRow = {
    id: number;
    name: string;
    mode: 'cyclic' | 'progressive';
    modeLabel: string;
    rewardText: string;
    stampsRequired: number;
    active: boolean;
    brandColor: string | null;
    brandForeground: string | null;
    held: boolean | null;
    businesses: string[] | null;
    onlyRunningAt: string[] | null;
    forNewCustomers: boolean;
};

/**
 * B7: the organization's cards, running first. Whoever runs the program
 * publishes or pauses them; a franchisee's owner only sees the cards its
 * business honours (HQ manages them).
 */
export default function CardsIndex({
    cards,
    canManage,
}: {
    cards: CardRow[];
    canManage: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Cards')} />
            <div className="flex flex-col gap-4 p-4">
                {canManage ? null : (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Your head office manages these cards. Ask them to change one.',
                        )}
                    </p>
                )}

                {cards.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('No cards yet.')}
                    </p>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {cards.map((card) => (
                            <li key={card.id}>
                                <CardItem card={card} canManage={canManage} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function CardItem({ card, canManage }: { card: CardRow; canManage: boolean }) {
    const { t } = useTranslation();
    const setActive = (active: boolean) =>
        router.patch(status.url(card.id), { active }, { preserveScroll: true });

    return (
        <Card className="h-full">
            <CardHeader>
                <div className="flex items-start gap-3">
                    <BrandMonogram
                        name={card.name}
                        brandColor={card.brandColor}
                        brandForeground={card.brandForeground}
                        className="size-10 text-sm"
                    />
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle>
                            <span className="block truncate">{card.name}</span>
                        </CardTitle>
                        <CardDescription>
                            {card.mode === 'cyclic'
                                ? t(':reward every :count stamps', {
                                      reward: card.rewardText,
                                      count: card.stampsRequired,
                                  })
                                : t(':mode, up to :count stamps', {
                                      mode: card.modeLabel,
                                      count: card.stampsRequired,
                                  })}
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap gap-2">
                    <Badge variant={card.active ? 'default' : 'secondary'}>
                        {card.active ? t('Running') : t('Paused')}
                    </Badge>
                    {card.forNewCustomers ? (
                        <Badge variant="outline">
                            {t('New customers get this card')}
                        </Badge>
                    ) : null}
                    {card.held ? (
                        <Badge variant="outline">
                            {t('Customers hold it')}
                        </Badge>
                    ) : null}
                </div>
            </CardContent>
            {canManage ? (
                <CardFooter>
                    <div className="flex flex-col items-start gap-3">
                        {card.businesses && card.businesses.length > 0 ? (
                            <p className="text-xs text-muted-foreground">
                                {t('Honoured at :businesses', {
                                    businesses: card.businesses.join(', '),
                                })}
                            </p>
                        ) : null}
                        {card.onlyRunningAt && card.onlyRunningAt.length > 0 ? (
                            // Pausing the only card running somewhere stops taps there: ask first.
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="secondary" size="sm">
                                        {t('Pause')}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        {t(
                                            'Pause the only card running at :businesses?',
                                            {
                                                businesses:
                                                    card.onlyRunningAt.join(
                                                        ', ',
                                                    ),
                                            },
                                        )}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t(
                                            'Taps there will be refused and nobody can join until a card runs there again. Stamps already collected stay on it.',
                                        )}
                                    </DialogDescription>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                {t('Cancel')}
                                            </Button>
                                        </DialogClose>
                                        <DialogClose asChild>
                                            <Button
                                                variant="destructive"
                                                onClick={() => setActive(false)}
                                            >
                                                {t('Pause')}
                                            </Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        ) : (
                            <Button
                                variant={card.active ? 'secondary' : 'default'}
                                size="sm"
                                onClick={() => setActive(!card.active)}
                            >
                                {card.active ? t('Pause') : t('Publish')}
                            </Button>
                        )}
                    </div>
                </CardFooter>
            ) : null}
        </Card>
    );
}

CardsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cards',
            href: index(),
        },
    ],
};

import { Form, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import LanguageSwitcher from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
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
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { cancel, step as stepRoute } from '@/routes/onboarding';

export type OnboardingSteps = {
    all: string[];
    current: string;
};

/**
 * The onboarding wizard's frame (CHW-31, B2): where the owner is among the
 * steps (a step done or the current one can be reopened), the step's page,
 * and a way to cancel setup. Pages pass English `title`/`description` via
 * `Page.layout`, translated here.
 */
export default function OnboardingLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { t } = useTranslation();
    const { steps } = usePage<{ steps: OnboardingSteps }>().props;
    const reached = steps.all.indexOf(steps.current);
    const labels: Record<string, string> = {
        business: t('Your business'),
        location: t('Location'),
        logo: t('Logo'),
        card: t('Card'),
        shipping: t('Stamper kit'),
    };

    return (
        <div className="flex min-h-svh flex-col items-center bg-background p-6 md:p-10">
            <div className="flex w-full max-w-lg flex-col gap-8">
                <div className="flex items-center justify-between gap-4">
                    <Link href={home()} aria-label={t('Home')}>
                        <AppLogoIcon className="size-8 fill-current text-foreground" />
                    </Link>
                    <div className="flex items-center gap-2">
                        <LanguageSwitcher />
                        <CancelSetup />
                    </div>
                </div>

                <nav aria-label={t('Setup steps')}>
                    <ol className="flex gap-2">
                        {steps.all.map((name, index) => {
                            const open = index <= reached;
                            const label = labels[name] ?? name;

                            return (
                                <li key={name} className="flex-1">
                                    <div
                                        className={cn(
                                            'mb-2 h-1 rounded-full',
                                            index < reached
                                                ? 'bg-primary'
                                                : index === reached
                                                  ? 'bg-primary/60'
                                                  : 'bg-muted',
                                        )}
                                    />
                                    {open ? (
                                        <Link
                                            href={stepRoute(name)}
                                            className="text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            {label}
                                        </Link>
                                    ) : (
                                        <span className="text-xs text-muted-foreground/60">
                                            {label}
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                <div className="space-y-2">
                    <h1 className="text-xl font-medium">{title && t(title)}</h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {t(description)}
                        </p>
                    )}
                </div>

                {children}
            </div>
        </div>
    );
}

function CancelSetup() {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    {t('Cancel setup')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Cancel setup?')}</DialogTitle>
                <DialogDescription>
                    {t(
                        'The business you are setting up is closed. Your account stays, and you can start again at any time.',
                    )}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">{t('Keep going')}</Button>
                    </DialogClose>
                    <Form {...cancel.form()}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {t('Cancel setup')}
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

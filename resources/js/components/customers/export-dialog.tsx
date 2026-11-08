import { useForm } from '@inertiajs/react';
import { Download, MailCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { exportMethod } from '@/routes/customers';

/** A day as the date input takes it, so many days before another. */
function daysBefore(day: string, days: number): string {
    const date = new Date(`${day}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
}

/**
 * Export on the Customers page (CHW-49, B4, design 21): the owner picks the
 * days (the last 90 to start, up to :years years) and gets an email with a
 * link to customers.csv and stamps.csv. Closes once asked; the page then
 * says where the link goes.
 */
export default function ExportDialog({
    today,
    maxYears,
}: {
    /** Today in the business's time (Y-m-d). */
    today: string;
    maxYears: number;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const form = useForm({ from: daysBefore(today, 89), to: today });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(exportMethod.url(), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" data-test="customers-export">
                    <Download aria-hidden="true" />
                    {t('Export')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1">
                        <DialogTitle>
                            {t('Export customers and stamps')}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                "We'll email you a link to two spreadsheet files.",
                            )}
                        </DialogDescription>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="export-from">{t('From')}</Label>
                            <Input
                                id="export-from"
                                type="date"
                                value={form.data.from}
                                max={today}
                                onChange={(event) =>
                                    form.setData('from', event.target.value)
                                }
                                required
                                data-test="export-from"
                            />
                            <InputError message={form.errors.from} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="export-to">{t('To')}</Label>
                            <Input
                                id="export-to"
                                type="date"
                                value={form.data.to}
                                max={today}
                                onChange={(event) =>
                                    form.setData('to', event.target.value)
                                }
                                required
                                data-test="export-to"
                            />
                            <InputError message={form.errors.to} />
                        </div>
                    </div>
                    <ul className="flex list-disc flex-col gap-1 ps-5 text-sm">
                        <li>
                            {t(
                                'customers.csv: the customers who came in during these dates, as on this page',
                            )}
                        </li>
                        <li>
                            {t(
                                'stamps.csv: every stamp given here during these dates',
                            )}
                        </li>
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'First name and initial only, never an email. Up to :count years at a time. The link works for 24 hours, signed in as you.',
                            { count: maxYears },
                        )}
                    </p>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="export-send"
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <MailCheck aria-hidden="true" />
                            )}
                            {t('Email me the export')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

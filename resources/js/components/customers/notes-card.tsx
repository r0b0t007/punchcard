import { Link, useForm } from '@inertiajs/react';
import { StickyNote, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { destroy, store } from '@/routes/customers/notes';

export type CustomerNote = {
    id: number;
    body: string;
    /** Who wrote it, by first name and initial; null once their account is gone. */
    by: string | null;
    at: LocalMoment;
};

/**
 * The team's notes on a customer (CHW-44, B5, design 15c), newest first:
 * write one, or delete one with the bin. The customer never sees them here;
 * their data export has them.
 */
export default function NotesCard({
    customer,
    notes,
    maxLength,
}: {
    customer: { id: number; name: string };
    notes: CustomerNote[];
    maxLength: number;
}) {
    const { t, tn, locale } = useTranslation();
    const form = useForm({ body: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(customer.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <section
            aria-labelledby="notes"
            className="flex flex-col gap-3 rounded-card border bg-card px-4 py-3.5"
        >
            <div className="flex items-center gap-2">
                <StickyNote aria-hidden="true" className="size-4" />
                <h2 id="notes" className="font-semibold">
                    {t('Notes')}
                </h2>
            </div>
            <p className="text-xs text-muted-foreground">
                {tn(
                    'For your team only. :name never sees them; they can ask for them like any data about them.',
                    { name: <bdi>{customer.name}</bdi> },
                )}
            </p>
            <form onSubmit={submit} className="flex flex-col gap-2">
                <Textarea
                    aria-label={t('Write a note…')}
                    placeholder={t('Write a note…')}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    maxLength={maxLength}
                    required
                    data-test="customer-note-body"
                />
                <InputError message={form.errors.body} />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    className="self-end"
                    disabled={form.processing}
                    data-test="customer-note-save"
                >
                    {form.processing && <Spinner />}
                    {t('Save note')}
                </Button>
            </form>
            {notes.length > 0 ? (
                <ul className="divide-y border-t">
                    {notes.map((note) => (
                        <li
                            key={note.id}
                            className="flex gap-2 py-2.5"
                            data-test="customer-note"
                        >
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <p className="text-sm break-words whitespace-pre-line">
                                    <bdi>{note.body}</bdi>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {note.by ? (
                                        <>
                                            <bdi>{note.by}</bdi>
                                            {' · '}
                                        </>
                                    ) : null}
                                    {momentLabel(locale, note.at, {
                                        today: (time) =>
                                            t('Today at :time', { time }),
                                        other: (date, time) =>
                                            t(':date at :time', { date, time }),
                                    })}
                                </p>
                            </div>
                            <Button
                                variant="ghost-destructive"
                                size="icon"
                                asChild
                            >
                                <Link
                                    href={destroy([customer.id, note.id])}
                                    as="button"
                                    preserveScroll
                                    aria-label={t('Delete note')}
                                    title={t('Delete note')}
                                    data-test="customer-note-delete"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Link>
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

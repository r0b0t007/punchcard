import { Head, router, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CircleAlert,
    FileText,
    Hourglass,
    IdCard,
    Lock,
    Upload,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { localDate } from '@/lib/local-moment';
import { cn } from '@/lib/utils';
import { show, store } from '@/routes/verification';

type Kind = 'id_document' | 'proof_of_address';

type Document = {
    kind: Kind;
    /** The latest of this kind: null when none was sent. */
    status: 'submitted' | 'accepted' | 'rejected' | null;
    sentAt: string | null;
    /** The platform's words, when it asked for a new one. */
    reason: string | null;
    /** When the file is deleted, once the platform decided. */
    deletesAt: string | null;
};

/**
 * Verify your business (CHW-145, design 9a in Punchcard Verification
 * Review): one card with a row per document, its status and one button;
 * the state above it (waiting, new ones needed with the platform's words,
 * verified). The files are never shown back.
 */
export default function Verification({
    verified,
    canSend,
    documents,
}: {
    verified: boolean;
    canSend: boolean;
    documents: Document[];
}) {
    const { t, locale } = useTranslation();
    const rejected = documents.filter((d) => d.status === 'rejected');
    const allSent = documents.every(
        (d) => d.status === 'submitted' || d.status === 'accepted',
    );
    const deletesAt = documents
        .map((d) => d.deletesAt)
        .filter((at): at is string => at !== null)
        .sort()[0];

    return (
        <>
            <Head title={t('Verify your business')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <header className="space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('Verify your business')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "Send us an ID of the owner and a proof of the business's address. We check them and mark your business verified. Your business works meanwhile.",
                        )}
                    </p>
                </header>

                {verified ? (
                    <StateBox
                        tone="success"
                        icon={BadgeCheck}
                        title={t('Your business is verified')}
                    >
                        {deletesAt
                            ? t('We delete the files on :date.', {
                                  date: localDate(locale, deletesAt, {
                                      day: 'numeric',
                                      month: 'long',
                                      year: 'numeric',
                                  }),
                              })
                            : null}
                    </StateBox>
                ) : rejected.length > 0 ? (
                    <StateBox
                        tone="warning"
                        icon={CircleAlert}
                        title={t('We need new documents')}
                    >
                        {t('Send the one marked below again.')}
                        {rejected.map((d) =>
                            d.reason ? (
                                <q
                                    key={d.kind}
                                    className="mt-1.5 block border-s-2 border-warning/60 ps-3 italic"
                                >
                                    {d.reason}
                                </q>
                            ) : null,
                        )}
                    </StateBox>
                ) : allSent ? (
                    <StateBox
                        tone="neutral"
                        icon={Hourglass}
                        title={t("We're checking your documents")}
                    >
                        {t("We'll email you if we need anything else.")}
                    </StateBox>
                ) : null}

                <ul className="divide-y rounded-card border bg-card">
                    {documents.map((document) => (
                        <DocumentRow
                            key={document.kind}
                            document={document}
                            canSend={canSend}
                        />
                    ))}
                </ul>

                <p className="flex items-start gap-2 text-xs text-muted-foreground">
                    <Lock className="mt-0.5 size-4 shrink-0" aria-hidden />
                    {t(
                        "Only punchcard's team sees these files, and every look is recorded. We delete them 3 months after we decide.",
                    )}
                </p>
            </div>
        </>
    );
}

const tones = {
    neutral: 'border-border bg-card',
    warning: 'border-warning/50 bg-warning/5',
    success: 'border-success/50 bg-success/5',
} as const;

function StateBox({
    tone,
    icon: Icon,
    title,
    children,
}: {
    tone: keyof typeof tones;
    icon: LucideIcon;
    title: string;
    children: ReactNode;
}) {
    return (
        <div
            role="status"
            className={cn(
                'flex items-start gap-3 rounded-card border px-4 py-3.5',
                tones[tone],
            )}
        >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden />
            <div className="flex min-w-0 flex-col gap-0.5">
                <p className="font-semibold">{title}</p>
                <div className="text-sm text-muted-foreground">{children}</div>
            </div>
        </div>
    );
}

function DocumentRow({
    document,
    canSend,
}: {
    document: Document;
    canSend: boolean;
}) {
    const { t, locale } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const input = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState(false);
    const [tried, setTried] = useState(false);
    const id = document.kind === 'id_document';
    const Icon = id ? IdCard : FileText;
    const status = {
        null: {
            label: t('Not sent'),
            className: 'border-border text-muted-foreground',
        },
        submitted: {
            label: t('Sent on :date', {
                date: document.sentAt
                    ? localDate(locale, document.sentAt, {
                          day: 'numeric',
                          month: 'short',
                      })
                    : '',
            }),
            className: 'border-border text-foreground',
        },
        rejected: {
            label: t('New one needed'),
            className: 'border-warning text-warning',
        },
        accepted: {
            label: t('Accepted'),
            className: 'border-success text-success',
        },
    }[document.status ?? 'null'];
    const toSend = document.status === null || document.status === 'rejected';

    const send = (file: File) => {
        setTried(true);
        router.post(
            store(document.kind).url,
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => {
                    setSending(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <li className="flex flex-wrap items-center gap-x-3.5 gap-y-3 p-4">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-secondary">
                <Icon className="size-5" aria-hidden />
            </span>
            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                <p className="font-semibold">
                    {id ? t('ID card or passport') : t('Proof of address')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {id
                        ? t("The owner's, both sides on one photo or PDF.")
                        : t(
                              "A bill or statement from the last 3 months, with the business's address.",
                          )}{' '}
                    {t('JPG, PNG or PDF, up to 10 MB.')}
                </p>
                {tried ? <InputError message={errors.file} /> : null}
            </div>
            <span
                className={cn(
                    'inline-flex h-6 shrink-0 items-center rounded-full border px-2.5 text-xs font-semibold whitespace-nowrap',
                    status.className,
                )}
                data-test={`document-status-${document.kind}`}
            >
                {status.label}
            </span>
            {canSend && document.status !== 'accepted' ? (
                <>
                    <input
                        ref={input}
                        type="file"
                        accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        className="sr-only"
                        tabIndex={-1}
                        aria-hidden
                        onChange={(event) => {
                            const file = event.target.files?.[0];

                            if (file) {
                                send(file);
                            }
                        }}
                        data-test={`document-input-${document.kind}`}
                    />
                    <Button
                        variant={toSend ? 'default' : 'outline'}
                        disabled={sending}
                        onClick={() => input.current?.click()}
                        data-test={`document-send-${document.kind}`}
                    >
                        {sending ? <Spinner /> : <Upload aria-hidden />}
                        {toSend ? t('Upload') : t('Replace')}
                    </Button>
                </>
            ) : null}
        </li>
    );
}

Verification.layout = {
    breadcrumbs: [
        {
            title: 'Verify your business',
            href: show(),
        },
    ],
};

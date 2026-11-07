import { StampDot } from '@/components/wordmark';

/**
 * A message as a customer's phone shows it, in a list: the punchcard tile,
 * the title and the message, each in its own direction, long words broken
 * (automations B11, a campaign's results B10). The lock screen's own is
 * LockPreview.
 */
export function NotificationPreview({
    title,
    body,
}: {
    title: string;
    body: string;
}) {
    return (
        <div className="flex items-start gap-2.5 rounded-xl bg-secondary p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary">
                <StampDot />
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <b className="text-sm font-semibold break-words">
                    <bdi>{title}</bdi>
                </b>
                <span className="text-sm break-words">
                    <bdi>{body}</bdi>
                </span>
            </span>
        </div>
    );
}

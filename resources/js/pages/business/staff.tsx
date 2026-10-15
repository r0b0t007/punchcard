import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/staff';
import { store } from '@/routes/staff/invitations';

type Invitation = {
    id: number;
    email: string;
    location: string | null;
    expiresAt: string;
    /** The location's timezone, the date shows in. */
    timezone: string;
};

/**
 * B13 for the pilot (CHW-146): the owner invites staff by email, to the
 * whole business or one location, and sees the invitations still waiting.
 */
export default function Staff({
    locations,
    invitations,
}: {
    locations: { id: number; name: string }[];
    invitations: Invitation[];
}) {
    const { t, locale } = useTranslation();
    const date = (invitation: Invitation) =>
        new Intl.DateTimeFormat(locale, {
            dateStyle: 'medium',
            timeZone: invitation.timezone,
        }).format(new Date(invitation.expiresAt));

    return (
        <>
            <Head title={t('Staff')} />
            <div className="flex max-w-2xl flex-col gap-8 p-4">
                <section className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('Invite your staff')}
                        description={t(
                            'They get an email with a link to join. Staff can stamp and redeem rewards, never see billing.',
                        )}
                    />

                    <Form
                        {...store.form()}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        {t('Email address')}
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoComplete="off"
                                        placeholder="email@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                {locations.length > 1 ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="location_id">
                                            {t('Location')}
                                        </Label>
                                        <Select
                                            name="location_id"
                                            defaultValue="all"
                                        >
                                            <SelectTrigger
                                                id="location_id"
                                                className="w-full"
                                            >
                                                <SelectValue
                                                    placeholder={t(
                                                        'Every location',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">
                                                    {t('Every location')}
                                                </SelectItem>
                                                {locations.map((location) => (
                                                    <SelectItem
                                                        key={location.id}
                                                        value={String(
                                                            location.id,
                                                        )}
                                                    >
                                                        {location.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.location_id}
                                        />
                                    </div>
                                ) : null}

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="invite-staff-button"
                                >
                                    {processing && <Spinner />}
                                    {t('Send the invitation')}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                {invitations.length > 0 ? (
                    <section className="space-y-4">
                        <Heading variant="small" title={t('Waiting to join')} />
                        <ul className="divide-y rounded-lg border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-col gap-0.5 p-3 text-sm"
                                >
                                    <bdi
                                        dir="ltr"
                                        className="truncate font-medium"
                                    >
                                        {invitation.email}
                                    </bdi>
                                    <span className="text-muted-foreground">
                                        {invitation.location ??
                                            t('Every location')}
                                        {' · '}
                                        {t('Link works until :date', {
                                            date: date(invitation),
                                        })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </>
    );
}

Staff.layout = {
    breadcrumbs: [
        {
            title: 'Staff',
            href: index(),
        },
    ],
};

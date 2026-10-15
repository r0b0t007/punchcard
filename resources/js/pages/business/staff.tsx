import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index } from '@/routes/staff';
import { destroy as cancelInvitation, store } from '@/routes/staff/invitations';

type Member = {
    /** The membership's id (business_user), which removing it names. */
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'staff';
    roleLabel: string;
    /** The one location staff is limited to, if any. */
    location: string | null;
    /** Staff only: owners are handed over, never removed here. */
    removable: boolean;
};

type Invitation = {
    id: number;
    email: string;
    location: string | null;
    expiresAt: string;
    /** The location's timezone, the date shows in. */
    timezone: string;
};

/**
 * B13 for the pilot (CHW-146): the owner sees who works there and removes
 * staff (their account stays), invites staff by email, to the whole business
 * or one location, and cancels invitations still waiting.
 */
export default function Staff({
    members,
    locations,
    invitations,
}: {
    members: Member[];
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
                <section className="space-y-4">
                    <Heading variant="small" title={t('Your staff')} />
                    <ul className="divide-y rounded-lg border">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center gap-3 p-3 text-sm"
                            >
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="truncate font-medium">
                                        {member.name}
                                    </span>
                                    <bdi
                                        dir="ltr"
                                        className="truncate text-muted-foreground"
                                    >
                                        {member.email}
                                    </bdi>
                                    <span className="text-muted-foreground">
                                        {member.roleLabel}
                                        {member.role === 'staff'
                                            ? ` · ${member.location ?? t('Every location')}`
                                            : null}
                                    </span>
                                </div>
                                {member.removable ? (
                                    <RemoveMember member={member} />
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>

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
                                    className="flex items-center gap-3 p-3 text-sm"
                                >
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
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
                                    </div>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link
                                            href={cancelInvitation(
                                                invitation.id,
                                            )}
                                            as="button"
                                            preserveScroll
                                            data-test="cancel-invitation"
                                        >
                                            {t('Cancel')}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </>
    );
}

/** Removing a staff member, once confirmed: their membership ends, their account stays. */
function RemoveMember({ member }: { member: Member }) {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" data-test="remove-member">
                    {t('Remove')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {t('Remove :name from your staff?', { name: member.name })}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'They can no longer stamp or redeem here. Their account and the stamps they gave stay.',
                    )}
                </DialogDescription>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </DialogClose>
                    <Button variant="destructive" asChild>
                        <Link
                            href={destroy(member.id)}
                            as="button"
                            preserveScroll
                            data-test="confirm-remove-member"
                        >
                            {t('Remove')}
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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

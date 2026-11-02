import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Minus, Plus } from 'lucide-react';
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
import { newIdempotencyKey } from '@/lib/campaigns';
import { store as correct } from '@/routes/customers/corrections';
import { store as stamp } from '@/routes/customers/stamps';

type Option = { id: number; name: string };

/**
 * Add stamps or take them back (CHW-44, B5, design 15c): how many, on which
 * card (asked only with two or more), where, and why, which the history shows
 * with the owner's name. A fresh idempotency key each time it opens, so a
 * double press is one stamp. AddStamps' refusals come back on their field.
 */
export default function StampDialog({
    takesBack,
    customer,
    cards,
    locations,
    maxQty,
    trigger,
}: {
    takesBack: boolean;
    customer: { id: number; name: string };
    cards: Option[];
    locations: Option[];
    maxQty: number;
    trigger: ReactNode;
}) {
    const { t, tn } = useTranslation();
    const [open, setOpen] = useState(false);
    const form = useForm({
        qty: 1,
        enrollment: String(cards[0]?.id ?? ''),
        location: String(locations[0]?.id ?? ''),
        reason: '',
        key: newIdempotencyKey(),
    });
    const { data, setData, errors, processing } = form;
    const name = <bdi>{customer.name}</bdi>;
    const test = takesBack ? 'take-back' : 'add-stamps';

    const toggle = (next: boolean) => {
        if (next) {
            form.reset();
            form.clearErrors();
            setData('key', newIdempotencyKey());
        }

        setOpen(next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post((takesBack ? correct : stamp).url(customer.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={toggle}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                    data-test={`${test}-dialog`}
                >
                    <DialogTitle>
                        {takesBack
                            ? tn('Take back stamps from :name', { name })
                            : tn('Add stamps for :name', { name })}
                    </DialogTitle>
                    <DialogDescription>
                        {takesBack
                            ? t(
                                  'Only stamps given here can be taken back. Nothing is deleted: the correction shows on their history.',
                              )
                            : t(
                                  'For a visit the stamper missed. It counts as a visit.',
                              )}
                    </DialogDescription>

                    <div className="grid gap-2">
                        <Label id={`${test}-qty-label`}>{t('How many')}</Label>
                        <div className="flex items-center gap-2" dir="ltr">
                            <Stepper
                                label={t('One fewer')}
                                disabled={data.qty <= 1}
                                onClick={() =>
                                    setData('qty', Math.max(1, data.qty - 1))
                                }
                            >
                                <Minus aria-hidden="true" className="size-5" />
                            </Stepper>
                            <output
                                aria-labelledby={`${test}-qty-label`}
                                aria-live="polite"
                                className="min-w-14 text-center font-display text-3xl font-bold"
                                data-test={`${test}-qty`}
                            >
                                {takesBack ? `−${data.qty}` : data.qty}
                            </output>
                            <Stepper
                                label={t('One more')}
                                disabled={data.qty >= maxQty}
                                onClick={() =>
                                    setData(
                                        'qty',
                                        Math.min(maxQty, data.qty + 1),
                                    )
                                }
                            >
                                <Plus aria-hidden="true" className="size-5" />
                            </Stepper>
                        </div>
                        <InputError message={errors.qty} />
                    </div>

                    {cards.length > 1 ? (
                        <Choice
                            id={`${test}-card`}
                            label={t('Card')}
                            value={data.enrollment}
                            options={cards}
                            onChange={(value) => setData('enrollment', value)}
                            error={errors.enrollment}
                        />
                    ) : (
                        <InputError message={errors.enrollment} />
                    )}

                    <Choice
                        id={`${test}-location`}
                        label={t('Where')}
                        value={data.location}
                        options={locations}
                        onChange={(value) => setData('location', value)}
                        error={errors.location}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor={`${test}-reason`}>
                            {t('Reason (required)')}
                        </Label>
                        <Input
                            id={`${test}-reason`}
                            value={data.reason}
                            onChange={(event) =>
                                setData('reason', event.target.value)
                            }
                            required
                            maxLength={255}
                            autoComplete="off"
                            aria-describedby={`${test}-reason-hint`}
                        />
                        <p
                            id={`${test}-reason-hint`}
                            className="text-xs text-muted-foreground"
                        >
                            {t('Shown on their history, with your name.')}
                        </p>
                        <InputError message={errors.reason ?? errors.key} />
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test={`${test}-submit`}
                        >
                            {processing && <Spinner />}
                            {takesBack
                                ? t(
                                      'Take back :count stamp|Take back :count stamps',
                                      { count: data.qty },
                                  )
                                : t('Add :count stamp|Add :count stamps', {
                                      count: data.qty,
                                  })}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Choice({
    id,
    label,
    value,
    options,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

function Stepper({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="flex size-11 items-center justify-center rounded-md border border-input bg-card disabled:opacity-50"
        >
            {children}
        </button>
    );
}

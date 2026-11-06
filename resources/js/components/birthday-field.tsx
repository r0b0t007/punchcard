import { Cake } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';

const DAYS = Array.from({ length: 31 }, (_, index) => index + 1);
const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1);

/** "March", "mars", "مارس": the months' names in the page's language. */
function monthNames(locale: string): string[] {
    const format = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        month: 'long',
        timeZone: 'UTC',
    });

    return MONTHS.map((month) =>
        format.format(new Date(Date.UTC(2000, month - 1, 1))),
    );
}

/**
 * The optional birthday on C12 Profile (CHW-46, design 17a): the day, then
 * the month by name, never the year. Posted with the profile form as
 * birthday_day and birthday_month; "Remove my birthday" empties both, for
 * Save to clear it. The server refuses a day the month never has. The two
 * selects are one group named "Birthday", described by the hint and the
 * error.
 */
export default function BirthdayField({
    birthday,
    waitDays,
    errors,
}: {
    birthday: { day: number; month: number } | null;
    /** Days after it is given that it counts (CHW-370). */
    waitDays: number;
    errors: { birthday_day?: string; birthday_month?: string };
}) {
    const { t, locale } = useTranslation();
    const [day, setDay] = useState(birthday ? String(birthday.day) : '');
    const [month, setMonth] = useState(birthday ? String(birthday.month) : '');
    const names = useMemo(() => monthNames(locale), [locale]);
    const id = useId();
    const error = errors.birthday_day ?? errors.birthday_month;
    const describedBy = error ? `${id}-hint ${id}-error` : `${id}-hint`;

    return (
        <div
            role="group"
            aria-labelledby={`${id}-label ${id}-optional`}
            className="grid gap-2"
            data-test="birthday"
        >
            <div className="flex items-center gap-2.5">
                <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-md bg-stamp/20"
                >
                    <Cake className="size-4" />
                </span>
                <div className="flex flex-col">
                    <Label id={`${id}-label`} htmlFor="birthday_day">
                        {t('Birthday')}
                    </Label>
                    <span
                        id={`${id}-optional`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('Optional')}
                    </span>
                </div>
            </div>

            <div className="grid grid-cols-5 gap-2.5">
                <div className="col-span-2">
                    <Select
                        name="birthday_day"
                        value={day}
                        onValueChange={setDay}
                    >
                        <SelectTrigger
                            id="birthday_day"
                            aria-label={t('Day')}
                            aria-describedby={describedBy}
                            aria-invalid={
                                errors.birthday_day ? true : undefined
                            }
                            className="w-full"
                        >
                            <SelectValue placeholder={t('Day')} />
                        </SelectTrigger>
                        <SelectContent>
                            {DAYS.map((each) => (
                                <SelectItem key={each} value={String(each)}>
                                    {each}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="col-span-3">
                    <Select
                        name="birthday_month"
                        value={month}
                        onValueChange={setMonth}
                    >
                        <SelectTrigger
                            aria-label={t('Month')}
                            aria-describedby={describedBy}
                            aria-invalid={
                                errors.birthday_month ? true : undefined
                            }
                            className="w-full"
                            data-test="birthday-month"
                        >
                            <SelectValue placeholder={t('Month')} />
                        </SelectTrigger>
                        <SelectContent>
                            {MONTHS.map((each) => (
                                <SelectItem key={each} value={String(each)}>
                                    {names[each - 1]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <InputError id={`${id}-error`} message={error} />
            <p
                id={`${id}-hint`}
                className="text-sm text-pretty text-muted-foreground"
            >
                {t(
                    'The places where you collect stamps can give you a treat on your birthday, :days days after you add or change it. Never the year.',
                    { days: waitDays },
                )}
            </p>
            {day !== '' || month !== '' ? (
                <button
                    type="button"
                    onClick={() => {
                        setDay('');
                        setMonth('');
                    }}
                    className="justify-self-start text-sm font-medium underline underline-offset-4"
                    data-test="remove-birthday"
                >
                    {t('Remove my birthday')}
                </button>
            ) : null}
        </div>
    );
}

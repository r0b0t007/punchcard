import { Cake } from 'lucide-react';
import { useState } from 'react';
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

/** "March", "mars", "مارس": the month's name in the page's language. */
function monthName(locale: string, month: number): string {
    return new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(2000, month - 1, 1)));
}

/**
 * The optional birthday on C12 Profile (CHW-46, design 17a): the day, then
 * the month by name, never the year. Posted with the profile form as
 * birthday_day and birthday_month; "Remove my birthday" empties both, for
 * Save to clear it. The server refuses a day the month never has.
 */
export default function BirthdayField({
    birthday,
    errors,
}: {
    birthday: { day: number; month: number } | null;
    errors: { birthday_day?: string; birthday_month?: string };
}) {
    const { t, locale } = useTranslation();
    const [day, setDay] = useState(birthday ? String(birthday.day) : '');
    const [month, setMonth] = useState(birthday ? String(birthday.month) : '');

    return (
        <div className="grid gap-2" data-test="birthday">
            <div className="flex items-center gap-2.5">
                <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-md bg-stamp/20"
                >
                    <Cake className="size-4" />
                </span>
                <div className="flex flex-col">
                    <Label htmlFor="birthday_day">{t('Birthday')}</Label>
                    <span className="text-xs text-muted-foreground">
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
                                    {monthName(locale, each)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <InputError
                message={errors.birthday_day ?? errors.birthday_month}
            />
            <p className="text-sm text-pretty text-muted-foreground">
                {t(
                    'The places where you collect stamps can give you a treat on your birthday. Never the year.',
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

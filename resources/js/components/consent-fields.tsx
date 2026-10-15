import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

/**
 * What every sign-up form asks (CHW-23): that they are 16 or older
 * (required), and news by email, never ticked for them.
 */
export default function ConsentFields({
    error,
    tabIndex,
}: {
    /** The "16 or older" error, if any. */
    error?: string;
    /** The first box's place in a form that numbers its fields; the second follows. */
    tabIndex?: number;
}) {
    const { t } = useTranslation();

    return (
        <>
            <div className="grid gap-2">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="adult"
                        name="adult"
                        value="1"
                        required
                        tabIndex={tabIndex}
                    />
                    <Label htmlFor="adult">{t("I'm 16 or older")}</Label>
                </div>
                <InputError message={error} />
            </div>

            <div className="flex items-center gap-3">
                <Checkbox
                    id="marketing"
                    name="marketing"
                    value="1"
                    tabIndex={tabIndex === undefined ? undefined : tabIndex + 1}
                />
                <Label htmlFor="marketing">
                    {t('Send me news and offers from punchcard by email')}
                </Label>
            </div>
        </>
    );
}

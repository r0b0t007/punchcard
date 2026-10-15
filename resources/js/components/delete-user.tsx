import { Form, Link, router } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { forgetOfflineCards } from '@/lib/offline-cards';
import { confirm as confirmItsYou } from '@/routes/profile';

/**
 * Deleting the account: with its password, or, for an account without one
 * (signed up by email link, CHW-23), once it confirmed it's them (a passkey
 * or a link by email, then back here).
 */
export default function DeleteUser({ hasPassword }: { hasPassword: boolean }) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('Delete account')}
                description={t('Delete your account and all of its resources')}
            />
            <div className="space-y-4 rounded-lg border border-destructive/20 bg-destructive/5 p-4">
                <div className="relative space-y-0.5 text-destructive">
                    <p className="font-medium">{t('Warning')}</p>
                    <p className="text-sm">
                        {t(
                            'Please proceed with caution, this cannot be undone.',
                        )}
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('Delete account')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {t('Are you sure you want to delete your account?')}
                        </DialogTitle>
                        <DialogDescription>
                            {hasPassword
                                ? t(
                                      "Once your account is deleted, you can no longer sign in. Stamps you collected or gave stay in the cafés' records without your name or email, so their counts stay correct. Please enter your password to confirm.",
                                  )
                                : t(
                                      "Once your account is deleted, you can no longer sign in. Stamps you collected or gave stay in the cafés' records without your name or email, so their counts stay correct.",
                                  )}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            onSuccess={() => {
                                // Nothing of the account stays on the phone (CHW-35).
                                router.clearHistory();
                                void forgetOfflineCards();
                            }}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        {hasPassword ? (
                                            <>
                                                <Label
                                                    htmlFor="password"
                                                    className="sr-only"
                                                >
                                                    {t('Password')}
                                                </Label>

                                                <PasswordInput
                                                    id="password"
                                                    name="password"
                                                    ref={passwordInput}
                                                    placeholder={t('Password')}
                                                    autoComplete="current-password"
                                                />
                                            </>
                                        ) : null}

                                        <InputError message={errors.password} />
                                        {!hasPassword && errors.password ? (
                                            <Link
                                                href={confirmItsYou()}
                                                className="text-sm font-medium underline underline-offset-4"
                                            >
                                                {t("Confirm it's you")}
                                            </Link>
                                        ) : null}
                                        <InputError message={errors.account} />
                                    </div>

                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('Cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('Delete account')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}

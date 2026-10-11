import { router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { banner } from '@/routes/cards';

/**
 * The card's banner (B6), uploaded as soon as it is picked: the page comes
 * back with the new one in the preview, the rest of the form untouched.
 */
export default function BannerUpload({ cardId }: { cardId: number }) {
    const { t } = useTranslation();
    const [error, setError] = useState<string>();
    const [uploading, setUploading] = useState(false);

    return (
        <div className="grid gap-2">
            <Label htmlFor="banner">{t('Banner')}</Label>
            <div className="flex items-center gap-2">
                <Input
                    id="banner"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    disabled={uploading}
                    onChange={(event) => {
                        const input = event.currentTarget;
                        const file = input.files?.[0];

                        if (!file) {
                            return;
                        }

                        router.post(
                            banner.url(cardId),
                            { banner: file },
                            {
                                preserveScroll: true,
                                preserveState: true,
                                onStart: () => {
                                    setUploading(true);
                                    setError(undefined);
                                },
                                onError: (errors) => setError(errors.banner),
                                onFinish: () => {
                                    setUploading(false);
                                    input.value = '';
                                },
                            },
                        );
                    }}
                />
                {uploading && <Spinner />}
            </div>
            <p className="text-xs text-muted-foreground">
                {t(
                    'A JPEG, PNG or WebP image, at least 600 by 200 pixels, up to 2 MB.',
                )}
            </p>
            <InputError message={error} />
        </div>
    );
}

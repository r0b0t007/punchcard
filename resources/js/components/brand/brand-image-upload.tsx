import { router } from '@inertiajs/react';
import { ImageUp } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

/**
 * A logo or cover picker (B17), uploaded as soon as it is picked, as the card
 * builder's banner is: the page comes back with the new image in the previews
 * and the colour being chosen untouched.
 */
export default function BrandImageUpload({
    field,
    url,
    label,
}: {
    field: 'logo' | 'cover';
    /** Where it posts (brand.logo or brand.cover). */
    url: string;
    label: string;
}) {
    const { t } = useTranslation();
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string>();
    const [uploading, setUploading] = useState(false);

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                    data-test={`upload-${field}`}
                >
                    <ImageUp aria-hidden="true" />
                    {label}
                </Button>
                {uploading && <Spinner />}
            </div>
            <input
                id={id}
                ref={input}
                type="file"
                className="sr-only"
                tabIndex={-1}
                aria-label={label}
                accept="image/jpeg,image/png,image/webp"
                onChange={(event) => {
                    const picker = event.currentTarget;
                    const file = picker.files?.[0];

                    if (!file) {
                        return;
                    }

                    router.post(
                        url,
                        { [field]: file },
                        {
                            preserveScroll: true,
                            preserveState: true,
                            onStart: () => {
                                setUploading(true);
                                setError(undefined);
                            },
                            onError: (errors) => setError(errors[field]),
                            onFinish: () => {
                                setUploading(false);
                                picker.value = '';
                            },
                        },
                    );
                }}
            />
            <InputError message={error} />
            <span className="sr-only" aria-live="polite">
                {uploading ? t('Uploading…') : ''}
            </span>
        </div>
    );
}

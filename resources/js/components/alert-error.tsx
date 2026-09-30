import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslation } from '@/hooks/use-translation';

export default function AlertError({
    errors,
    title,
}: {
    errors: string[];
    title?: string;
}) {
    const { t } = useTranslation();

    return (
        <Alert variant="destructive">
            <AlertCircleIcon />
            <AlertTitle>{title || t('Something went wrong.')}</AlertTitle>
            <AlertDescription>
                <ul className="list-inside list-disc text-sm">
                    {Array.from(new Set(errors)).map((error, index) => (
                        // Client-side messages (e.g. useTwoFactorAuth) are English keys;
                        // server messages arrive translated and pass through unchanged.
                        <li key={index}>{t(error)}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

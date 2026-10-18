import { useState } from 'react';

/**
 * A café's logo, or null once it fails to load (the caller then shows its
 * monogram). The failure belongs to that URL: a new logo is tried again.
 */
export function useLogo(
    logoUrl: string | null | undefined,
): [string | null, () => void] {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const logo = logoUrl && logoUrl !== failedUrl ? logoUrl : null;

    return [logo, () => setFailedUrl(logoUrl ?? null)];
}

import { Link } from '@inertiajs/react';
import { Wordmark } from '@/components/wordmark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2"
                        >
                            <Wordmark />
                        </Link>

                        {/* A page with its own heading (other-account) passes none: no empty h1. */}
                        {title || description ? (
                            <div className="space-y-2 text-center">
                                <h1 className="font-display text-form-title font-bold text-balance">
                                    {title}
                                </h1>
                                <p className="text-center text-md text-pretty text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        ) : null}
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}

import { Link } from '@inertiajs/react';

import AppLogoIcon from '@/components/layout/app-logo-icon';
import { home } from '@/routes';
import { privacy, terms } from '@/routes/legal';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
    brandText,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex items-center gap-2 font-medium"
                        >
                            <div className="flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 text-primary" />
                            </div>
                            {brandText && (
                                <span className="text-lg leading-none font-semibold tracking-tight">
                                    {brandText}
                                </span>
                            )}
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}

                    {/* Google, Meta, X and LinkedIn all expect a signup screen
                        to link its terms and privacy policy before they approve
                        an OAuth app, so these belong here and not only on the
                        marketing pages. */}
                    <p className="text-center text-xs text-muted-foreground">
                        By continuing you agree to our{' '}
                        <Link
                            href={terms()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            Terms
                        </Link>{' '}
                        and{' '}
                        <Link
                            href={privacy()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            Privacy Policy
                        </Link>
                        .
                    </p>
                </div>
            </div>
        </div>
    );
}

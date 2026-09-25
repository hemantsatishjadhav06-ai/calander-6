import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';
import { dataDeletion, privacy, terms } from '@/routes/legal';

/**
 * Fixed decorative background, matching the public share viewer so the two
 * unauthenticated surfaces read as one product. Pure decoration: behind
 * everything, ignores pointer events, works in light and dark.
 */
function PublicScene() {
    return (
        <div
            aria-hidden
            className="pointer-events-none fixed inset-0 -z-10 overflow-hidden"
        >
            <div className="absolute inset-0 bg-background" />
            <div
                className="absolute inset-0 opacity-[0.35]"
                style={{
                    background:
                        'radial-gradient(60rem 60rem at 12% -10%, color-mix(in oklch, var(--primary) 28%, transparent), transparent 60%),' +
                        'radial-gradient(50rem 50rem at 110% 10%, color-mix(in oklch, var(--primary) 14%, transparent), transparent 55%),' +
                        'radial-gradient(70rem 50rem at 50% 120%, color-mix(in oklch, var(--primary) 16%, transparent), transparent 60%)',
                }}
            />
            <div
                className="absolute inset-0 [mask-image:radial-gradient(80%_60%_at_50%_0%,black,transparent)] opacity-[0.5]"
                style={{
                    backgroundImage:
                        'radial-gradient(currentColor 0.5px, transparent 0.5px)',
                    backgroundSize: '22px 22px',
                    color: 'color-mix(in oklch, var(--foreground) 8%, transparent)',
                }}
            />
        </div>
    );
}

function PublicHeader({
    appName,
    showRegister,
}: {
    appName: string;
    showRegister: boolean;
}) {
    return (
        <header className="sticky top-0 z-20 border-b border-border/70 bg-background/70 backdrop-blur-xl">
            <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-5 py-3 sm:px-8">
                <Link
                    href={home()}
                    className="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <AppLogoIcon className="size-7 text-primary" />
                    <span className="font-[family-name:var(--font-display)] text-[18px] font-semibold tracking-tight text-foreground">
                        {appName}
                    </span>
                </Link>

                <nav className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        render={<Link href={login()} />}
                    >
                        Log in
                    </Button>
                    {showRegister && (
                        <Button size="sm" render={<Link href={register()} />}>
                            Get started
                        </Button>
                    )}
                </nav>
            </div>
        </header>
    );
}

function PublicFooter({
    appName,
    company,
}: {
    appName: string;
    company: string;
}) {
    return (
        <footer className="border-t border-border/70">
            <div className="mx-auto flex max-w-5xl flex-col gap-4 px-5 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <p>
                    © {new Date().getFullYear()} {company || appName}
                </p>
                <nav className="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <Link
                        href={privacy()}
                        className="transition-colors hover:text-foreground"
                    >
                        Privacy
                    </Link>
                    <Link
                        href={terms()}
                        className="transition-colors hover:text-foreground"
                    >
                        Terms
                    </Link>
                    <Link
                        href={dataDeletion()}
                        className="transition-colors hover:text-foreground"
                    >
                        Delete your data
                    </Link>
                </nav>
            </div>
        </footer>
    );
}

/**
 * Chrome shared by every unauthenticated page. `showRegister` is driven by the
 * instance's registration setting on the home page, and left on elsewhere so a
 * legal page never has to query it.
 */
export default function PublicShell({
    appName,
    company,
    showRegister = true,
    children,
}: {
    appName: string;
    company: string;
    showRegister?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-svh flex-col">
            <PublicScene />
            <PublicHeader appName={appName} showRegister={showRegister} />
            <main className="flex-1">{children}</main>
            <PublicFooter appName={appName} company={company} />
        </div>
    );
}

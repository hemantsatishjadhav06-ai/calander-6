import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { ThemeToggle } from '@/components/common/theme-toggle';
import AppLogoIcon from '@/components/layout/app-logo-icon';
import { Button } from '@/components/ui/button';
import { ArrowUpRight, Menu, X } from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { home, login, register } from '@/routes';
import { dataDeletion, privacy, terms } from '@/routes/legal';
import {
    developers,
    features,
    howItWorks,
    platforms,
    security,
} from '@/routes/product';
import type { PublicSiteProps } from '@/types/public';

/**
 * The product pages, in the order the header and footer list them. Each one
 * answers a different question: what it does, how, where it publishes, how to
 * build on it, and how it keeps accounts safe.
 */
const PRODUCT_LINKS = [
    { label: 'Features', route: features },
    { label: 'How it works', route: howItWorks },
    { label: 'Platforms', route: platforms },
    { label: 'Developers', route: developers },
    { label: 'Security', route: security },
];

/**
 * Fixed decorative background, matching the public share viewer so the
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

/** Whether `href` is the page being viewed, ignoring any query string. */
function isCurrent(currentUrl: string, href: string): boolean {
    return currentUrl.split('?')[0] === href;
}

function PublicHeader({
    appName,
    showRegister,
}: {
    appName: string;
    showRegister: boolean;
}) {
    const { url } = usePage();
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 border-b border-border/60 bg-background/90 backdrop-blur-xl">
            <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-8">
                <Link
                    href={home()}
                    className="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <AppLogoIcon className="size-7 text-primary" />
                    <span className="font-[family-name:var(--font-display)] text-[19px] font-medium tracking-tight text-foreground">
                        {appName}
                    </span>
                </Link>

                <nav
                    aria-label="Product"
                    className="hidden items-center gap-1 lg:flex"
                >
                    {PRODUCT_LINKS.map((item) => {
                        const href = item.route().url;
                        const current = isCurrent(url, href);

                        return (
                            <Link
                                key={item.label}
                                href={href}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    'rounded-full px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    current && 'bg-muted text-foreground',
                                )}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>

                <div className="flex items-center gap-1.5">
                    <ThemeToggle />
                    <Button
                        variant="ghost"
                        size="sm"
                        className="hidden rounded-full sm:inline-flex"
                        render={<Link href={login()} />}
                    >
                        Log in
                    </Button>
                    {showRegister && (
                        <Button
                            size="sm"
                            className="hidden rounded-full px-3.5 sm:inline-flex"
                            render={<Link href={register()} />}
                        >
                            Get started
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 lg:hidden"
                        aria-expanded={menuOpen}
                        aria-controls="public-mobile-menu"
                        aria-label={menuOpen ? 'Close menu' : 'Open menu'}
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        {menuOpen ? (
                            <X className="size-4" />
                        ) : (
                            <Menu className="size-4" />
                        )}
                    </Button>
                </div>
            </div>

            {menuOpen && (
                <nav
                    id="public-mobile-menu"
                    aria-label="Product"
                    className="border-t border-border/60 bg-background/95 lg:hidden"
                >
                    <ul className="mx-auto grid max-w-6xl gap-1 px-4 py-3 sm:px-8">
                        {PRODUCT_LINKS.map((item) => {
                            const href = item.route().url;

                            return (
                                <li key={item.label}>
                                    <Link
                                        href={href}
                                        aria-current={
                                            isCurrent(url, href)
                                                ? 'page'
                                                : undefined
                                        }
                                        className="block rounded-xl px-3 py-2.5 text-[15px] font-medium text-foreground transition-colors hover:bg-muted aria-[current=page]:bg-muted"
                                    >
                                        {item.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="mx-auto flex max-w-6xl gap-2 px-4 pb-4 sm:hidden">
                        <Button
                            variant="outline"
                            className="h-10 flex-1 rounded-full"
                            render={<Link href={login()} />}
                        >
                            Log in
                        </Button>
                        {showRegister && (
                            <Button
                                className="h-10 flex-1 rounded-full"
                                render={<Link href={register()} />}
                            >
                                Get started
                            </Button>
                        )}
                    </div>
                </nav>
            )}
        </header>
    );
}

function FooterColumn({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <div>
            <h2 className="text-xs font-semibold tracking-[0.16em] text-foreground uppercase">
                {title}
            </h2>
            <ul className="mt-4 grid gap-2.5 text-sm text-muted-foreground">
                {children}
            </ul>
        </div>
    );
}

const footerLinkClass =
    'rounded-sm transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

function PublicFooter({
    appName,
    company,
    repoUrl,
}: {
    appName: string;
    company: string;
    repoUrl: string;
}) {
    return (
        <footer className="border-t border-border/70 bg-background/40">
            <div className="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-8 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div className="max-w-xs">
                    <Link
                        href={home()}
                        className="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <AppLogoIcon className="size-7 text-primary" />
                        <span className="font-[family-name:var(--font-display)] text-[19px] font-medium tracking-tight text-foreground">
                            {appName}
                        </span>
                    </Link>
                    <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
                        Schedule, publish and follow up on every social account
                        your team runs, from one calendar.
                    </p>
                </div>

                <FooterColumn title="Product">
                    {PRODUCT_LINKS.slice(0, 3).map((item) => (
                        <li key={item.label}>
                            <Link
                                href={item.route()}
                                className={footerLinkClass}
                            >
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </FooterColumn>

                <FooterColumn title="Build">
                    <li>
                        <Link href={developers()} className={footerLinkClass}>
                            API and MCP
                        </Link>
                    </li>
                    {repoUrl && (
                        <li>
                            <a
                                href={repoUrl}
                                target="_blank"
                                rel="noreferrer"
                                className={cn(
                                    footerLinkClass,
                                    'inline-flex items-center gap-1',
                                )}
                            >
                                Source code
                                <ArrowUpRight className="size-3.5" />
                            </a>
                        </li>
                    )}
                </FooterColumn>

                <FooterColumn title="Trust">
                    <li>
                        <Link href={security()} className={footerLinkClass}>
                            Security
                        </Link>
                    </li>
                    <li>
                        <Link href={privacy()} className={footerLinkClass}>
                            Privacy
                        </Link>
                    </li>
                    <li>
                        <Link href={terms()} className={footerLinkClass}>
                            Terms
                        </Link>
                    </li>
                    <li>
                        <Link href={dataDeletion()} className={footerLinkClass}>
                            Delete your data
                        </Link>
                    </li>
                </FooterColumn>
            </div>
            <div className="border-t border-border/60">
                <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <p>
                        © {new Date().getFullYear()} {company || appName}
                    </p>
                    <p>Open source and self-hostable.</p>
                </div>
            </div>
        </footer>
    );
}

/**
 * Chrome shared by every unauthenticated page. `showRegister` is driven by the
 * instance's registration setting, and left on by default so a caller that
 * does not know it never has to query it.
 *
 * The source link is read from the page props rather than passed in: every
 * public page receives it from PublicPageController::siteProps(), and threading
 * it through each legal page would add a prop nobody but the footer reads.
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
    const { repoUrl = '' } = usePage<Partial<PublicSiteProps>>().props;

    return (
        <div className="flex min-h-svh flex-col">
            <PublicScene />
            <a
                href="#main"
                className="sr-only z-50 rounded-full bg-background px-4 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:ring-2 focus:ring-ring"
            >
                Skip to content
            </a>
            <PublicHeader appName={appName} showRegister={showRegister} />
            <main id="main" className="flex-1">
                {children}
            </main>
            <PublicFooter
                appName={appName}
                company={company}
                repoUrl={repoUrl}
            />
        </div>
    );
}

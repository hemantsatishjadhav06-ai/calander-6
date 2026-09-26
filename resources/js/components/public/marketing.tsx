import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { ArrowUpRight, Check, Copy } from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { login, register } from '@/routes';

/**
 * Building blocks shared by the public product pages (home, features, how it
 * works, platforms, developers, security), so they read as one site rather
 * than six pages that happen to share a header.
 */

export function Container({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('mx-auto w-full max-w-6xl px-4 sm:px-8', className)}>
            {children}
        </div>
    );
}

export function Section({
    id,
    className,
    containerClassName,
    children,
}: {
    id?: string;
    className?: string;
    containerClassName?: string;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className={cn('scroll-mt-24 py-20 sm:py-28', className)}
        >
            <Container className={containerClassName}>{children}</Container>
        </section>
    );
}

/** Small uppercase label above a heading, with a lime status dot. */
export function Eyebrow({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'inline-flex items-center gap-2 text-xs font-semibold tracking-[0.18em] text-primary-ink uppercase',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-primary shadow-[0_0_0_3px_color-mix(in_oklch,var(--primary)_25%,transparent)]"
            />
            {children}
        </p>
    );
}

/**
 * The italic display-face word that carries each headline's emphasis, with a
 * lime highlighter stroke behind its lower half.
 */
export function Accent({ children }: { children: ReactNode }) {
    return (
        <em className="bg-[linear-gradient(transparent_64%,color-mix(in_oklch,var(--primary)_38%,transparent)_64%)] bg-no-repeat px-0.5 font-normal text-foreground italic">
            {children}
        </em>
    );
}

const displayClass =
    'font-[family-name:var(--font-display)] font-medium tracking-[-0.02em] text-balance text-foreground';

export function SectionHeading({
    eyebrow,
    title,
    description,
    align = 'left',
    className,
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    align?: 'left' | 'center';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'max-w-2xl',
                align === 'center' && 'mx-auto text-center',
                className,
            )}
        >
            {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
            <h2
                className={cn(
                    displayClass,
                    'mt-4 text-3xl leading-[1.08] sm:text-5xl',
                )}
            >
                {title}
            </h2>
            {description && (
                <p className="mt-5 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg">
                    {description}
                </p>
            )}
        </div>
    );
}

/** The opening block of every product page except home. */
export function PageHero({
    eyebrow,
    title,
    description,
    children,
}: {
    eyebrow: ReactNode;
    title: ReactNode;
    description: ReactNode;
    children?: ReactNode;
}) {
    return (
        <section className="relative overflow-hidden pt-16 pb-14 sm:pt-24 sm:pb-20">
            <Container>
                <div className="mx-auto max-w-3xl text-center">
                    <Eyebrow>{eyebrow}</Eyebrow>
                    <h1
                        className={cn(
                            displayClass,
                            'mt-5 text-4xl leading-[1.04] sm:text-6xl',
                        )}
                    >
                        {title}
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg">
                        {description}
                    </p>
                </div>
                {children}
            </Container>
        </section>
    );
}

export function CheckList({
    items,
    className,
}: {
    items: ReactNode[];
    className?: string;
}) {
    return (
        <ul className={cn('grid gap-3', className)}>
            {items.map((item, index) => (
                <li
                    key={index}
                    className="flex gap-3 text-sm leading-relaxed text-muted-foreground"
                >
                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary-ink">
                        <Check className="size-3" strokeWidth={3} />
                    </span>
                    <span>{item}</span>
                </li>
            ))}
        </ul>
    );
}

/** A bordered, softly lit surface: the default card on the product pages. */
export function Panel({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative rounded-3xl border border-border/70 bg-card/70 shadow-[0_1px_0_0_color-mix(in_oklch,var(--foreground)_4%,transparent)] backdrop-blur-sm',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** A feature tile: icon, title, body. */
export function FeatureTile({
    icon: Icon,
    title,
    children,
    className,
}: {
    icon: typeof Check;
    title: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Panel
            className={cn(
                'group p-6 transition-colors hover:border-primary/40',
                className,
            )}
        >
            <span className="flex size-10 items-center justify-center rounded-2xl border border-primary/25 bg-primary/10 text-primary-ink transition-transform group-hover:-translate-y-0.5">
                <Icon className="size-5" />
            </span>
            <h3 className="mt-5 text-base font-semibold text-foreground">
                {title}
            </h3>
            <div className="mt-2 text-sm leading-relaxed text-muted-foreground">
                {children}
            </div>
        </Panel>
    );
}

/**
 * A terminal-styled code sample with a copy button. The button renders on the
 * server and the client alike (so SSR hydrates cleanly) and does nothing where
 * the Clipboard API is unavailable, e.g. on a plain-http instance.
 */
export function CodeBlock({
    label,
    code,
    className,
}: {
    label: string;
    code: string;
    className?: string;
}) {
    const [copied, setCopied] = useState(false);

    function copy() {
        if (!navigator.clipboard) {
            return;
        }

        void navigator.clipboard.writeText(code).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        });
    }

    return (
        <div
            className={cn(
                'overflow-hidden rounded-2xl border border-white/10 bg-[oklch(0.17_0.01_130)] text-[oklch(0.93_0.01_130)] shadow-2xl shadow-black/20',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-2.5">
                <div className="flex items-center gap-2">
                    <span className="flex gap-1.5" aria-hidden="true">
                        <span className="size-2.5 rounded-full bg-white/15" />
                        <span className="size-2.5 rounded-full bg-white/15" />
                        <span className="size-2.5 rounded-full bg-white/15" />
                    </span>
                    <span className="ml-2 font-mono text-[11px] text-white/55">
                        {label}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={copy}
                    className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-medium text-white/60 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    aria-label={`Copy ${label}`}
                >
                    {copied ? (
                        <Check className="size-3.5 text-primary" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
                </button>
            </div>
            <pre className="overflow-x-auto p-4 font-mono text-[12.5px] leading-6 sm:p-5">
                <code>{code}</code>
            </pre>
        </div>
    );
}

/** Inline link with an arrow, for "read more" style jumps between pages. */
export function ArrowLink({
    href,
    children,
    className,
}: {
    href: ComponentProps<typeof Link>['href'];
    children: ReactNode;
    className?: string;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'group inline-flex items-center gap-1 text-sm font-semibold text-primary-ink underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
        >
            {children}
            <ArrowUpRight className="size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
        </Link>
    );
}

/**
 * The primary sign-up call to action. Follows the instance's registration
 * setting: a "Create your account" button that bounces off a closed
 * registration route is worse than no button.
 */
export function AuthButtons({
    registrationsEnabled,
    size = 'lg',
    className,
}: {
    registrationsEnabled: boolean;
    size?: 'lg' | 'default';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 sm:flex-row',
                className,
            )}
        >
            {registrationsEnabled ? (
                <>
                    <Button
                        size={size}
                        className="h-11 rounded-full px-6 text-[15px]"
                        render={<Link href={register()} />}
                    >
                        Create your account
                    </Button>
                    <Button
                        size={size}
                        variant="outline"
                        className="h-11 rounded-full px-6 text-[15px]"
                        render={<Link href={login()} />}
                    >
                        Log in
                    </Button>
                </>
            ) : (
                <>
                    <Button
                        size={size}
                        className="h-11 rounded-full px-6 text-[15px]"
                        render={<Link href={login()} />}
                    >
                        Log in
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        Sign-ups are closed on this instance.
                    </p>
                </>
            )}
        </div>
    );
}

/** The closing call to action every product page ends on. */
export function CtaBand({
    registrationsEnabled,
    title,
    description,
}: {
    registrationsEnabled: boolean;
    title: ReactNode;
    description: ReactNode;
}) {
    return (
        <Section className="pt-6 sm:pt-10">
            <div className="relative isolate overflow-hidden rounded-[2rem] border border-primary/30 px-6 py-16 text-center sm:px-12 sm:py-20">
                <div
                    aria-hidden="true"
                    className="absolute inset-0 -z-10 bg-[radial-gradient(60%_90%_at_50%_0%,color-mix(in_oklch,var(--primary)_32%,transparent),transparent_70%),radial-gradient(40%_60%_at_100%_100%,color-mix(in_oklch,var(--primary)_18%,transparent),transparent_70%)]"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 -z-10 [mask-image:radial-gradient(70%_70%_at_50%_30%,black,transparent)] opacity-60"
                    style={{
                        backgroundImage:
                            'linear-gradient(color-mix(in oklch, var(--foreground) 7%, transparent) 1px, transparent 1px), linear-gradient(90deg, color-mix(in oklch, var(--foreground) 7%, transparent) 1px, transparent 1px)',
                        backgroundSize: '44px 44px',
                    }}
                />
                <h2
                    className={cn(
                        displayClass,
                        'mx-auto max-w-2xl text-3xl leading-[1.08] sm:text-5xl',
                    )}
                >
                    {title}
                </h2>
                <p className="mx-auto mt-5 max-w-xl text-base text-pretty text-muted-foreground sm:text-lg">
                    {description}
                </p>
                <AuthButtons
                    registrationsEnabled={registrationsEnabled}
                    className="mt-9"
                />
            </div>
        </Section>
    );
}

import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import PublicShell from '@/components/public/public-shell';
import { Button } from '@/components/ui/button';
import {
    BarChart3,
    Bot,
    CalendarClock,
    Check,
    KeyRound,
    Layers,
    MessageSquare,
    Send,
    ShieldCheck,
    Users,
} from '@/components/ui/icons';
import { login, register } from '@/routes';

type PlatformOption = { value: string; label: string };

type Props = {
    appName: string;
    company: string;
    contactEmail: string;
    address: string;
    jurisdiction: string;
    effectiveDate: string;
    platforms: PlatformOption[];
    registrationsEnabled: boolean;
};

type Feature = {
    icon: typeof Send;
    title: string;
    body: string;
};

/**
 * Every claim here maps to something the app actually does. Nothing is
 * aspirational — a landing page that promises a feature the product does not
 * have costs more on the first day of use than it wins on the first visit.
 */
const FEATURES: Feature[] = [
    {
        icon: Send,
        title: 'Write once, publish everywhere',
        body: 'Compose a post once, then tailor the text per platform where it matters. One editor, one queue, every account you run.',
    },
    {
        icon: CalendarClock,
        title: 'A calendar you can drag',
        body: 'See the whole month at a glance. Drag a post to move it, and a scheduler claims each one the minute it comes due.',
    },
    {
        icon: Layers,
        title: 'Account sets',
        body: 'Group the accounts that always post together, then publish to the whole set instead of ticking boxes every time.',
    },
    {
        icon: MessageSquare,
        title: 'Replies and DMs in one inbox',
        body: 'Replies to your posts and direct messages land in a single inbox, so nothing waits unanswered in an app nobody opened.',
    },
    {
        icon: BarChart3,
        title: 'Metrics that refresh themselves',
        body: 'Likes, comments, reposts and impressions are captured on a schedule that backs off as a post ages, so fresh work is measured closely and old work cheaply.',
    },
    {
        icon: Users,
        title: 'Workspaces for real teams',
        body: 'Separate clients or brands into workspaces with their own accounts, members and roles. Nothing crosses between them.',
    },
    {
        icon: KeyRound,
        title: 'An API and MCP endpoint',
        body: 'Scoped API keys for your own scripts and integrations, plus an MCP endpoint so an AI assistant can draft and queue posts for you.',
    },
    {
        icon: Bot,
        title: 'Auto-reposts that earn it',
        body: 'Well-performing posts can be reposted automatically on a delay you set, instead of by hand at the right hour.',
    },
];

const STEPS: { title: string; body: string }[] = [
    {
        title: 'Connect your accounts',
        body: 'Authorize each platform once. Tokens are stored encrypted and refreshed before they expire, so a connection does not quietly die mid-campaign.',
    },
    {
        title: 'Compose and schedule',
        body: 'Write the post, adjust it per platform, attach media, and pick a time — or save it as a draft and come back to it.',
    },
    {
        title: 'Watch what happens next',
        body: 'Published posts report back: metrics on a schedule, replies and DMs in the inbox, and a notification the moment something fails.',
    },
];

function Section({
    id,
    children,
    className = '',
}: {
    id?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            id={id}
            className={`mx-auto max-w-5xl px-5 py-16 sm:px-8 sm:py-20 ${className}`}
        >
            {children}
        </section>
    );
}

function Hero({
    appName,
    registrationsEnabled,
}: {
    appName: string;
    registrationsEnabled: boolean;
}) {
    return (
        <Section className="!pt-20 text-center sm:!pt-28">
            <p className="mb-5 inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/60 px-3 py-1 text-xs font-medium tracking-wide text-muted-foreground">
                <ShieldCheck
                    className="size-3.5 text-primary"
                    aria-hidden="true"
                />
                Self-hostable, open source
            </p>

            <h1 className="mx-auto max-w-3xl font-[family-name:var(--font-display)] text-4xl font-semibold tracking-tight text-balance text-foreground sm:text-6xl">
                Every social account your team runs, on one calendar
            </h1>

            <p className="mx-auto mt-6 max-w-2xl text-lg text-pretty text-muted-foreground">
                {appName} schedules and publishes to X, Bluesky, LinkedIn,
                Instagram, Facebook, Threads and Discord — then brings the
                replies, messages and metrics back to you.
            </p>

            <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                {registrationsEnabled ? (
                    <>
                        <Button size="lg" render={<Link href={register()} />}>
                            Create your account
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            render={<Link href={login()} />}
                        >
                            Log in
                        </Button>
                    </>
                ) : (
                    <>
                        <Button size="lg" render={<Link href={login()} />}>
                            Log in
                        </Button>
                        <p className="text-sm text-muted-foreground">
                            Sign-ups are closed on this instance.
                        </p>
                    </>
                )}
            </div>
        </Section>
    );
}

function Platforms({ platforms }: { platforms: PlatformOption[] }) {
    return (
        <Section className="!py-10">
            <h2 className="text-center text-sm font-medium tracking-wide text-muted-foreground uppercase">
                Publishes to
            </h2>
            <ul className="mt-6 flex flex-wrap items-center justify-center gap-2.5">
                {platforms.map((platform) => (
                    <li
                        key={platform.value}
                        className="rounded-full border border-border/70 bg-card/60 px-4 py-1.5 text-sm font-medium text-foreground"
                    >
                        {platform.label}
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function Features() {
    return (
        <Section id="features">
            <h2 className="font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                What you get
            </h2>

            <div className="mt-10 grid gap-5 sm:grid-cols-2">
                {FEATURES.map((feature) => (
                    <div
                        key={feature.title}
                        className="rounded-xl border border-border/70 bg-card/60 p-5"
                    >
                        <feature.icon
                            className="size-5 text-primary"
                            aria-hidden="true"
                        />
                        <h3 className="mt-3 font-medium text-foreground">
                            {feature.title}
                        </h3>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            {feature.body}
                        </p>
                    </div>
                ))}
            </div>
        </Section>
    );
}

function HowItWorks() {
    return (
        <Section id="how-it-works">
            <h2 className="font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                How it works
            </h2>

            <ol className="mt-10 grid gap-6 sm:grid-cols-3">
                {STEPS.map((step, index) => (
                    <li key={step.title}>
                        <span className="inline-flex size-7 items-center justify-center rounded-full border border-border/70 bg-card/60 text-sm font-medium text-primary">
                            {index + 1}
                        </span>
                        <h3 className="mt-3 font-medium text-foreground">
                            {step.title}
                        </h3>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            {step.body}
                        </p>
                    </li>
                ))}
            </ol>
        </Section>
    );
}

function Assurances() {
    const points = [
        'Your workspaces are isolated from every other tenant on the instance.',
        'Platform tokens are stored encrypted and refreshed before they expire.',
        'Nothing is posted that you did not schedule or publish yourself.',
        'You can export nothing you did not put in, and delete everything you did.',
    ];

    return (
        <Section className="!py-12">
            <div className="rounded-xl border border-border/70 bg-card/60 p-6 sm:p-8">
                <h2 className="font-[family-name:var(--font-display)] text-2xl font-semibold tracking-tight text-foreground">
                    Where your data stands
                </h2>
                <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                    {points.map((point) => (
                        <li
                            key={point}
                            className="flex gap-2.5 text-sm text-muted-foreground"
                        >
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span>{point}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </Section>
    );
}

export default function Home({
    appName,
    company,
    platforms,
    registrationsEnabled,
}: Props) {
    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            {/* app.tsx appends " - {appName}", so the title must not repeat it. */}
            <Head title="Every social account on one calendar" />

            <Hero
                appName={appName}
                registrationsEnabled={registrationsEnabled}
            />
            <Platforms platforms={platforms} />
            <Features />
            <HowItWorks />
            <Assurances />
        </PublicShell>
    );
}

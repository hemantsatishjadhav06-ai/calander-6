import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    Accent,
    ArrowLink,
    AuthButtons,
    CheckList,
    CodeBlock,
    Container,
    CtaBand,
    Eyebrow,
    Panel,
    Section,
    SectionHeading,
} from '@/components/public/marketing';
import {
    AppWindow,
    CalendarMockup,
    ComposerMockup,
    InboxMockup,
    MetricsMockup,
    PublishedToastMockup,
    QueueMockup,
    RepostMockup,
    WorkspaceMockup,
} from '@/components/public/product-mockups';
import PublicShell from '@/components/public/public-shell';
import {
    BarChart3,
    CalendarClock,
    Inbox,
    KeyRound,
    PenLine,
    Repeat2,
    ShieldCheck,
    Users,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import {
    developers,
    features,
    howItWorks,
    platforms as platformsPage,
    security,
} from '@/routes/product';
import type { PlatformName } from '@/types/compose';
import type { PlatformOption, PublicSiteProps } from '@/types/public';

type Props = PublicSiteProps & {
    platforms: PlatformOption[];
    mcpToolCount: number;
};

/**
 * Every claim on this page maps to something the app actually does. Nothing is
 * aspirational: a landing page that promises a feature the product does not
 * have costs more on the first day of use than it wins on the first visit.
 */

function Hero({
    appName,
    registrationsEnabled,
    platformCount,
}: {
    appName: string;
    registrationsEnabled: boolean;
    platformCount: number;
}) {
    return (
        <section className="relative overflow-hidden pt-14 sm:pt-24">
            <Container>
                <div className="mx-auto max-w-4xl text-center">
                    <p className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/70 py-1 pr-3 pl-1 text-xs font-medium text-muted-foreground shadow-sm backdrop-blur motion-safe:animate-in motion-safe:duration-700 motion-safe:fade-in motion-safe:slide-in-from-bottom-2">
                        <span className="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">
                            Open source
                        </span>
                        Self-hostable · {platformCount} platforms · one calendar
                    </p>

                    <h1 className="mt-7 font-[family-name:var(--font-display)] text-[2.6rem] leading-[1.02] font-medium tracking-[-0.03em] text-balance text-foreground motion-safe:animate-in motion-safe:duration-700 motion-safe:fade-in motion-safe:slide-in-from-bottom-3 sm:text-7xl lg:text-[5.4rem]">
                        Plan it once.
                        <br />
                        Publish it <Accent>everywhere.</Accent>
                    </h1>

                    <p className="mx-auto mt-7 max-w-2xl text-base leading-relaxed text-pretty text-muted-foreground motion-safe:animate-in motion-safe:duration-1000 motion-safe:fade-in sm:text-lg">
                        {appName} schedules and publishes to X, Bluesky,
                        LinkedIn, Instagram, Facebook, Threads and Discord, then
                        brings every reply, message and metric back to one
                        place, so your team can plan, post and follow up without
                        opening seven apps.
                    </p>

                    <AuthButtons
                        registrationsEnabled={registrationsEnabled}
                        className="mt-9"
                    />
                    <ArrowLink href={howItWorks()} className="mt-5">
                        See how it works
                    </ArrowLink>
                </div>

                <div className="relative mx-auto mt-14 max-w-5xl pb-10 sm:mt-20 md:pb-24">
                    <div
                        aria-hidden="true"
                        className="absolute inset-x-10 top-10 -z-10 hidden h-2/3 rounded-full bg-primary/30 blur-3xl sm:block"
                    />
                    <AppWindow
                        address="calendar · october"
                        className="motion-safe:animate-in motion-safe:duration-1000 motion-safe:fade-in motion-safe:slide-in-from-bottom-6"
                    >
                        <CalendarMockup />
                    </AppWindow>

                    <ComposerMockup className="absolute -bottom-2 -left-6 hidden w-[330px] -rotate-2 motion-safe:animate-float-slow lg:block" />
                    <PublishedToastMockup className="absolute -top-6 -right-4 hidden motion-safe:animate-float md:flex" />
                    <MetricsMockup className="absolute -right-8 bottom-4 hidden w-[300px] rotate-2 motion-safe:animate-float lg:block" />
                </div>
            </Container>
        </section>
    );
}

function PlatformStrip({ platforms }: { platforms: PlatformOption[] }) {
    return (
        <section aria-labelledby="publishes-to" className="py-10">
            <Container>
                <h2
                    id="publishes-to"
                    className="text-center text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase"
                >
                    Publishes to
                </h2>
                <ul className="mt-6 flex flex-wrap items-center justify-center gap-2.5">
                    {platforms.map((platform) => (
                        <li
                            key={platform.value}
                            className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/70 px-4 py-2 text-sm font-medium text-foreground backdrop-blur transition-colors hover:border-primary/40"
                        >
                            <PlatformGlyph
                                platform={platform.value as PlatformName}
                                size={14}
                            />
                            {platform.label}
                        </li>
                    ))}
                </ul>
            </Container>
        </section>
    );
}

const LOOP = [
    {
        icon: PenLine,
        title: 'Plan',
        body: 'Write once, tailor the text per network, attach media, and drop it on the calendar or into the next open queue slot.',
    },
    {
        icon: CalendarClock,
        title: 'Publish',
        body: 'A scheduler checks for due posts every minute and publishes each account on its own, retrying with backoff when a platform hiccups.',
    },
    {
        icon: Inbox,
        title: 'Follow up',
        body: 'Replies, direct messages and metrics flow back on a schedule that tracks how old each post is, into one inbox and one set of charts.',
    },
];

function Loop() {
    return (
        <Section className="reveal">
            <SectionHeading
                align="center"
                eyebrow="The loop"
                title={
                    <>
                        One place for the <Accent>whole</Accent> life of a post
                    </>
                }
                description="Most tools stop the moment a post goes out. This one keeps going: it watches what comes back and puts it in front of the people who need to answer it."
            />
            <ol className="relative mt-14 grid gap-5 md:grid-cols-3">
                <span
                    aria-hidden="true"
                    className="absolute top-8 right-[16%] left-[16%] hidden h-px bg-gradient-to-r from-transparent via-primary/60 to-transparent md:block"
                />
                {LOOP.map((step, index) => (
                    <li key={step.title}>
                        <Panel className="h-full p-6 text-center">
                            <span className="relative mx-auto flex size-16 items-center justify-center rounded-3xl border border-primary/30 bg-background text-primary-ink shadow-[0_0_0_6px_color-mix(in_oklch,var(--primary)_10%,transparent)]">
                                <step.icon className="size-6" />
                                <span className="absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                                    {index + 1}
                                </span>
                            </span>
                            <h3 className="mt-5 font-[family-name:var(--font-display)] text-2xl font-medium text-foreground">
                                {step.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {step.body}
                            </p>
                        </Panel>
                    </li>
                ))}
            </ol>
        </Section>
    );
}

function BentoCard({
    icon: Icon,
    title,
    body,
    className,
    children,
}: {
    icon: typeof PenLine;
    title: string;
    body: string;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <Panel
            className={cn(
                'group flex flex-col overflow-hidden p-6 transition-colors hover:border-primary/40 sm:p-7',
                className,
            )}
        >
            <span className="flex items-center gap-2.5">
                <span className="flex size-9 items-center justify-center rounded-xl bg-primary/12 text-primary-ink ring-1 ring-primary/25">
                    <Icon className="size-[18px]" />
                </span>
                <h3 className="text-base font-semibold text-foreground">
                    {title}
                </h3>
            </span>
            <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                {body}
            </p>
            {children && (
                <div className="relative mt-6 flex flex-1 items-end justify-center">
                    {children}
                </div>
            )}
        </Panel>
    );
}

function Bento() {
    return (
        <Section id="features">
            <div className="flex flex-col items-start justify-between gap-6 md:flex-row md:items-end">
                <SectionHeading
                    eyebrow="What you get"
                    title={
                        <>
                            Built for the team that runs <Accent>ten</Accent>{' '}
                            accounts, not one
                        </>
                    }
                />
                <ArrowLink href={features()}>Every feature</ArrowLink>
            </div>

            <div className="mt-12 grid gap-4 md:grid-cols-6">
                <BentoCard
                    icon={PenLine}
                    title="A composer that knows each network"
                    body="Character budgets counted the way each platform counts them, per-network edits, threads, media with alt text, and a precheck that stops a post the platform would reject."
                    className="reveal md:col-span-4"
                >
                    <ComposerMockup className="w-full max-w-sm translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
                <BentoCard
                    icon={CalendarClock}
                    title="A queue that fills itself"
                    body="Set weekly posting slots once. “Add to queue” drops each post into the next open one."
                    className="reveal md:col-span-2"
                >
                    <QueueMockup className="w-full translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
                <BentoCard
                    icon={Inbox}
                    title="Every reply in one inbox"
                    body="Replies and DMs from every connected account, with like, reply and archive right there."
                    className="reveal md:col-span-3"
                >
                    <InboxMockup className="w-full translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
                <BentoCard
                    icon={BarChart3}
                    title="Metrics that refresh themselves"
                    body="Likes, comments, reposts and impressions, captured often while a post is fresh and less as it ages."
                    className="reveal md:col-span-3"
                >
                    <MetricsMockup className="w-full max-w-sm translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
                <BentoCard
                    icon={Repeat2}
                    title="Auto-reposts that earn it"
                    body="When a post outperforms your recent work and its engagement levels off, it can be reposted for you."
                    className="reveal md:col-span-3"
                >
                    <RepostMockup className="w-full max-w-sm translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
                <BentoCard
                    icon={Users}
                    title="Workspaces for clients and brands"
                    body="Separate accounts, members and roles per workspace. Nothing crosses between them."
                    className="reveal md:col-span-3"
                >
                    <WorkspaceMockup className="w-full max-w-sm translate-y-8 transition-transform duration-500 group-hover:translate-y-5" />
                </BentoCard>
            </div>
        </Section>
    );
}

function Numbers({
    platformCount,
    mcpToolCount,
}: {
    platformCount: number;
    mcpToolCount: number;
}) {
    const stats = [
        { value: String(platformCount), label: 'networks from one composer' },
        { value: '60s', label: 'between checks for posts that are due' },
        { value: '15 min', label: 'between sweeps for expiring tokens' },
        {
            value: String(mcpToolCount),
            label: 'tools your AI assistant can use',
        },
    ];

    return (
        <section aria-label="By the numbers" className="reveal py-6">
            <Container>
                <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-3xl border border-border/70 bg-border/70 lg:grid-cols-4">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="flex flex-col gap-2 bg-card/80 p-6 sm:p-8"
                        >
                            <dt className="text-sm text-muted-foreground">
                                {stat.label}
                            </dt>
                            <dd className="order-first font-[family-name:var(--font-display)] text-4xl font-medium tracking-tight text-foreground sm:text-5xl">
                                {stat.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </Container>
        </section>
    );
}

function Developers() {
    return (
        <Section>
            <div className="grid items-center gap-12 lg:grid-cols-2">
                <div className="reveal">
                    <SectionHeading
                        eyebrow="For developers"
                        title={
                            <>
                                Script it, or hand it to your{' '}
                                <Accent>AI assistant</Accent>
                            </>
                        }
                        description="A REST API with read or read-and-write keys scoped to one workspace, and an MCP endpoint so assistants like Claude can draft, schedule and queue posts. Anything irreversible waits for a human to confirm."
                    />
                    <ArrowLink href={developers()} className="mt-7">
                        API and MCP reference
                    </ArrowLink>
                </div>
                <div className="reveal grid gap-4">
                    <CodeBlock
                        label="Connect an assistant"
                        code={`claude mcp add --transport http sm-manager \\\n  https://your-instance/mcp`}
                    />
                    <CodeBlock
                        label="Create a draft"
                        code={`curl -X POST https://your-instance/api/v1/posts \\\n  -H "Authorization: Bearer $API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d '{"base_text": "Hello from the API",\n       "destination": {"kind": "all"}}'`}
                    />
                </div>
            </div>
        </Section>
    );
}

function Trust() {
    return (
        <Section className="pt-4">
            <Panel className="reveal grid gap-10 overflow-hidden p-8 sm:p-12 lg:grid-cols-[1fr_1.1fr]">
                <div>
                    <Eyebrow>Security</Eyebrow>
                    <h2 className="mt-4 font-[family-name:var(--font-display)] text-3xl leading-[1.08] font-medium tracking-[-0.02em] text-balance text-foreground sm:text-4xl">
                        Your accounts are the keys to your brand. They are
                        treated that way.
                    </h2>
                    <ArrowLink href={security()} className="mt-7">
                        How your data is protected
                    </ArrowLink>
                </div>
                <CheckList
                    items={[
                        'Platform tokens are encrypted at rest and refreshed before they expire.',
                        'Every workspace is isolated from every other tenant on the instance.',
                        'Every login can add two-factor authentication or a passkey.',
                        'Nothing is posted that a person did not schedule or publish.',
                        'Share links are revocable, can expire, and are stored only as hashes.',
                        'Self-host it and your database stays on your own server.',
                    ]}
                />
            </Panel>
            <p className="mt-8 flex items-center justify-center gap-2 text-center text-sm text-muted-foreground">
                <ShieldCheck className="size-4 text-primary-ink" />
                Curious what each network supports?
                <ArrowLink href={platformsPage()}>Compare platforms</ArrowLink>
            </p>
        </Section>
    );
}

export default function Home({
    appName,
    company,
    platforms,
    registrationsEnabled,
    mcpToolCount,
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
                platformCount={platforms.length}
            />
            <PlatformStrip platforms={platforms} />
            <Loop />
            <Bento />
            <Numbers
                platformCount={platforms.length}
                mcpToolCount={mcpToolCount}
            />
            <Developers />
            <Trust />
            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Your whole month, on <Accent>one</Accent> calendar.
                    </>
                }
                description="Connect your first account in a minute. The scheduler takes it from there."
            />
        </PublicShell>
    );
}

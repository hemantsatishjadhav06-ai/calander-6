import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    Accent,
    ArrowLink,
    CtaBand,
    PageHero,
    Panel,
    Section,
    SectionHeading,
} from '@/components/public/marketing';
import PublicShell from '@/components/public/public-shell';
import {
    ArrowDown,
    Bell,
    Bot,
    CalendarClock,
    Database,
    Globe,
    KeyRound,
    Layers,
    Monitor,
    Plug,
    RefreshCw,
    Repeat2,
    Send,
    ShieldCheck,
    Zap,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { developers, features, security } from '@/routes/product';
import type { PlatformOption, PublicSiteProps } from '@/types/public';

type Band = { max_age_hours: number; interval_minutes: number };

type Props = PublicSiteProps & {
    platforms: PlatformOption[];
    cadence: {
        replies: Band[];
        repliesSteadyMinutes: number;
        repliesMaxBackoff: number;
        metrics: Band[];
        metricsMaxBackoff: number;
    };
    repost: {
        minDelayHours: number;
        maxDelayHours: number;
        plateauStreak: number;
        minPercentile: number;
        baselineDays: number;
    };
};

/** "30 min", "2 hours", "1 day". */
function formatMinutes(minutes: number): string {
    if (minutes % 1440 === 0) {
        const days = minutes / 1440;

        return days === 1 ? 'day' : `${days} days`;
    }

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return hours === 1 ? 'hour' : `${hours} hours`;
    }

    return `${minutes} min`;
}

/** An age boundary: "6 h", "1 day", "7 days". */
function formatAge(hours: number): string {
    if (hours % 24 === 0) {
        const days = hours / 24;

        return days === 1 ? '1 day' : `${days} days`;
    }

    return `${hours} h`;
}

type Step = {
    icon: typeof Send;
    title: string;
    body: ReactNode;
    facts: string[];
};

function Timeline({ steps }: { steps: Step[] }) {
    return (
        <ol className="relative mx-auto mt-14 max-w-3xl">
            <span
                aria-hidden="true"
                className="absolute top-2 bottom-2 left-[1.45rem] w-px bg-gradient-to-b from-primary via-primary/40 to-transparent sm:left-[1.7rem]"
            />
            {steps.map((step, index) => (
                <li
                    key={step.title}
                    className="reveal relative flex gap-5 pb-12 last:pb-0 sm:gap-7"
                >
                    <span className="relative z-10 flex size-12 shrink-0 items-center justify-center rounded-2xl border border-primary/30 bg-background text-primary-ink shadow-[0_0_0_6px_var(--background)] sm:size-14">
                        <step.icon className="size-5 sm:size-6" />
                    </span>
                    <div className="min-w-0 pt-1">
                        <p className="font-mono text-xs text-muted-foreground">
                            Step {String(index + 1).padStart(2, '0')}
                        </p>
                        <h3 className="mt-1 font-[family-name:var(--font-display)] text-2xl font-medium tracking-tight text-foreground sm:text-[1.7rem]">
                            {step.title}
                        </h3>
                        <div className="mt-3 text-[15px] leading-relaxed text-muted-foreground">
                            {step.body}
                        </div>
                        <ul className="mt-4 flex flex-wrap gap-2">
                            {step.facts.map((fact) => (
                                <li
                                    key={fact}
                                    className="rounded-full border border-border/70 bg-card/70 px-3 py-1 font-mono text-[11px] text-foreground"
                                >
                                    {fact}
                                </li>
                            ))}
                        </ul>
                    </div>
                </li>
            ))}
        </ol>
    );
}

/**
 * A polling schedule drawn as consecutive age bands. Each band's lime fill is
 * stronger the more often it polls, so "frequent early, rare later" is visible
 * before any label is read.
 */
function CadenceStrip({
    title,
    description,
    bands,
    tail,
}: {
    title: string;
    description: string;
    bands: Band[];
    tail: { label: string; detail: string };
}) {
    const fastest = Math.min(...bands.map((band) => band.interval_minutes));

    return (
        <Panel className="reveal p-6 sm:p-8">
            <h3 className="text-lg font-semibold text-foreground">{title}</h3>
            <p className="mt-1.5 text-sm text-muted-foreground">
                {description}
            </p>
            <ol
                className={cn(
                    'mt-6 grid gap-2 sm:grid-cols-2',
                    bands.length >= 4
                        ? 'lg:grid-cols-5'
                        : bands.length === 3
                          ? 'lg:grid-cols-4'
                          : 'lg:grid-cols-3',
                )}
            >
                {bands.map((band, index) => {
                    const from = bands[index - 1]?.max_age_hours ?? 0;
                    const strength = fastest / band.interval_minutes;

                    return (
                        <li
                            key={band.max_age_hours}
                            className="relative isolate overflow-hidden rounded-2xl border border-primary/25 p-4"
                        >
                            <span
                                aria-hidden="true"
                                className="absolute inset-0 -z-10 bg-primary"
                                style={{ opacity: 0.08 + strength * 0.32 }}
                            />
                            <span className="block font-mono text-[11px] text-muted-foreground">
                                {from === 0 ? 'Published' : formatAge(from)} →{' '}
                                {formatAge(band.max_age_hours)}
                            </span>
                            <span className="mt-2 block text-base font-semibold text-foreground">
                                Every {formatMinutes(band.interval_minutes)}
                            </span>
                        </li>
                    );
                })}
                <li className="rounded-2xl border border-dashed border-border p-4">
                    <span className="block font-mono text-[11px] text-muted-foreground">
                        {tail.label}
                    </span>
                    <span className="mt-2 block text-base font-semibold text-foreground">
                        {tail.detail}
                    </span>
                </li>
            </ol>
        </Panel>
    );
}

type Node = { icon: typeof Send; title: string; body: string };

function DiagramColumn({
    heading,
    nodes,
    emphasis = false,
}: {
    heading: string;
    nodes: Node[];
    emphasis?: boolean;
}) {
    return (
        <div
            className={cn(
                'rounded-3xl border p-4 sm:p-5',
                emphasis
                    ? 'border-primary/40 bg-primary/[0.06] shadow-[0_0_0_6px_color-mix(in_oklch,var(--primary)_8%,transparent)]'
                    : 'border-border/70 bg-card/70',
            )}
        >
            <p className="text-center text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                {heading}
            </p>
            <ul className="mt-4 grid gap-2.5">
                {nodes.map((node) => (
                    <li
                        key={node.title}
                        className="flex gap-3 rounded-2xl border border-border/70 bg-background/80 p-3"
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary-ink">
                            <node.icon className="size-4" />
                        </span>
                        <span className="grid">
                            <span className="text-sm font-semibold text-foreground">
                                {node.title}
                            </span>
                            <span className="text-xs leading-relaxed text-muted-foreground">
                                {node.body}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Connector() {
    return (
        <div
            aria-hidden="true"
            className="flex items-center justify-center text-primary-ink lg:py-0"
        >
            <ArrowDown className="size-5 lg:-rotate-90" />
        </div>
    );
}

export default function HowItWorks({
    appName,
    company,
    registrationsEnabled,
    platforms,
    cadence,
    repost,
}: Props) {
    const steps: Step[] = [
        {
            icon: Plug,
            title: 'Connect each account once',
            body: (
                <p>
                    X, LinkedIn, Facebook, Instagram and Threads are authorized
                    with OAuth. Bluesky uses an app password or OAuth, and
                    Discord posts through a channel webhook. The tokens each
                    network issues are encrypted before they are stored, and a
                    sweep refreshes any that are about to expire, holding a lock
                    so a single-use refresh token can never be spent twice.
                </p>
            ),
            facts: ['Encrypted at rest', 'Refresh sweep every 15 min'],
        },
        {
            icon: Layers,
            title: 'Compose against real limits',
            body: (
                <p>
                    The composer measures text in each network’s own unit and
                    checks media count, file size, type, video length and aspect
                    ratio against that network’s limits as you type. The same
                    rules run again as a precheck just before publishing, so a
                    post a platform would reject is stopped with a reason
                    instead of failing halfway through.
                </p>
            ),
            facts: ['Same rules in preview and precheck'],
        },
        {
            icon: CalendarClock,
            title: 'Schedule it, queue it, or send it now',
            body: (
                <p>
                    Pick an exact time, or add the post to the queue and it
                    takes the next open slot in the workspace’s weekly posting
                    schedule. Times are shown in the workspace’s timezone, and
                    dragging a post on the calendar simply moves its time.
                </p>
            ),
            facts: ['Weekly posting slots', 'Workspace timezone'],
        },
        {
            icon: Zap,
            title: 'Dispatch, one account at a time',
            body: (
                <p>
                    A scheduler wakes every minute and claims the posts that are
                    due. Every account a post goes to becomes its own job on the
                    queue, so a slow or failing network never holds up the
                    others. A thread is posted part by part, in order.
                </p>
            ),
            facts: ['Checks every 60 s', 'One job per account'],
        },
        {
            icon: RefreshCw,
            title: 'Recover instead of giving up',
            body: (
                <p>
                    When a network returns an error worth retrying, that one
                    account retries with exponential backoff: a minute, then
                    two, then four, capped at an hour, with a little random
                    jitter, and always honouring a platform’s own Retry-After.
                    If a worker dies partway through a thread, the next attempt
                    picks up from the parts already posted rather than posting
                    the start twice. The post’s status rolls up from its
                    accounts: published, partly failed or failed, with one-click
                    retry.
                </p>
            ),
            facts: ['Up to 5 attempts', 'Backoff 1 min → 1 h', 'Thread resume'],
        },
        {
            icon: Bell,
            title: 'Listen, measure and tell you',
            body: (
                <p>
                    Once a post is live, replies, direct messages and metrics
                    are fetched on a schedule tied to the post’s age (below).
                    When something needs a person, like a failed publish, an
                    account that needs reconnecting, or new replies, the right
                    people hear about it.
                </p>
            ),
            facts: ['Age-based polling', 'In-app and email alerts'],
        },
        {
            icon: Repeat2,
            title: 'Resurface what worked',
            body: (
                <p>
                    With auto-repost on, a post that outperforms the account’s
                    recent work is reposted once its engagement levels off,
                    using the network’s native repost. The exact rules are
                    below.
                </p>
            ),
            facts: ['X, LinkedIn, Bluesky', 'Opt-in per account'],
        },
    ];

    const percentile = Math.round(repost.minPercentile * 100);

    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title="How it works" />

            <PageHero
                eyebrow="How it works"
                title={
                    <>
                        From draft to <Accent>delivered</Accent>, and back
                    </>
                }
                description={`What happens to a post after you press Schedule: the jobs, the checks and the timing, described from the code that runs ${appName}.`}
            />

            <Section className="pt-0 sm:pt-0">
                <SectionHeading
                    align="center"
                    eyebrow="The life of a post"
                    title={
                        <>
                            Seven steps, <Accent>zero</Accent> babysitting
                        </>
                    }
                />
                <Timeline steps={steps} />
            </Section>

            <Section id="cadence" className="border-t border-border/60">
                <SectionHeading
                    eyebrow="Polling"
                    title={
                        <>
                            A schedule that <Accent>ages</Accent> with the post
                        </>
                    }
                    description="Most engagement arrives in a post’s first hours, and every API call costs quota. So checks are frequent while a post is fresh and spread out as it ages."
                />
                <div className="mt-12 grid gap-5">
                    <CadenceStrip
                        title="Replies and messages"
                        description={`When a check finds nothing new, the next one waits twice as long, up to ${cadence.repliesMaxBackoff}× the base interval. Replies are never dropped: old posts keep being checked.`}
                        bands={cadence.replies}
                        tail={{
                            label: `After ${formatAge(cadence.replies.at(-1)?.max_age_hours ?? 0)}`,
                            detail: `Every ${formatMinutes(cadence.repliesSteadyMinutes)}, for good`,
                        }}
                    />
                    <CadenceStrip
                        title="Post metrics"
                        description={`When the numbers stop changing, checks back off further, up to ${cadence.metricsMaxBackoff}× the base interval. Account follower counts are captured once a day.`}
                        bands={cadence.metrics}
                        tail={{
                            label: `After ${formatAge(cadence.metrics.at(-1)?.max_age_hours ?? 0)}`,
                            detail: 'Final numbers kept',
                        }}
                    />
                </div>
                <p className="mt-5 text-sm text-muted-foreground">
                    An instance operator can set a slower minimum per network.
                    X’s API is metered, so it defaults to checking at most every
                    six hours.
                </p>
            </Section>

            <Section id="auto-repost" className="border-t border-border/60">
                <div className="grid gap-12 lg:grid-cols-[1fr_1.2fr] lg:items-center">
                    <SectionHeading
                        eyebrow="Auto-repost"
                        title={
                            <>
                                The rules, in <Accent>plain</Accent> numbers
                            </>
                        }
                        description="Reposting is a judgement call, so it is written down. These are the defaults; each account can adjust its own delays."
                    />
                    <Panel className="reveal divide-y divide-border/70">
                        {[
                            {
                                label: 'Earliest',
                                value: `${formatAge(repost.minDelayHours)} after publishing`,
                                detail: 'A post gets its first day on its own before anything is reposted.',
                            },
                            {
                                label: 'Trigger',
                                value: `Engagement flat for ${repost.plateauStreak} checks`,
                                detail: `Or ${formatAge(repost.maxDelayHours)} after publishing, whichever comes first.`,
                            },
                            {
                                label: 'Bar to clear',
                                value: `Better than ${percentile}% of recent posts`,
                                detail: `Compared with the same account’s posts from the last ${repost.baselineDays} days, not a global average.`,
                            },
                            {
                                label: 'Score',
                                value: 'Likes + 2 × (comments + reposts)',
                                detail: 'Comments and reposts count double as stronger signals. Impressions are left out: too inconsistent across networks.',
                            },
                        ].map((rule) => (
                            <div
                                key={rule.label}
                                className="grid gap-1 p-5 sm:grid-cols-[8rem_1fr] sm:gap-5 sm:p-6"
                            >
                                <span className="font-mono text-xs text-muted-foreground sm:pt-1">
                                    {rule.label}
                                </span>
                                <span>
                                    <span className="block font-semibold text-foreground">
                                        {rule.value}
                                    </span>
                                    <span className="mt-1 block text-sm text-muted-foreground">
                                        {rule.detail}
                                    </span>
                                </span>
                            </div>
                        ))}
                    </Panel>
                </div>
            </Section>

            <Section id="architecture" className="border-t border-border/60">
                <SectionHeading
                    align="center"
                    eyebrow="Under the hood"
                    title={
                        <>
                            Small enough to <Accent>self-host</Accent>
                        </>
                    }
                    description={`${appName} is a Laravel application. The published Docker image runs the web server, a queue worker and the scheduler in one container, with optional server-side rendering. Each background process can be switched off and run as its own service instead.`}
                />
                <div className="reveal mt-14 grid gap-3 lg:grid-cols-[1fr_auto_1.2fr_auto_1fr] lg:items-center">
                    <DiagramColumn
                        heading="You"
                        nodes={[
                            {
                                icon: Monitor,
                                title: 'Your team',
                                body: 'The web app, in any browser.',
                            },
                            {
                                icon: KeyRound,
                                title: 'Your scripts',
                                body: 'REST API with scoped keys.',
                            },
                            {
                                icon: Bot,
                                title: 'Your AI assistant',
                                body: 'MCP endpoint over OAuth.',
                            },
                        ]}
                    />
                    <Connector />
                    <DiagramColumn
                        emphasis
                        heading={appName}
                        nodes={[
                            {
                                icon: Globe,
                                title: 'Web server',
                                body: 'Laravel Octane on FrankenPHP.',
                            },
                            {
                                icon: Layers,
                                title: 'Queue worker',
                                body: 'Publishes posts and fetches replies and metrics.',
                            },
                            {
                                icon: CalendarClock,
                                title: 'Scheduler',
                                body: 'The every-minute clock that decides what is due.',
                            },
                            {
                                icon: Database,
                                title: 'Your database',
                                body: 'PostgreSQL or SQLite; media on disk or S3.',
                            },
                        ]}
                    />
                    <Connector />
                    <DiagramColumn
                        heading="Networks"
                        nodes={[
                            {
                                icon: Send,
                                title: `${platforms.length} platform APIs`,
                                body: platforms
                                    .map((platform) => platform.label)
                                    .join(', '),
                            },
                            {
                                icon: ShieldCheck,
                                title: 'Only on your say-so',
                                body: 'Nothing is sent that a person did not schedule.',
                            },
                        ]}
                    />
                </div>
                <div className="mt-10 flex flex-wrap items-center justify-center gap-x-8 gap-y-3">
                    <ArrowLink href={features()}>All features</ArrowLink>
                    <ArrowLink href={developers()}>API and MCP</ArrowLink>
                    <ArrowLink href={security()}>Security</ArrowLink>
                </div>
            </Section>

            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Let the scheduler <Accent>worry</Accent> about timing
                    </>
                }
                description="You write the posts. It handles the minute they go out, the retries, and everything that comes back."
            />
        </PublicShell>
    );
}

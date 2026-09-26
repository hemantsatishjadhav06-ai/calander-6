import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    Accent,
    ArrowLink,
    CheckList,
    CodeBlock,
    CtaBand,
    Eyebrow,
    FeatureTile,
    Section,
    SectionHeading,
} from '@/components/public/marketing';
import {
    AppWindow,
    CalendarMockup,
    ComposerMockup,
    InboxMockup,
    MetricsMockup,
    QueueMockup,
    RepostMockup,
    ShareLinkMockup,
    WorkspaceMockup,
} from '@/components/public/product-mockups';
import PublicShell from '@/components/public/public-shell';
import {
    BarChart3,
    Bell,
    CalendarClock,
    Inbox,
    KeyRound,
    Layers,
    PenLine,
    Repeat2,
    Search,
    Share2,
    Users,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { developers, howItWorks, platforms } from '@/routes/product';
import type { PlatformOption, PublicSiteProps } from '@/types/public';

type Props = PublicSiteProps & { platforms: PlatformOption[] };

const SECTIONS = [
    { id: 'composer', label: 'Composer' },
    { id: 'calendar', label: 'Calendar and queue' },
    { id: 'inbox', label: 'Inbox' },
    { id: 'analytics', label: 'Analytics' },
    { id: 'auto-repost', label: 'Auto-repost' },
    { id: 'sharing', label: 'Review links' },
    { id: 'teams', label: 'Teams' },
];

function Jump() {
    return (
        <nav
            aria-label="On this page"
            className="sticky top-[57px] z-20 border-y border-border/60 bg-background/80 backdrop-blur-xl"
        >
            <ul className="mx-auto flex max-w-6xl [scrollbar-width:none] gap-1 overflow-x-auto px-4 py-2 sm:justify-center sm:px-8">
                {SECTIONS.map((section) => (
                    <li key={section.id} className="shrink-0">
                        <a
                            href={`#${section.id}`}
                            className="block rounded-full px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {section.label}
                        </a>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/**
 * One feature, told as copy on one side and an illustration on the other.
 * `flip` alternates the sides so the page reads as a zig-zag.
 */
function FeatureRow({
    id,
    icon: Icon,
    eyebrow,
    title,
    description,
    points,
    visual,
    flip = false,
    footer,
}: {
    id: string;
    icon: typeof PenLine;
    eyebrow: string;
    title: ReactNode;
    description: ReactNode;
    points: ReactNode[];
    visual: ReactNode;
    flip?: boolean;
    footer?: ReactNode;
}) {
    return (
        <Section id={id} className="py-16 sm:py-24">
            <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                <div className={cn('reveal', flip && 'lg:order-2')}>
                    <span className="flex size-11 items-center justify-center rounded-2xl border border-primary/25 bg-primary/10 text-primary-ink">
                        <Icon className="size-5" />
                    </span>
                    <SectionHeading
                        className="mt-5"
                        eyebrow={eyebrow}
                        title={title}
                        description={description}
                    />
                    <CheckList items={points} className="mt-7" />
                    {footer && <div className="mt-7">{footer}</div>}
                </div>
                <div
                    className={cn(
                        'reveal relative flex justify-center',
                        flip && 'lg:order-1',
                    )}
                >
                    <div
                        aria-hidden="true"
                        className="absolute inset-8 -z-10 rounded-full bg-primary/20 blur-3xl"
                    />
                    {visual}
                </div>
            </div>
        </Section>
    );
}

export default function Features({
    appName,
    company,
    registrationsEnabled,
    platforms: platformOptions,
}: Props) {
    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title="Features" />

            <section className="pt-16 pb-14 text-center sm:pt-24 sm:pb-20">
                <div className="mx-auto max-w-3xl px-4 sm:px-8">
                    <Eyebrow>Features</Eyebrow>
                    <h1 className="mt-5 font-[family-name:var(--font-display)] text-4xl leading-[1.04] font-medium tracking-[-0.02em] text-balance text-foreground sm:text-6xl">
                        Everything a social team <Accent>actually</Accent> uses
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg">
                        From the first draft to the last reply: one composer,
                        one calendar, one inbox and one set of numbers for all{' '}
                        {platformOptions.length} networks. Here is each piece,
                        and what it does for you.
                    </p>
                </div>
            </section>

            <Jump />

            <FeatureRow
                id="composer"
                icon={PenLine}
                eyebrow="Composer"
                title={
                    <>
                        Write once. <Accent>Tailor</Accent> where it matters.
                    </>
                }
                description="Start from one shared draft, then adjust it per network without copying text between tabs. The composer counts, warns and previews the way each platform will."
                points={[
                    'Live character budgets counted in each platform’s own unit: X counts UTF-16, Bluesky counts graphemes.',
                    'Threads on X, Bluesky, Threads and Discord, broken by hand or split automatically.',
                    'Images and video with alt text, a built-in crop and edit step, and optional GIF search.',
                    'Mentions that resolve to the right handle on X, Bluesky and LinkedIn.',
                    'Feed, Reels and Stories formats for Instagram and Facebook.',
                    'A publish precheck that blocks anything a platform would reject, before it is sent.',
                ]}
                visual={<ComposerMockup className="w-full max-w-md" />}
            />

            <FeatureRow
                id="calendar"
                flip
                icon={CalendarClock}
                eyebrow="Calendar and queue"
                title={
                    <>
                        See the month. <Accent>Drag</Accent> to reschedule.
                    </>
                }
                description="Every scheduled and published post on one calendar, in your workspace’s timezone. Or skip picking times altogether and let the queue do it."
                points={[
                    'Month and agenda views across every account in the workspace.',
                    'Drag a post to another day to move it. The scheduler follows.',
                    'Weekly posting slots: “Add to queue” takes the next open one.',
                    'Duplicate a post to reuse it, or save it as a draft for later.',
                ]}
                visual={
                    <div className="grid w-full max-w-xl gap-4">
                        <AppWindow address="calendar · october">
                            <CalendarMockup />
                        </AppWindow>
                        <QueueMockup className="ml-auto w-full max-w-xs -rotate-1 sm:-mt-24 sm:mr-[-1.5rem]" />
                    </div>
                }
            />

            <FeatureRow
                id="inbox"
                icon={Inbox}
                eyebrow="Engagement inbox"
                title={
                    <>
                        Every reply, <Accent>one</Accent> inbox.
                    </>
                }
                description="Replies to your posts and direct messages to your accounts arrive in one list, so nothing waits unanswered in an app nobody opened."
                points={[
                    'Replies from every network that exposes them, with the thread for context.',
                    'Like, reply and archive without leaving the inbox.',
                    'Direct messages for X, Bluesky, Instagram and Facebook.',
                    'Media in replies on X and Bluesky, where the platform allows it.',
                ]}
                visual={<InboxMockup className="w-full max-w-md" />}
                footer={
                    <ArrowLink href={platforms()}>
                        What each platform supports
                    </ArrowLink>
                }
            />

            <FeatureRow
                id="analytics"
                flip
                icon={BarChart3}
                eyebrow="Analytics"
                title={
                    <>
                        Numbers that <Accent>refresh</Accent> themselves.
                    </>
                }
                description="Published posts report back without anyone pressing refresh. Fresh posts are measured closely, older ones less often, so you see early momentum without burning API quota on last month."
                points={[
                    'Likes, comments, reposts and impressions per post, per account.',
                    'Follower counts for accounts, captured daily.',
                    'Capture slows as a post ages and backs off further when nothing changes.',
                    'A manual refresh for the moment you cannot wait.',
                ]}
                visual={<MetricsMockup className="w-full max-w-md" />}
                footer={
                    <ArrowLink href={howItWorks()}>
                        The exact refresh schedule
                    </ArrowLink>
                }
            />

            <FeatureRow
                id="auto-repost"
                icon={Repeat2}
                eyebrow="Auto-repost"
                title={
                    <>
                        Give your best posts a <Accent>second</Accent> life.
                    </>
                }
                description="Turn it on per account. A post that outperforms your recent work is reposted once its engagement levels off, instead of whenever someone remembers."
                points={[
                    'Waits until a post has had its first day, then watches for a plateau.',
                    'Only reposts what beats your own recent posts, not a global average.',
                    'Override per post: always repost this one, or never.',
                    'Uses each platform’s native repost on X, LinkedIn and Bluesky.',
                ]}
                visual={<RepostMockup className="w-full max-w-md" />}
            />

            <FeatureRow
                id="sharing"
                flip
                icon={Share2}
                eyebrow="Review links"
                title={
                    <>
                        Get sign-off <Accent>without</Accent> another login.
                    </>
                }
                description="Send a client or a colleague a read-only preview of a post. They see exactly what will go out; they do not need an account to see it."
                points={[
                    'One link per reviewer, each revocable on its own.',
                    'Optional expiry, so old previews stop working.',
                    'Hidden from search engines and rate limited.',
                ]}
                visual={<ShareLinkMockup className="w-full max-w-sm" />}
            />

            <FeatureRow
                id="teams"
                icon={Users}
                eyebrow="Teams and workspaces"
                title={
                    <>
                        Built for <Accent>more</Accent> than one person.
                    </>
                }
                description="Run each client or brand in its own workspace, with its own accounts, members and posting schedule. Invite people with the role they need."
                points={[
                    'Owner, admin and member roles per workspace.',
                    'Account sets: publish to a named group of accounts in one click.',
                    'Notifications in the app or by email when a post publishes or fails, and in the app when replies arrive.',
                    'A command palette to jump anywhere from the keyboard.',
                ]}
                visual={<WorkspaceMockup className="w-full max-w-sm" />}
            />

            <Section className="py-16 sm:py-24">
                <SectionHeading
                    align="center"
                    eyebrow="And the rest"
                    title={
                        <>
                            The details that <Accent>add up</Accent>
                        </>
                    }
                />
                <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FeatureTile
                        icon={Layers}
                        title="Account sets"
                        className="reveal"
                    >
                        Group the accounts that always post together and target
                        the set instead of ticking boxes.
                    </FeatureTile>
                    <FeatureTile
                        icon={Bell}
                        title="Failure alerts"
                        className="reveal"
                    >
                        Hear about a failed publish or an account that needs
                        reconnecting the moment it happens.
                    </FeatureTile>
                    <FeatureTile
                        icon={Search}
                        title="Search everything"
                        className="reveal"
                    >
                        Find any post by its text from the posts list, or jump
                        to it from the command palette.
                    </FeatureTile>
                    <FeatureTile
                        icon={KeyRound}
                        title="API and MCP"
                        className="reveal"
                    >
                        Automate with scoped API keys, or let an AI assistant
                        work your queue.{' '}
                        <ArrowLink href={developers()} className="mt-2">
                            Developers
                        </ArrowLink>
                    </FeatureTile>
                </div>

                <div className="reveal mx-auto mt-12 max-w-2xl">
                    <CodeBlock
                        label="Queue a post from a script"
                        code={`curl -X POST https://your-instance/api/v1/posts/$POST_ID/queue \\\n  -H "Authorization: Bearer $API_KEY"`}
                    />
                </div>
            </Section>

            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Stop juggling <Accent>seven</Accent> apps.
                    </>
                }
                description="Bring every account your team runs into one workspace, and let the scheduler handle the timing."
            />
        </PublicShell>
    );
}

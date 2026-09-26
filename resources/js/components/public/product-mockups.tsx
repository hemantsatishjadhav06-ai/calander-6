import type { ReactNode } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import AppLogoIcon from '@/components/layout/app-logo-icon';
import {
    Archive,
    BarChart3,
    Calendar,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Heart,
    Home,
    Inbox,
    Link2,
    LockKeyhole,
    MessageCircle,
    MessagesSquare,
    PenLine,
    Plus,
    Repeat2,
    Settings,
    Users,
} from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

/**
 * Illustrations of the product, drawn in markup rather than screenshots so they
 * stay crisp, follow the light/dark theme, and never go stale against a UI
 * change the way a PNG would. They are decoration: every mockup root is
 * aria-hidden, contains nothing focusable, and the surrounding copy carries
 * the meaning.
 */

type Tone = 'scheduled' | 'published';

const chipTone: Record<Tone, string> = {
    scheduled:
        'bg-sky-500/10 text-sky-700 dark:bg-sky-400/15 dark:text-sky-200',
    published: 'bg-muted text-muted-foreground',
};

const stripTone: Record<Tone, string> = {
    scheduled: 'bg-sky-500 dark:bg-sky-400',
    published: 'bg-muted-foreground/40',
};

function Mock({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div aria-hidden="true" className={cn('select-none', className)}>
            {children}
        </div>
    );
}

function Glyphs({
    platforms,
    size = 10,
}: {
    platforms: PlatformName[];
    size?: number;
}) {
    return (
        <span className="inline-flex items-center gap-[3px]">
            {platforms.map((platform) => (
                <PlatformGlyph key={platform} platform={platform} size={size} />
            ))}
        </span>
    );
}

/** A browser-style frame around an app illustration. */
export function AppWindow({
    address,
    className,
    children,
}: {
    address: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-[1.4rem] border border-border/80 bg-card shadow-xl shadow-black/5 sm:shadow-[0_50px_120px_-40px_color-mix(in_oklch,var(--foreground)_40%,transparent)]',
                className,
            )}
        >
            <div className="flex items-center gap-3 border-b border-border/70 bg-muted/50 px-4 py-2.5">
                <span className="flex gap-1.5">
                    <span className="size-2.5 rounded-full bg-[#ff5f57]" />
                    <span className="size-2.5 rounded-full bg-[#febc2e]" />
                    <span className="size-2.5 rounded-full bg-[#28c840]" />
                </span>
                <span className="mx-auto flex max-w-[16rem] min-w-0 flex-1 items-center justify-center gap-1.5 rounded-full border border-border/70 bg-background/80 px-3 py-1 text-[11px] text-muted-foreground">
                    <LockKeyhole className="size-3 shrink-0" />
                    <span className="truncate">{address}</span>
                </span>
                <span className="w-10" />
            </div>
            {children}
        </div>
    );
}

type CalendarPost = {
    time: string;
    text: string;
    platforms: PlatformName[];
    tone: Tone;
    dragging?: boolean;
};

/**
 * Cell index (0–34, Monday-first) → posts. The month is fixed rather than
 * derived from today's date so server and client render identical markup.
 */
const CALENDAR_POSTS: Record<number, CalendarPost[]> = {
    2: [
        {
            time: '9:00a',
            text: 'Q3 recap thread',
            platforms: ['x', 'bluesky'],
            tone: 'published',
        },
    ],
    5: [
        {
            time: '11:30a',
            text: 'Reading list',
            platforms: ['linkedin'],
            tone: 'published',
        },
    ],
    8: [
        {
            time: '8:00a',
            text: 'Launch day',
            platforms: ['x', 'linkedin', 'instagram'],
            tone: 'published',
        },
    ],
    10: [
        {
            time: '2:15p',
            text: 'Behind the build',
            platforms: ['threads', 'bluesky'],
            tone: 'published',
        },
    ],
    16: [
        {
            time: '9:30a',
            text: 'Customer story',
            platforms: ['linkedin', 'x'],
            tone: 'scheduled',
        },
        {
            time: '4:00p',
            text: 'Poll: what next?',
            platforms: ['x', 'threads'],
            tone: 'scheduled',
        },
    ],
    18: [
        {
            time: '10:00a',
            text: 'Changelog 2.4',
            platforms: ['discord', 'x'],
            tone: 'scheduled',
        },
    ],
    21: [
        {
            time: '12:00p',
            text: 'Carousel: 5 tips',
            platforms: ['instagram', 'facebook'],
            tone: 'scheduled',
        },
    ],
    23: [
        {
            time: '6:00p',
            text: 'AMA with the team',
            platforms: ['bluesky', 'threads', 'x'],
            tone: 'scheduled',
            dragging: true,
        },
    ],
    26: [
        {
            time: '11:30a',
            text: 'Reading list',
            platforms: ['linkedin'],
            tone: 'scheduled',
        },
    ],
    30: [
        {
            time: '9:00a',
            text: 'Launch week recap',
            platforms: ['x', 'linkedin', 'bluesky'],
            tone: 'scheduled',
        },
    ],
};

const TODAY_INDEX = 16;
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Day numbers for a Monday-first October that starts on a Thursday. */
function dayNumber(index: number): { day: number; inMonth: boolean } {
    if (index < 3) {
        return { day: 28 + index, inMonth: false };
    }

    const day = index - 2;

    return day > 31
        ? { day: day - 31, inMonth: false }
        : { day, inMonth: true };
}

function CalendarChip({ post }: { post: CalendarPost }) {
    return (
        <>
            <span
                className={cn(
                    'block h-1.5 rounded-full sm:hidden',
                    stripTone[post.tone],
                )}
            />
            <span
                className={cn(
                    'relative hidden items-center gap-1 overflow-hidden rounded-md py-[3px] pr-1.5 pl-2 text-[10px] leading-tight font-medium sm:flex',
                    chipTone[post.tone],
                    post.dragging &&
                        'z-10 -rotate-2 shadow-lg ring-2 shadow-sky-500/20 ring-sky-500/40 motion-safe:animate-float',
                )}
            >
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 w-[3px]',
                        stripTone[post.tone],
                    )}
                />
                <span className="shrink-0 tabular-nums opacity-70">
                    {post.time}
                </span>
                <span className="min-w-0 flex-1 truncate">{post.text}</span>
                <span className="hidden shrink-0 lg:inline-flex">
                    <Glyphs platforms={post.platforms} size={9} />
                </span>
            </span>
        </>
    );
}

const RAIL_ICONS = [Home, Calendar, PenLine, Inbox, MessagesSquare, BarChart3];

/** The month calendar, framed as the app: icon rail on the left. */
export function CalendarMockup({ className }: { className?: string }) {
    return (
        <Mock className={className}>
            <div className="flex">
                <div className="hidden w-14 shrink-0 flex-col items-center gap-2 border-r border-border/70 bg-sidebar py-4 sm:flex">
                    <AppLogoIcon className="mb-3 size-6 text-primary" />
                    {RAIL_ICONS.map((Icon, index) => (
                        <span
                            key={index}
                            className={cn(
                                'flex size-8 items-center justify-center rounded-xl text-muted-foreground',
                                index === 1 &&
                                    'bg-primary/15 text-primary-ink ring-1 ring-primary/30',
                            )}
                        >
                            <Icon className="size-4" />
                        </span>
                    ))}
                    <span className="mt-auto flex size-8 items-center justify-center rounded-xl text-muted-foreground">
                        <Settings className="size-4" />
                    </span>
                </div>

                <div className="min-w-0 flex-1 p-3 sm:p-5">
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span className="font-[family-name:var(--font-display)] text-lg font-medium text-foreground sm:text-xl">
                                October
                            </span>
                            <span className="flex items-center gap-0.5 text-muted-foreground">
                                <ChevronLeft className="size-4" />
                                <ChevronRight className="size-4" />
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="hidden rounded-full border border-border/70 p-0.5 text-[11px] font-medium sm:flex">
                                <span className="rounded-full bg-muted px-2.5 py-0.5 text-foreground">
                                    Month
                                </span>
                                <span className="px-2.5 py-0.5 text-muted-foreground">
                                    Agenda
                                </span>
                            </span>
                            <span className="inline-flex items-center gap-1 rounded-full border border-(--primary-gradient-edge) bg-primary-gradient px-2.5 py-1 text-[11px] font-semibold text-primary-foreground">
                                <Plus className="size-3" strokeWidth={3} />
                                New post
                            </span>
                        </div>
                    </div>

                    <div className="mt-4 grid grid-cols-7 gap-px overflow-hidden rounded-xl border border-border/70 bg-border/70">
                        {WEEKDAYS.map((weekday) => (
                            <span
                                key={weekday}
                                className="bg-muted/60 px-1.5 py-1.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase sm:px-2"
                            >
                                {weekday}
                            </span>
                        ))}
                        {Array.from({ length: 35 }, (_, index) => {
                            const { day, inMonth } = dayNumber(index);
                            const posts = CALENDAR_POSTS[index] ?? [];
                            const isToday = index === TODAY_INDEX;

                            return (
                                <div
                                    key={index}
                                    className={cn(
                                        'flex min-h-12 flex-col gap-1 bg-card p-1 sm:min-h-[4.6rem] sm:p-1.5',
                                        !inMonth && 'bg-muted/30',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-5 items-center justify-center rounded-full text-[10px] tabular-nums',
                                            inMonth
                                                ? 'text-foreground'
                                                : 'text-muted-foreground/60',
                                            isToday &&
                                                'bg-primary font-semibold text-primary-foreground',
                                        )}
                                    >
                                        {day}
                                    </span>
                                    {posts.map((post) => (
                                        <CalendarChip
                                            key={post.text + post.time}
                                            post={post}
                                        />
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </Mock>
    );
}

/** Small ring that fills as a platform's character budget is used. */
function BudgetRing({ used, limit }: { used: number; limit: number }) {
    const radius = 7;
    const circumference = 2 * Math.PI * radius;
    const ratio = Math.min(used / limit, 1);

    return (
        <svg width="18" height="18" viewBox="0 0 18 18" className="-rotate-90">
            <circle
                cx="9"
                cy="9"
                r={radius}
                fill="none"
                strokeWidth="2.5"
                className="stroke-border"
            />
            <circle
                cx="9"
                cy="9"
                r={radius}
                fill="none"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - ratio)}
                className={ratio > 0.8 ? 'stroke-amber-500' : 'stroke-primary'}
            />
        </svg>
    );
}

const COMPOSER_TEXT =
    'Scheduled threads are live. Write once, tailor it per network, and let the queue pick the time.';

const COMPOSER_BUDGETS: { platform: PlatformName; limit: number }[] = [
    { platform: 'x', limit: 280 },
    { platform: 'bluesky', limit: 300 },
    { platform: 'threads', limit: 500 },
    { platform: 'linkedin', limit: 3000 },
];

/** The composer: shared text, per-platform budgets, media, schedule. */
export function ComposerMockup({ className }: { className?: string }) {
    const used = COMPOSER_TEXT.length;

    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/50 px-2.5 py-1 text-[11px] font-medium text-foreground">
                    <Users className="size-3 text-primary-ink" />
                    Launch set · 4 accounts
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                    <Check
                        className="size-3 text-primary-ink"
                        strokeWidth={3}
                    />
                    Saved
                </span>
            </div>

            <p className="mt-3 text-[13px] leading-relaxed text-foreground">
                {COMPOSER_TEXT}{' '}
                <span className="rounded bg-sky-500/10 px-1 text-sky-700 dark:text-sky-300">
                    @atlas
                </span>
            </p>

            <div className="mt-3 flex gap-2">
                {[
                    'from-lime-300 to-emerald-500',
                    'from-sky-300 to-indigo-500',
                ].map((gradient) => (
                    <span
                        key={gradient}
                        className={cn(
                            'relative size-14 rounded-xl bg-gradient-to-br',
                            gradient,
                        )}
                    >
                        <span className="absolute bottom-1 left-1 rounded bg-black/55 px-1 text-[8px] font-bold tracking-wide text-white">
                            ALT
                        </span>
                    </span>
                ))}
                <span className="flex size-14 items-center justify-center rounded-xl border border-dashed border-border text-muted-foreground">
                    <Plus className="size-4" />
                </span>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-1.5">
                {COMPOSER_BUDGETS.map(({ platform, limit }) => (
                    <span
                        key={platform}
                        className="flex items-center justify-between gap-2 rounded-xl border border-border/70 bg-background/60 px-2.5 py-1.5 text-[11px]"
                    >
                        <span className="flex items-center gap-1.5 text-foreground">
                            <PlatformGlyph platform={platform} size={11} />
                            <span className="text-muted-foreground tabular-nums">
                                {used}/{limit.toLocaleString('en-US')}
                            </span>
                        </span>
                        <BudgetRing used={used} limit={limit} />
                    </span>
                ))}
            </div>

            <div className="mt-4 flex items-center justify-between gap-2 border-t border-border/70 pt-3">
                <span className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    <Clock className="size-3.5" />
                    Tue, Oct 21 · 9:30 AM
                </span>
                <span className="rounded-full border border-(--primary-gradient-edge) bg-primary-gradient px-3 py-1 text-[11px] font-semibold text-primary-foreground">
                    Schedule
                </span>
            </div>
        </Mock>
    );
}

/** "Published to N accounts" notification. */
export function PublishedToastMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'flex items-center gap-3 rounded-2xl border border-border/80 bg-card/95 py-3 pr-4 pl-3 shadow-xl shadow-black/10 backdrop-blur',
                className,
            )}
        >
            <span className="flex size-9 items-center justify-center rounded-xl bg-primary/15 text-primary-ink">
                <CheckCircle2 className="size-5" />
            </span>
            <span className="grid">
                <span className="text-[13px] font-semibold text-foreground">
                    Published to 4 accounts
                </span>
                <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    <Glyphs
                        platforms={['x', 'linkedin', 'bluesky', 'threads']}
                    />
                    Launch day · just now
                </span>
            </span>
        </Mock>
    );
}

const SPARK_PATH =
    'M0 46 C 18 44, 26 38, 40 39 S 66 30, 80 31 S 104 18, 120 22 S 146 12, 160 9 S 186 6, 200 4';

/** Metrics card: four counters over a sparkline. */
export function MetricsMockup({ className }: { className?: string }) {
    const stats = [
        { label: 'Likes', value: '1,284' },
        { label: 'Comments', value: '186' },
        { label: 'Reposts', value: '342' },
        { label: 'Impressions', value: '48.2k' },
    ];

    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="text-[12px] font-semibold text-foreground">
                    Launch day
                </span>
                <span className="text-[10px] text-muted-foreground">
                    Refreshed 4 min ago
                </span>
            </div>
            <div className="mt-3 grid grid-cols-4 gap-2">
                {stats.map((stat) => (
                    <span key={stat.label} className="grid">
                        <span className="text-[15px] font-semibold text-foreground tabular-nums">
                            {stat.value}
                        </span>
                        <span className="text-[10px] text-muted-foreground">
                            {stat.label}
                        </span>
                    </span>
                ))}
            </div>
            <svg
                viewBox="0 0 200 50"
                className="mt-3 h-14 w-full overflow-visible"
                preserveAspectRatio="none"
            >
                <defs>
                    <linearGradient id="spark-fill" x1="0" x2="0" y1="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor="var(--primary)"
                            stopOpacity="0.35"
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--primary)"
                            stopOpacity="0"
                        />
                    </linearGradient>
                </defs>
                <path
                    d={`${SPARK_PATH} L200 50 L0 50 Z`}
                    fill="url(#spark-fill)"
                />
                <path
                    d={SPARK_PATH}
                    fill="none"
                    stroke="var(--primary-ink)"
                    strokeWidth="2"
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
        </Mock>
    );
}

type InboxItem = {
    name: string;
    handle: string;
    platform: PlatformName;
    text: string;
    time: string;
    unread?: boolean;
    liked?: boolean;
};

const INBOX: InboxItem[] = [
    {
        name: 'Maya Chen',
        handle: '@maya.bsky.social',
        platform: 'bluesky',
        text: 'Scheduled threads are exactly what we needed. Does it split long posts on its own?',
        time: '2m',
        unread: true,
    },
    {
        name: 'Dev Patel',
        handle: '@devpatel',
        platform: 'x',
        text: 'Connected our Discord too. One queue for everything.',
        time: '14m',
        liked: true,
    },
    {
        name: 'Northwind',
        handle: 'LinkedIn Page',
        platform: 'linkedin',
        text: 'Congrats on the launch! Sharing this with the team.',
        time: '1h',
    },
];

/** The engagement inbox: replies from every platform in one list. */
export function InboxMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-2 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="flex items-center gap-1 px-2 pt-2 pb-3 text-[11px] font-medium">
                <span className="rounded-full bg-muted px-2.5 py-1 text-foreground">
                    Replies · 12
                </span>
                <span className="px-2.5 py-1 text-muted-foreground">
                    Messages · 3
                </span>
                <span className="px-2.5 py-1 text-muted-foreground">
                    Archived
                </span>
            </div>
            <div className="grid gap-1">
                {INBOX.map((item) => (
                    <div
                        key={item.name}
                        className={cn(
                            'flex gap-3 rounded-2xl p-3',
                            item.unread && 'bg-primary/[0.07]',
                        )}
                    >
                        <span className="relative shrink-0">
                            <span className="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-muted to-border text-[11px] font-semibold text-foreground">
                                {item.name
                                    .split(' ')
                                    .map((part) => part[0])
                                    .join('')}
                            </span>
                            <span className="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full border border-border bg-card text-foreground">
                                <PlatformGlyph
                                    platform={item.platform}
                                    size={8}
                                />
                            </span>
                        </span>
                        <span className="grid min-w-0 flex-1 gap-0.5">
                            <span className="flex items-center gap-1.5 text-[12px]">
                                <span className="font-semibold text-foreground">
                                    {item.name}
                                </span>
                                <span className="truncate text-muted-foreground">
                                    {item.handle}
                                </span>
                                <span className="ml-auto shrink-0 text-muted-foreground">
                                    {item.time}
                                </span>
                                {item.unread && (
                                    <span className="size-1.5 shrink-0 rounded-full bg-primary" />
                                )}
                            </span>
                            <span className="text-[12px] leading-snug text-muted-foreground">
                                {item.text}
                            </span>
                            <span className="mt-1 flex items-center gap-3 text-muted-foreground">
                                <Heart
                                    className={cn(
                                        'size-3.5',
                                        item.liked && 'text-rose-500',
                                    )}
                                />
                                <MessageCircle className="size-3.5" />
                                <Archive className="size-3.5" />
                            </span>
                        </span>
                    </div>
                ))}
            </div>
        </Mock>
    );
}

const QUEUE_SLOTS = ['9:00', '12:30', '17:00'];
const QUEUE_DAYS: {
    day: string;
    filled: (PlatformName[] | null)[];
}[] = [
    { day: 'Mon', filled: [['x', 'linkedin'], ['bluesky'], ['threads']] },
    { day: 'Tue', filled: [['linkedin'], ['x', 'bluesky'], null] },
    { day: 'Wed', filled: [['instagram'], null, null] },
    { day: 'Thu', filled: [null, null, null] },
    { day: 'Fri', filled: [null, null, null] },
];

/** The weekly posting schedule with the next open slot highlighted. */
export function QueueMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="text-[12px] font-semibold text-foreground">
                    Posting schedule
                </span>
                <span className="text-[10px] text-muted-foreground">
                    Europe/London
                </span>
            </div>
            <div className="mt-3 grid grid-cols-[2.5rem_repeat(3,1fr)] gap-1.5 text-[10px]">
                <span />
                {QUEUE_SLOTS.map((slot) => (
                    <span
                        key={slot}
                        className="text-center font-medium text-muted-foreground tabular-nums"
                    >
                        {slot}
                    </span>
                ))}
                {QUEUE_DAYS.map(({ day, filled }) => (
                    <div key={day} className="contents">
                        <span className="self-center font-medium text-muted-foreground">
                            {day}
                        </span>
                        {filled.map((platforms, index) => {
                            const isNext = day === 'Tue' && index === 2;

                            return (
                                <span
                                    key={index}
                                    className={cn(
                                        'flex h-7 items-center justify-center rounded-lg',
                                        platforms
                                            ? 'bg-sky-500/10 text-sky-700 dark:bg-sky-400/15 dark:text-sky-200'
                                            : 'border border-dashed border-border',
                                        isNext &&
                                            'border-solid border-primary bg-primary/10 ring-3 ring-primary/20',
                                    )}
                                >
                                    {platforms && (
                                        <Glyphs
                                            platforms={platforms}
                                            size={9}
                                        />
                                    )}
                                    {isNext && (
                                        <span className="text-[9px] font-semibold text-primary-ink">
                                            Next
                                        </span>
                                    )}
                                </span>
                            );
                        })}
                    </div>
                ))}
            </div>
        </Mock>
    );
}

/** A revocable, expiring preview link for a post. */
export function ShareLinkMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <span className="text-[12px] font-semibold text-foreground">
                Share for review
            </span>
            <span className="mt-3 flex items-center gap-2 rounded-xl border border-border/70 bg-background/70 px-3 py-2 font-mono text-[11px] text-muted-foreground">
                <Link2 className="size-3.5 shrink-0 text-primary-ink" />
                <span className="truncate">/share/Qm7Kd2vX…pL0a</span>
            </span>
            <span className="mt-3 flex items-center justify-between text-[11px]">
                <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                    <Clock className="size-3.5" />
                    Expires in 7 days
                </span>
                <span className="rounded-full border border-destructive/30 px-2.5 py-0.5 font-medium text-destructive">
                    Revoke
                </span>
            </span>
            <span className="mt-4 block rounded-2xl border border-border/70 p-3">
                <span className="flex items-center gap-2">
                    <span className="size-6 rounded-full bg-gradient-to-br from-lime-300 to-emerald-500" />
                    <span className="text-[11px] font-semibold text-foreground">
                        Acme Studio
                    </span>
                    <span className="ml-auto text-muted-foreground">
                        <Glyphs platforms={['linkedin', 'x']} />
                    </span>
                </span>
                <span className="mt-2 block text-[11px] leading-snug text-muted-foreground">
                    A read-only preview. No account needed to view it.
                </span>
            </span>
        </Mock>
    );
}

/**
 * Engagement over time: it climbs, flattens, and the repost fires on the
 * plateau. The curve is illustrative, not data.
 */
export function RepostMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="flex items-center justify-between">
                <span className="text-[12px] font-semibold text-foreground">
                    Engagement
                </span>
                <span className="inline-flex items-center gap-1 rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-semibold text-primary-ink">
                    <Repeat2 className="size-3" />
                    Reposted
                </span>
            </div>
            <svg viewBox="0 0 240 90" className="mt-3 h-28 w-full">
                <line
                    x1="0"
                    x2="240"
                    y1="80"
                    y2="80"
                    className="stroke-border"
                    strokeWidth="1"
                />
                <path
                    d="M4 78 C 30 70, 44 36, 76 28 S 120 20, 150 19 L 160 19"
                    fill="none"
                    stroke="var(--primary-ink)"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                />
                <path
                    d="M160 19 C 176 18, 184 8, 204 6 S 230 4, 236 4"
                    fill="none"
                    stroke="var(--primary-ink)"
                    strokeWidth="2.5"
                    strokeDasharray="4 5"
                    strokeLinecap="round"
                />
                <line
                    x1="160"
                    x2="160"
                    y1="10"
                    y2="80"
                    className="stroke-primary"
                    strokeDasharray="3 4"
                />
                <circle cx="160" cy="19" r="5" className="fill-primary" />
                <circle
                    cx="160"
                    cy="19"
                    r="9"
                    className="fill-primary/25 motion-safe:animate-ping"
                />
            </svg>
            <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                <span>Published</span>
                <span className="font-medium text-foreground">
                    Plateau → repost
                </span>
            </div>
        </Mock>
    );
}

const MEMBERS = [
    { name: 'Ana Ruiz', role: 'Owner' },
    { name: 'Sam Okafor', role: 'Admin' },
    { name: 'Lee Park', role: 'Member' },
];

/** Workspace switcher and members with roles. */
export function WorkspaceMockup({ className }: { className?: string }) {
    return (
        <Mock
            className={cn(
                'rounded-3xl border border-border/80 bg-card p-4 shadow-2xl shadow-black/10',
                className,
            )}
        >
            <div className="grid grid-cols-2 gap-2">
                {['Acme Studio', 'Northwind'].map((workspace, index) => (
                    <span
                        key={workspace}
                        className={cn(
                            'flex items-center gap-2 rounded-2xl border px-3 py-2 text-[11px] font-semibold',
                            index === 0
                                ? 'border-primary/40 bg-primary/10 text-foreground'
                                : 'border-border/70 text-muted-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'size-5 rounded-lg bg-gradient-to-br',
                                index === 0
                                    ? 'from-lime-300 to-emerald-500'
                                    : 'from-sky-300 to-indigo-500',
                            )}
                        />
                        {workspace}
                    </span>
                ))}
            </div>
            <div className="mt-3 grid gap-1">
                {MEMBERS.map((member) => (
                    <span
                        key={member.name}
                        className="flex items-center gap-2.5 rounded-xl px-2 py-1.5"
                    >
                        <span className="flex size-7 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-foreground">
                            {member.name
                                .split(' ')
                                .map((part) => part[0])
                                .join('')}
                        </span>
                        <span className="text-[12px] text-foreground">
                            {member.name}
                        </span>
                        <span className="ml-auto rounded-full border border-border/70 px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                            {member.role}
                        </span>
                    </span>
                ))}
            </div>
        </Mock>
    );
}

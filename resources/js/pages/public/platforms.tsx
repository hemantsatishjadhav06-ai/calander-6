import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
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
import { Check, Minus } from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { features, howItWorks } from '@/routes/product';
import type { PlatformName } from '@/types/compose';
import type { PublicSiteProps } from '@/types/public';

type PlatformDetail = {
    value: PlatformName;
    label: string;
    connection: 'oauth' | 'app-password' | 'webhook';
    maxLength: number;
    lengthUnit: 'utf16' | 'graphemes' | 'characters';
    maxMedia: number;
    maxVideoSeconds: number;
    threads: boolean;
    requiresMedia: boolean;
    mixesVideoAndImages: boolean;
    replies: boolean;
    replyLikes: boolean;
    replyMedia: boolean;
    directMessages: boolean;
    directMessageMedia: boolean;
    postMetrics: boolean;
    accountMetrics: boolean;
    autoRepost: boolean;
};

type Props = PublicSiteProps & { platforms: PlatformDetail[] };

/**
 * How each network is connected, in words. Keyed by platform where the
 * connection kind alone does not say enough (which Meta flow, Pages or
 * profiles), with a fallback on the kind for any platform added later.
 */
const CONNECTION_COPY: Partial<Record<PlatformName, string>> = {
    x: 'OAuth 2.0 sign-in',
    bluesky: 'App password or OAuth',
    linkedin: 'OAuth, for profiles and Pages',
    facebook: 'Facebook Login, for Pages',
    instagram: 'Facebook Login, via a linked Page',
    threads: 'OAuth sign-in with Threads',
    discord: 'A channel webhook',
};

const CONNECTION_FALLBACK: Record<PlatformDetail['connection'], string> = {
    oauth: 'OAuth sign-in',
    'app-password': 'App password',
    webhook: 'Webhook',
};

const UNIT_COPY: Record<PlatformDetail['lengthUnit'], string> = {
    utf16: 'UTF-16 units',
    graphemes: 'graphemes',
    characters: 'characters',
};

function formatDuration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    if (hours > 0) {
        return minutes > 0 ? `${hours} h ${minutes} min` : `${hours} h`;
    }

    if (rest > 0) {
        return minutes > 0 ? `${minutes} min ${rest} s` : `${rest} s`;
    }

    return `${minutes} min`;
}

type Row = {
    label: string;
    render: (platform: PlatformDetail) => ReactNode;
};

function Yes({ on }: { on: boolean }) {
    return on ? (
        <span className="inline-flex size-6 items-center justify-center rounded-full bg-primary/15 text-primary-ink">
            <Check className="size-3.5" strokeWidth={3} />
            <span className="sr-only">Yes</span>
        </span>
    ) : (
        <span className="inline-flex size-6 items-center justify-center rounded-full text-muted-foreground/50">
            <Minus className="size-3.5" />
            <span className="sr-only">No</span>
        </span>
    );
}

const ROW_GROUPS: { title: string; rows: Row[] }[] = [
    {
        title: 'Publishing',
        rows: [
            {
                label: 'Post length',
                render: (platform) => (
                    <span className="grid">
                        <span className="font-semibold text-foreground tabular-nums">
                            {platform.maxLength.toLocaleString('en-US')}
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {UNIT_COPY[platform.lengthUnit]}
                        </span>
                    </span>
                ),
            },
            {
                label: 'Media per post',
                render: (platform) => (
                    <span className="font-semibold text-foreground tabular-nums">
                        {platform.maxMedia}
                    </span>
                ),
            },
            {
                label: 'Longest video',
                render: (platform) => (
                    <span className="font-semibold whitespace-nowrap text-foreground tabular-nums">
                        {formatDuration(platform.maxVideoSeconds)}
                    </span>
                ),
            },
            {
                label: 'Threading',
                render: (platform) => <Yes on={platform.threads} />,
            },
            {
                label: 'Video and images together',
                render: (platform) => <Yes on={platform.mixesVideoAndImages} />,
            },
            {
                label: 'Needs an image or video',
                render: (platform) => <Yes on={platform.requiresMedia} />,
            },
        ],
    },
    {
        title: 'Engagement',
        rows: [
            {
                label: 'Replies in the inbox',
                render: (platform) => <Yes on={platform.replies} />,
            },
            {
                label: 'Like replies',
                render: (platform) => <Yes on={platform.replyLikes} />,
            },
            {
                label: 'Media in replies',
                render: (platform) => <Yes on={platform.replyMedia} />,
            },
            {
                label: 'Direct messages',
                render: (platform) => <Yes on={platform.directMessages} />,
            },
            {
                label: 'Media in messages',
                render: (platform) => <Yes on={platform.directMessageMedia} />,
            },
        ],
    },
    {
        title: 'Measurement',
        rows: [
            {
                label: 'Post metrics',
                render: (platform) => <Yes on={platform.postMetrics} />,
            },
            {
                label: 'Account metrics',
                render: (platform) => <Yes on={platform.accountMetrics} />,
            },
            {
                label: 'Auto-repost',
                render: (platform) => <Yes on={platform.autoRepost} />,
            },
        ],
    },
];

function PlatformCard({ platform }: { platform: PlatformDetail }) {
    const highlights = [
        { label: 'Threading', on: platform.threads },
        { label: 'Replies', on: platform.replies },
        { label: 'DMs', on: platform.directMessages },
        { label: 'Auto-repost', on: platform.autoRepost },
    ];

    return (
        <Panel className="reveal group flex flex-col p-6 transition-colors hover:border-primary/40">
            <div className="flex items-center gap-3">
                <span className="flex size-12 items-center justify-center rounded-2xl border border-border/70 bg-background text-foreground transition-colors group-hover:border-primary/40 group-hover:text-primary-ink">
                    <PlatformGlyph platform={platform.value} size={22} />
                </span>
                <div className="grid">
                    <h3 className="text-lg font-semibold text-foreground">
                        {platform.label}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {CONNECTION_COPY[platform.value] ??
                            CONNECTION_FALLBACK[platform.connection]}
                    </p>
                </div>
            </div>
            <dl className="mt-6 grid grid-cols-3 gap-3 border-y border-border/70 py-4">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[11px] text-muted-foreground">
                        Length
                    </dt>
                    <dd className="order-first text-lg font-semibold text-foreground tabular-nums">
                        {platform.maxLength.toLocaleString('en-US')}
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[11px] text-muted-foreground">Media</dt>
                    <dd className="order-first text-lg font-semibold text-foreground tabular-nums">
                        {platform.maxMedia}
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-[11px] text-muted-foreground">Video</dt>
                    <dd className="order-first text-lg font-semibold whitespace-nowrap text-foreground tabular-nums">
                        {formatDuration(platform.maxVideoSeconds)}
                    </dd>
                </div>
            </dl>
            <ul className="mt-4 flex flex-wrap gap-1.5">
                {highlights.map((highlight) => (
                    <li
                        key={highlight.label}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-medium',
                            highlight.on
                                ? 'border-primary/30 bg-primary/10 text-foreground'
                                : 'border-border/70 text-muted-foreground line-through decoration-muted-foreground/40',
                        )}
                    >
                        {highlight.on && (
                            <Check
                                className="size-3 text-primary-ink"
                                strokeWidth={3}
                            />
                        )}
                        {highlight.label}
                        {!highlight.on && (
                            <span className="sr-only"> (not supported)</span>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function Matrix({ platforms }: { platforms: PlatformDetail[] }) {
    return (
        <Panel className="reveal mt-12 overflow-hidden">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] border-collapse text-left text-sm">
                    <caption className="sr-only">
                        What each platform supports
                    </caption>
                    <thead>
                        <tr className="border-b border-border/70">
                            <th
                                scope="col"
                                className="sticky left-0 z-10 bg-card px-5 py-4 text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                            >
                                Capability
                            </th>
                            {platforms.map((platform) => (
                                <th
                                    key={platform.value}
                                    scope="col"
                                    className="px-3 py-4 text-center"
                                >
                                    <span className="inline-flex flex-col items-center gap-1.5 text-xs font-semibold text-foreground">
                                        <PlatformGlyph
                                            platform={platform.value}
                                            size={16}
                                        />
                                        {platform.label}
                                    </span>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    {ROW_GROUPS.map((group) => (
                        <tbody key={group.title}>
                            <tr>
                                <th
                                    scope="rowgroup"
                                    colSpan={platforms.length + 1}
                                    className="bg-muted/50 px-5 py-2 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                >
                                    {group.title}
                                </th>
                            </tr>
                            {group.rows.map((row) => (
                                <tr
                                    key={row.label}
                                    className="border-t border-border/60 transition-colors hover:bg-muted/30"
                                >
                                    <th
                                        scope="row"
                                        className="sticky left-0 z-10 bg-card px-5 py-3 font-medium text-foreground"
                                    >
                                        {row.label}
                                    </th>
                                    {platforms.map((platform) => (
                                        <td
                                            key={platform.value}
                                            className="px-3 py-3 text-center"
                                        >
                                            {row.render(platform)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    ))}
                </table>
            </div>
        </Panel>
    );
}

export default function Platforms({
    appName,
    company,
    registrationsEnabled,
    platforms,
}: Props) {
    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title="Platforms" />

            <PageHero
                eyebrow="Platforms"
                title={
                    <>
                        {platforms.length} networks. <Accent>One</Accent>{' '}
                        composer.
                    </>
                }
                description="Every network has its own limits and its own API. These are the ones this app enforces and uses, read from the same code that checks your posts before they go out."
            >
                <ul className="mt-10 flex flex-wrap items-center justify-center gap-3">
                    {platforms.map((platform) => (
                        <li
                            key={platform.value}
                            className="flex size-14 items-center justify-center rounded-2xl border border-border/70 bg-card/80 text-foreground shadow-sm backdrop-blur transition-transform hover:-translate-y-1"
                        >
                            <PlatformGlyph
                                platform={platform.value}
                                size={22}
                            />
                            <span className="sr-only">{platform.label}</span>
                        </li>
                    ))}
                </ul>
            </PageHero>

            <Section className="pt-0 sm:pt-0">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {platforms.map((platform) => (
                        <PlatformCard
                            key={platform.value}
                            platform={platform}
                        />
                    ))}
                </div>
            </Section>

            <Section id="compare" className="border-t border-border/60">
                <SectionHeading
                    eyebrow="Side by side"
                    title={
                        <>
                            What each network <Accent>supports</Accent>
                        </>
                    }
                    description="Where a network’s API does not offer something, the app hides the control instead of letting it fail when you press send."
                />
                <Matrix platforms={platforms} />
                <ul className="mt-6 grid gap-2 text-sm text-muted-foreground sm:grid-cols-2">
                    <li>
                        X counts text in UTF-16 units, so most emoji count as
                        two. Bluesky counts graphemes, which is what you see as
                        one character.
                    </li>
                    <li>
                        X Premium accounts can upload longer video. The app
                        reads the account’s tier and raises the limit to match.
                    </li>
                    <li>
                        LinkedIn Pages report post metrics. Personal LinkedIn
                        profiles do not expose them.
                    </li>
                    <li>
                        Discord posts through a webhook, which can send but
                        cannot read, so there is no inbox for it.
                    </li>
                </ul>
                <div className="mt-10 flex flex-wrap gap-x-8 gap-y-3">
                    <ArrowLink href={features()}>What you can do</ArrowLink>
                    <ArrowLink href={howItWorks()}>
                        How publishing works
                    </ArrowLink>
                </div>
            </Section>

            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Connect them <Accent>all</Accent>
                    </>
                }
                description="Authorize each account once. Tokens are encrypted and kept fresh, so a connection does not quietly die mid-campaign."
            />
        </PublicShell>
    );
}

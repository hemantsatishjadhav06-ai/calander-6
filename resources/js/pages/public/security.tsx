import { Head, Link } from '@inertiajs/react';

import {
    Accent,
    CheckList,
    CtaBand,
    PageHero,
    Panel,
    Section,
    SectionHeading,
} from '@/components/public/marketing';
import PublicShell from '@/components/public/public-shell';
import {
    Bot,
    Fingerprint,
    KeyRound,
    Layers,
    Link2,
    LockKeyhole,
    Mail,
    RefreshCw,
    Server,
    ShieldCheck,
} from '@/components/ui/icons';
import { dataDeletion, privacy, terms } from '@/routes/legal';
import type { PublicSiteProps } from '@/types/public';

type Practice = {
    icon: typeof ShieldCheck;
    title: string;
    body: string;
    detail: string;
};

/**
 * Each practice names the mechanism behind it (the `detail` line), so a
 * reader can check the claim against the code rather than take it on trust.
 */
const PRACTICES: Practice[] = [
    {
        icon: LockKeyhole,
        title: 'Tokens encrypted at rest',
        body: 'Access tokens, refresh tokens, app passwords and sessions for every connected account are encrypted before they reach the database, with a key that lives only on the server.',
        detail: 'AES-256-CBC · per-field encryption',
    },
    {
        icon: Layers,
        title: 'Workspaces that stay apart',
        body: 'Every web and API request is scoped to the workspace it runs in, so a member of one workspace cannot read another’s posts, accounts or media. When no workspace is resolved, the scope fails closed.',
        detail: 'Global workspace scope · fails closed',
    },
    {
        icon: Fingerprint,
        title: 'Strong sign-in',
        body: 'Two-factor authentication with recovery codes, passkeys, email verification, and a password re-check before sensitive changes. Repeated login attempts are throttled.',
        detail: 'TOTP · WebAuthn · 5 attempts a minute',
    },
    {
        icon: KeyRound,
        title: 'API keys with limits',
        body: 'Keys are read-only or read-and-write, belong to a single workspace, can expire, and stop working the moment the member who made them leaves it. The full key is shown once.',
        detail: 'Scoped tokens · 60 requests a minute',
    },
    {
        icon: Bot,
        title: 'AI with a person in the loop',
        body: 'Assistants connect through an OAuth consent screen and are bound to one workspace. Publishing, retrying and deleting refuse to run until a person confirms.',
        detail: 'MCP over OAuth · confirm=true gates',
    },
    {
        icon: Link2,
        title: 'Review links you control',
        body: 'Each link is 43 random characters, stored only as a hash. Links can expire, can be revoked one at a time, are hidden from search engines, and are rate limited.',
        detail: 'SHA-256 at rest · noindex · 30 views a minute',
    },
    {
        icon: ShieldCheck,
        title: 'A hardened browser surface',
        body: 'A strict Content Security Policy with a fresh nonce on every request, clickjacking protection, no MIME sniffing, a strict referrer policy, and HSTS in production.',
        detail: 'CSP nonce · X-Frame-Options DENY',
    },
    {
        icon: RefreshCw,
        title: 'Connections that stay healthy',
        body: 'Tokens are refreshed before they expire, under a lock so a single-use refresh token is never replayed. If an account does need reconnecting, you are told.',
        detail: 'Refresh sweep every 15 min',
    },
    {
        icon: Server,
        title: 'Yours to host',
        body: 'Run the Docker image on your own infrastructure with PostgreSQL or SQLite, and media on your own disk or S3-compatible bucket. Your posts, tokens and analytics live in your database.',
        detail: 'Open source · self-hostable',
    },
];

export default function Security({
    appName,
    company,
    contactEmail,
    registrationsEnabled,
}: PublicSiteProps) {
    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title="Security" />

            <PageHero
                eyebrow="Security"
                title={
                    <>
                        Your accounts are the <Accent>keys</Accent> to your
                        brand
                    </>
                }
                description={`${appName} holds the tokens that can post as you on seven networks. Here is exactly how it protects them, and everything around them.`}
            />

            <Section className="pt-0 sm:pt-0">
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {PRACTICES.map((practice) => (
                        <li key={practice.title}>
                            <Panel className="reveal group flex h-full flex-col p-6 transition-colors hover:border-primary/40">
                                <span className="flex size-11 items-center justify-center rounded-2xl border border-primary/25 bg-primary/10 text-primary-ink transition-transform group-hover:-translate-y-0.5">
                                    <practice.icon className="size-5" />
                                </span>
                                <h2 className="mt-5 text-base font-semibold text-foreground">
                                    {practice.title}
                                </h2>
                                <p className="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                                    {practice.body}
                                </p>
                                <p className="mt-5 border-t border-border/70 pt-4 font-mono text-[11px] text-muted-foreground">
                                    {practice.detail}
                                </p>
                            </Panel>
                        </li>
                    ))}
                </ul>
            </Section>

            <Section className="border-t border-border/60">
                <div className="grid gap-12 lg:grid-cols-2 lg:items-center">
                    <SectionHeading
                        eyebrow="Control"
                        title={
                            <>
                                Nothing posts <Accent>without</Accent> a person
                            </>
                        }
                        description="Automation should save you time, not surprise you. Every path to a live post starts with someone deciding it should go."
                    />
                    <Panel className="reveal p-7 sm:p-8">
                        <CheckList
                            items={[
                                'Posts go out only when a member schedules, queues or publishes them.',
                                'Auto-repost is off until someone turns it on for an account, and any post can opt out.',
                                'AI assistants can draft and queue, but publishing now waits for your confirmation.',
                                'A publish the platform would reject is stopped before it is sent, with the reason.',
                                'Deleting a published post removes it from the networks too, where their APIs allow it.',
                            ]}
                        />
                    </Panel>
                </div>
            </Section>

            <Section className="border-t border-border/60">
                <SectionHeading
                    align="center"
                    eyebrow="Your data"
                    title={
                        <>
                            Read the <Accent>fine</Accent> print
                        </>
                    }
                    description="What is collected, why, who it is shared with, and how to have it deleted, written from what the code actually stores."
                />
                <div className="mx-auto mt-12 grid max-w-4xl gap-4 sm:grid-cols-3">
                    {[
                        {
                            href: privacy(),
                            title: 'Privacy policy',
                            body: 'What is stored, and what is not.',
                        },
                        {
                            href: terms(),
                            title: 'Terms of service',
                            body: 'The rules for using this instance.',
                        },
                        {
                            href: dataDeletion(),
                            title: 'Delete your data',
                            body: 'Remove your account and everything in it.',
                        },
                    ].map((doc) => (
                        <Link
                            key={doc.title}
                            href={doc.href}
                            className="group rounded-3xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <Panel className="reveal h-full p-6 transition-colors group-hover:border-primary/40">
                                <h3 className="font-semibold text-foreground group-hover:text-primary-ink">
                                    {doc.title}
                                </h3>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {doc.body}
                                </p>
                            </Panel>
                        </Link>
                    ))}
                </div>

                {contactEmail && (
                    <Panel className="reveal mx-auto mt-8 flex max-w-4xl flex-col items-start gap-4 p-6 sm:flex-row sm:items-center">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary-ink">
                            <Mail className="size-5" />
                        </span>
                        <p className="text-sm text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                Found a vulnerability?
                            </span>{' '}
                            Please report it privately to{' '}
                            <a
                                href={`mailto:${contactEmail}`}
                                className="font-medium text-primary-ink underline underline-offset-4"
                            >
                                {contactEmail}
                            </a>{' '}
                            so it can be fixed before it is disclosed.
                        </p>
                    </Panel>
                )}
            </Section>

            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Post with <Accent>confidence</Accent>
                    </>
                }
                description="Connect your accounts knowing exactly how they are protected."
            />
        </PublicShell>
    );
}

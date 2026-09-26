import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import {
    Accent,
    ArrowLink,
    CheckList,
    CodeBlock,
    CtaBand,
    PageHero,
    Panel,
    Section,
    SectionHeading,
} from '@/components/public/marketing';
import PublicShell from '@/components/public/public-shell';
import { Bot, Code, ShieldCheck, Terminal } from '@/components/ui/icons';
import { cn } from '@/lib/utils';
import { howItWorks, security } from '@/routes/product';
import type { PublicSiteProps } from '@/types/public';

type McpTool = { name: string; description: string };

type Props = PublicSiteProps & {
    apiBaseUrl: string;
    mcpUrl: string;
    mcpTools: McpTool[];
};

type Endpoint = {
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE';
    path: string;
    does: string;
};

const READ_ENDPOINTS: Endpoint[] = [
    {
        method: 'GET',
        path: '/connected-accounts',
        does: 'Accounts in the workspace and their status',
    },
    {
        method: 'GET',
        path: '/posts',
        does: 'Posts, filterable by status or text',
    },
    {
        method: 'GET',
        path: '/posts/{id}',
        does: 'One post with per-account publish results',
    },
    {
        method: 'GET',
        path: '/calendar?month=YYYY-MM',
        does: 'Scheduled and published posts for a month',
    },
    {
        method: 'GET',
        path: '/posting-schedule',
        does: 'Timezone and weekly queue slots',
    },
    { method: 'GET', path: '/account-sets', does: 'Named groups of accounts' },
    {
        method: 'GET',
        path: '/posts/{id}/shares',
        does: 'Active review links for a post',
    },
];

const WRITE_ENDPOINTS: Endpoint[] = [
    { method: 'POST', path: '/posts', does: 'Create a draft' },
    { method: 'PATCH', path: '/posts/{id}', does: 'Edit a draft' },
    {
        method: 'POST',
        path: '/posts/{id}/schedule',
        does: 'Schedule for a time, or unschedule',
    },
    {
        method: 'POST',
        path: '/posts/{id}/queue',
        does: 'Take the next open queue slot',
    },
    { method: 'POST', path: '/posts/{id}/publish', does: 'Publish now' },
    {
        method: 'POST',
        path: '/posts/{id}/targets/{targetId}/retry',
        does: 'Retry one failed account',
    },
    { method: 'DELETE', path: '/posts/{id}', does: 'Delete a post' },
    { method: 'POST', path: '/media', does: 'Upload media' },
    { method: 'DELETE', path: '/media/{mediaId}', does: 'Remove media' },
    {
        method: 'POST',
        path: '/posts/{id}/shares',
        does: 'Create a review link',
    },
    {
        method: 'DELETE',
        path: '/posts/{id}/shares/{shareId}',
        does: 'Revoke a review link',
    },
    { method: 'POST', path: '/account-sets', does: 'Create an account set' },
    {
        method: 'PATCH',
        path: '/account-sets/{set}',
        does: 'Rename or change an account set',
    },
    {
        method: 'DELETE',
        path: '/account-sets/{set}',
        does: 'Delete an account set',
    },
];

const METHOD_TONE: Record<Endpoint['method'], string> = {
    GET: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    POST: 'bg-primary/15 text-primary-ink',
    PATCH: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    DELETE: 'bg-destructive/10 text-destructive',
};

function EndpointTable({
    title,
    note,
    endpoints,
}: {
    title: string;
    note: string;
    endpoints: Endpoint[];
}) {
    return (
        <Panel className="reveal overflow-hidden">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border/70 px-5 py-4">
                <h3 className="font-semibold text-foreground">{title}</h3>
                <p className="text-xs text-muted-foreground">{note}</p>
            </div>
            <ul className="divide-y divide-border/60">
                {endpoints.map((endpoint) => (
                    <li
                        key={endpoint.method + endpoint.path}
                        className="grid gap-1.5 px-5 py-3 sm:grid-cols-[4.5rem_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:gap-4"
                    >
                        <span
                            className={cn(
                                'w-fit rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold',
                                METHOD_TONE[endpoint.method],
                            )}
                        >
                            {endpoint.method}
                        </span>
                        <code className="font-mono text-[13px] break-all text-foreground">
                            {endpoint.path}
                        </code>
                        <span className="text-sm text-muted-foreground">
                            {endpoint.does}
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function Surface({
    icon: Icon,
    eyebrow,
    title,
    url,
    children,
}: {
    icon: typeof Code;
    eyebrow: string;
    title: string;
    url: string;
    children: ReactNode;
}) {
    return (
        <Panel className="reveal flex min-w-0 flex-col p-7 sm:p-8">
            <span className="flex size-12 items-center justify-center rounded-2xl border border-primary/25 bg-primary/10 text-primary-ink">
                <Icon className="size-6" />
            </span>
            <p className="mt-6 font-mono text-xs text-muted-foreground">
                {eyebrow}
            </p>
            <h2 className="mt-1 font-[family-name:var(--font-display)] text-3xl font-medium tracking-tight text-foreground">
                {title}
            </h2>
            <code className="mt-4 block w-fit max-w-full rounded-xl border border-border/70 bg-background/80 px-3 py-2 font-mono text-[13px] break-all text-foreground">
                {url}
            </code>
            <div className="mt-6">{children}</div>
        </Panel>
    );
}

/**
 * Whether a tool refuses to act until called with `confirm=true`. Read from
 * the tool's own description so the badge cannot disagree with the server.
 */
function needsConfirmation(tool: McpTool): boolean {
    return tool.description.includes('confirm=true');
}

export default function Developers({
    appName,
    company,
    registrationsEnabled,
    apiBaseUrl,
    mcpUrl,
    mcpTools,
}: Props) {
    const serverName = appName.toLowerCase().replace(/[^a-z0-9]+/g, '-');

    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title="Developers" />

            <PageHero
                eyebrow="Developers"
                title={
                    <>
                        Automate it, or let your <Accent>assistant</Accent> do
                        it
                    </>
                }
                description={`Everything you can do in the ${appName} calendar, you can do from a script or an AI assistant. Both run as you, inside one workspace, with the same permissions and the same checks.`}
            />

            <Section className="pt-0 sm:pt-0">
                <div className="grid gap-5 lg:grid-cols-2">
                    <Surface
                        icon={Code}
                        eyebrow="REST · JSON"
                        title="The API"
                        url={apiBaseUrl}
                    >
                        <CheckList
                            items={[
                                'Create keys under workspace settings. Each key belongs to one workspace.',
                                'Read-only keys, or read-and-write keys for anything that changes data.',
                                'Optional expiry. The full key is shown once and stored only as a hash.',
                                'Send it as a Bearer token. Rate limited to 60 requests a minute per key.',
                            ]}
                        />
                    </Surface>
                    <Surface
                        icon={Bot}
                        eyebrow="Model Context Protocol"
                        title="The MCP server"
                        url={mcpUrl}
                    >
                        <CheckList
                            items={[
                                'Sign in through an OAuth consent screen. No key to paste into a chat.',
                                'Bound to one workspace when you connect. Reconnect to switch.',
                                'Publishing, retrying and deleting refuse to run until a person confirms.',
                                `${mcpTools.length} tools covering posts, scheduling, media, account sets and review links.`,
                            ]}
                        />
                    </Surface>
                </div>
            </Section>

            <Section id="quickstart" className="border-t border-border/60">
                <SectionHeading
                    eyebrow="Quickstart"
                    title={
                        <>
                            From zero to <Accent>scheduled</Accent> in three
                            calls
                        </>
                    }
                    description="Create a draft for every connected account, then drop it into the next open slot of your posting schedule."
                />
                <div className="mt-12 grid gap-5 lg:grid-cols-2">
                    <div className="grid gap-5">
                        <CodeBlock
                            label="1 · See your accounts"
                            code={`curl ${apiBaseUrl}/connected-accounts \\\n  -H "Authorization: Bearer $API_KEY" \\\n  -H "Accept: application/json"`}
                        />
                        <CodeBlock
                            label="2 · Create a draft"
                            code={`curl -X POST ${apiBaseUrl}/posts \\\n  -H "Authorization: Bearer $API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d '{\n    "base_text": "Shipped: scheduled threads.",\n    "destination": { "kind": "all" }\n  }'`}
                        />
                        <CodeBlock
                            label="3 · Queue it"
                            code={`curl -X POST ${apiBaseUrl}/posts/$POST_ID/queue \\\n  -H "Authorization: Bearer $API_KEY"`}
                        />
                    </div>
                    <div className="grid content-start gap-5">
                        <CodeBlock
                            label="Connect Claude Code"
                            code={`claude mcp add --transport http ${serverName} \\\n  ${mcpUrl}`}
                        />
                        <Panel className="reveal p-6">
                            <h3 className="flex items-center gap-2 font-semibold text-foreground">
                                <Terminal className="size-4 text-primary-ink" />
                                Then just ask
                            </h3>
                            <ul className="mt-4 grid gap-2.5 text-sm">
                                {[
                                    'Draft a LinkedIn post about Tuesday’s launch and queue it.',
                                    'What is scheduled for next week?',
                                    'Make a review link for the launch post that expires Friday.',
                                ].map((prompt) => (
                                    <li
                                        key={prompt}
                                        className="rounded-2xl rounded-bl-md border border-border/70 bg-background/70 px-4 py-2.5 text-foreground"
                                    >
                                        {prompt}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-4 text-xs leading-relaxed text-muted-foreground">
                                The assistant drafts, schedules and queues on
                                its own. To publish immediately it has to come
                                back and ask you first.
                            </p>
                        </Panel>
                    </div>
                </div>
            </Section>

            <Section id="reference" className="border-t border-border/60">
                <SectionHeading
                    eyebrow="Reference"
                    title={
                        <>
                            Every <Accent>endpoint</Accent>
                        </>
                    }
                    description={
                        <>
                            All paths are relative to{' '}
                            <code className="font-mono text-[0.9em] break-all text-foreground">
                                {apiBaseUrl}
                            </code>
                            . Responses are JSON; validation errors come back as
                            422 with a message per field.
                        </>
                    }
                />
                <div className="mt-12 grid gap-5 lg:grid-cols-2 lg:items-start">
                    <EndpointTable
                        title="Read"
                        note="Any key"
                        endpoints={READ_ENDPOINTS}
                    />
                    <EndpointTable
                        title="Write"
                        note="Needs a read-and-write key"
                        endpoints={WRITE_ENDPOINTS}
                    />
                </div>
            </Section>

            <Section id="mcp-tools" className="border-t border-border/60">
                <SectionHeading
                    eyebrow="MCP tools"
                    title={
                        <>
                            What your assistant <Accent>can</Accent> do
                        </>
                    }
                    description="Listed straight from the server, so this is always the current set. Tools marked “Asks first” do nothing until called again with explicit confirmation."
                />
                <ul className="mt-12 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {mcpTools.map((tool) => (
                        <li key={tool.name}>
                            <Panel className="reveal h-full p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <code className="font-mono text-[13px] font-semibold break-all text-foreground">
                                        {tool.name}
                                    </code>
                                    {needsConfirmation(tool) && (
                                        <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                                            <ShieldCheck className="size-3" />
                                            Asks first
                                        </span>
                                    )}
                                </div>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {tool.description}
                                </p>
                            </Panel>
                        </li>
                    ))}
                </ul>
                <div className="mt-10 flex flex-wrap gap-x-8 gap-y-3">
                    <ArrowLink href={howItWorks()}>
                        What happens after publish
                    </ArrowLink>
                    <ArrowLink href={security()}>
                        How keys are protected
                    </ArrowLink>
                </div>
            </Section>

            <CtaBand
                registrationsEnabled={registrationsEnabled}
                title={
                    <>
                        Get a key and <Accent>start</Accent> building
                    </>
                }
                description="Create an account, connect a network, and generate an API key from your workspace settings."
            />
        </PublicShell>
    );
}

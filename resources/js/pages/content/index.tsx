import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import {
    BrandForm,
    Feedback,
    Field,
    IdeaForm,
    selectStyle,
    TemplateForm,
} from '@/components/content/content-forms';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useContentMutation } from '@/hooks/content/use-content-mutation';
import { cn } from '@/lib/utils';
import { prompt as promptRoute } from '@/routes/content';
import { index as brandIndex } from '@/routes/content/brand';
import { convert, index as ideasIndex } from '@/routes/content/ideas';
import { index as templatesIndex } from '@/routes/content/templates';
import type {
    BrandProfile,
    ContentAsset,
    ContentIdea,
    ContentPage,
    ContentProject,
    ContentTemplate,
} from '@/types/content';

type Props = {
    tab: 'brand' | 'templates' | 'ideas';
    workspaceId: string;
    workspaceName: string;
    canManageBrand: boolean;
    brand: BrandProfile;
    logos: ContentAsset[];
    projects: ContentProject[];
    templateOptions: { id: string; name: string }[];
    templates: ContentPage<ContentTemplate>;
    ideas: ContentPage<ContentIdea>;
    filters: { q: string; status: string; archived: boolean };
};

function Pagination({ page }: { page: ContentPage<unknown> }) {
    return (
        <nav
            aria-label="Content pagination"
            className="flex items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <span>
                {page.total} items · Page {page.current_page} of{' '}
                {page.last_page}
            </span>
            <div className="flex gap-2">
                {page.prev_page_url && (
                    <Link
                        className={buttonVariants({ variant: 'outline' })}
                        href={page.prev_page_url}
                    >
                        Previous
                    </Link>
                )}
                {page.next_page_url && (
                    <Link
                        className={buttonVariants({ variant: 'outline' })}
                        href={page.next_page_url}
                    >
                        Next
                    </Link>
                )}
            </div>
        </nav>
    );
}

function PromptBuilder({
    templates,
    workspaceId,
}: {
    templates: Props['templateOptions'];
    workspaceId: string;
}) {
    const [brief, setBrief] = useState('');
    const [templateId, setTemplateId] = useState('');
    const [prompt, setPrompt] = useState('');
    const [copyStatus, setCopyStatus] = useState('');
    const mutation = useContentMutation();
    return (
        <section className="grid gap-4 rounded-2xl border bg-card p-5">
            <div>
                <h2 className="font-semibold">Branded prompt builder</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Combine your saved brand, a reusable brief and today’s
                    direction. Review or edit the result before using it in the
                    creator.
                </p>
            </div>
            <form
                className="grid gap-4"
                onSubmit={async (event) => {
                    event.preventDefault();
                    const result = await mutation.run<{ prompt: string }>(
                        promptRoute.url(),
                        'POST',
                        {
                            brief,
                            template_id: templateId || null,
                            expected_workspace_id: workspaceId,
                        },
                    );
                    if (result) {
                        setPrompt(result.prompt);
                        setCopyStatus('');
                    }
                }}
            >
                <Field label="Creative direction">
                    <Textarea
                        required
                        rows={4}
                        maxLength={10000}
                        value={brief}
                        onChange={(event) => setBrief(event.target.value)}
                        placeholder="Create an Instagram carousel introducing our new collection…"
                    />
                </Field>
                <Field label="Reusable brief">
                    <select
                        className={selectStyle}
                        value={templateId}
                        onChange={(event) => setTemplateId(event.target.value)}
                    >
                        <option value="">Use brand only</option>
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Button
                    type="submit"
                    disabled={mutation.busy || !brief.trim()}
                    className="justify-self-start"
                >
                    {mutation.busy ? 'Preparing…' : 'Build editable prompt'}
                </Button>
                <Feedback error={mutation.error} />
            </form>
            {prompt && (
                <>
                    <Field label="Prepared prompt · editable">
                        <Textarea
                            rows={12}
                            maxLength={10000}
                            value={prompt}
                            onChange={(event) => {
                                setPrompt(event.target.value);
                                setCopyStatus('');
                            }}
                        />
                    </Field>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            variant="outline"
                            onClick={async () => {
                                try {
                                    await navigator.clipboard.writeText(prompt);
                                    setCopyStatus('Prompt copied');
                                } catch {
                                    setCopyStatus(
                                        'Select and copy the prompt above',
                                    );
                                }
                            }}
                        >
                            Copy prompt
                        </Button>
                        <span
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {copyStatus}
                        </span>
                    </div>
                </>
            )}
            <p className="text-xs text-muted-foreground">
                Preparing this prompt makes no AI provider call. Any paid
                generation requires its own quote and confirmation in the
                creator.
            </p>
        </section>
    );
}

function IdeaCard({
    idea,
    templates,
    workspaceId,
}: {
    workspaceId: string;
    idea: ContentIdea;
    templates: Props['templateOptions'];
}) {
    const mutation = useContentMutation();
    return (
        <article className="grid content-start gap-4 rounded-2xl border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h2 className="font-semibold">{idea.title}</h2>
                <Badge variant="secondary">{idea.status}</Badge>
            </div>
            <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                {idea.category && <span>{idea.category}</span>}
                {idea.due_on && <span>Plan for {idea.due_on}</span>}
                {idea.tags.map((tag) => (
                    <Badge key={tag} variant="outline">
                        {tag}
                    </Badge>
                ))}
            </div>
            {idea.brief && (
                <p className="line-clamp-4 text-sm whitespace-pre-wrap text-muted-foreground">
                    {idea.brief}
                </p>
            )}
            {idea.caption && (
                <p className="line-clamp-4 text-sm whitespace-pre-wrap">
                    {idea.caption}
                </p>
            )}
            {idea.post_url ? (
                <Link
                    className={cn(
                        buttonVariants({ variant: 'outline' }),
                        'justify-self-start',
                    )}
                    href={idea.post_url}
                >
                    Open draft
                </Link>
            ) : (
                <>
                    <details className="rounded-xl border p-3">
                        <summary className="cursor-pointer text-sm font-medium">
                            Edit idea
                        </summary>
                        <div className="mt-4">
                            <IdeaForm
                                key={idea.revision}
                                idea={idea}
                                templates={templates}
                                workspaceId={workspaceId}
                            />
                        </div>
                    </details>
                    {idea.status !== 'archived' && (
                        <Button
                            disabled={mutation.busy}
                            className="justify-self-start"
                            onClick={async () => {
                                const result = await mutation.run<{
                                    post_url: string;
                                }>(convert.url(idea.id), 'POST', {
                                    expected_revision: idea.revision,
                                });
                                if (result) router.visit(result.post_url);
                            }}
                        >
                            {mutation.busy
                                ? 'Creating…'
                                : 'Create reviewable draft'}
                        </Button>
                    )}
                </>
            )}
            {idea.creator_project_id && (
                <p className="text-xs text-muted-foreground">
                    A separate design copy is saved in the creator project
                    library. Open it from the draft’s creator, then export it to
                    attach the design.
                </p>
            )}
            <Feedback error={mutation.error} />
        </article>
    );
}

function ContentWorkspace(props: Props) {
    const {
        tab,
        brand,
        canManageBrand,
        logos,
        projects,
        templateOptions,
        templates,
        ideas,
        filters,
    } = props;
    const [query, setQuery] = useState(filters.q);
    const tabs = [
        { id: 'brand', label: 'Brand kit', href: brandIndex() },
        { id: 'templates', label: 'Templates', href: templatesIndex() },
        { id: 'ideas', label: 'Ideas', href: ideasIndex() },
    ];
    const title = tabs.find((item) => item.id === tab)?.label ?? 'Brand kit';
    const filter = (values: Record<string, string | number>) =>
        router.get(
            tab === 'ideas' ? ideasIndex.url() : templatesIndex.url(),
            {
                q: query,
                status: filters.status,
                archived: filters.archived ? 1 : 0,
                ...values,
            },
            { preserveState: true, replace: true },
        );
    return (
        <main className="mx-auto grid w-full max-w-7xl gap-6 p-4 md:p-8">
            <Head title={title} />
            <header className="grid gap-2">
                <p className="text-sm font-medium text-primary">
                    {props.workspaceName}
                </p>
                <h1 className="text-3xl font-semibold tracking-tight">
                    Make every post feel like your brand
                </h1>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    Capture ideas, keep creative direction consistent and turn
                    reusable content into drafts ready for your team’s review.
                </p>
            </header>
            <nav
                aria-label="Content workspace"
                className="flex flex-wrap gap-2"
            >
                {tabs.map((item) => (
                    <Link
                        key={item.id}
                        href={item.href}
                        aria-current={tab === item.id ? 'page' : undefined}
                        className={buttonVariants({
                            variant: tab === item.id ? 'default' : 'outline',
                        })}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
            {tab === 'brand' && (
                <>
                    <BrandForm
                        key={brand.revision}
                        brand={brand}
                        logos={logos}
                        canManage={canManageBrand}
                        workspaceId={props.workspaceId}
                    />
                    <PromptBuilder
                        templates={templateOptions}
                        workspaceId={props.workspaceId}
                    />
                </>
            )}
            {tab !== 'brand' && (
                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        filter({});
                    }}
                >
                    <Input
                        aria-label={`Search ${tab}`}
                        className="max-w-sm"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={
                            tab === 'ideas'
                                ? 'Search ideas, categories and briefs'
                                : 'Search templates'
                        }
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                    {tab === 'ideas' ? (
                        <select
                            aria-label="Idea status"
                            className={cn(selectStyle, 'w-auto')}
                            value={filters.status}
                            onChange={(event) =>
                                filter({ status: event.target.value })
                            }
                        >
                            {[
                                'all',
                                'inbox',
                                'planned',
                                'drafted',
                                'archived',
                            ].map((status) => (
                                <option key={status} value={status}>
                                    {status === 'all' ? 'All statuses' : status}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={filters.archived}
                                onChange={(event) =>
                                    filter({
                                        archived: event.target.checked ? 1 : 0,
                                    })
                                }
                            />
                            Include archived
                        </label>
                    )}
                </form>
            )}
            {tab === 'templates' && (
                <>
                    <details className="rounded-2xl border bg-card p-5">
                        <summary className="cursor-pointer font-semibold">
                            Create a reusable template
                        </summary>
                        <div className="mt-5 max-w-2xl">
                            <TemplateForm
                                key={templates.total}
                                projects={projects}
                                workspaceId={props.workspaceId}
                            />
                        </div>
                    </details>
                    {templates.data.length === 0 && (
                        <p className="rounded-2xl border border-dashed p-8 text-center text-muted-foreground">
                            No templates here yet. Save a brief, caption and
                            optional design snapshot to reuse across campaigns.
                        </p>
                    )}
                    <div className="grid gap-5 lg:grid-cols-2">
                        {templates.data.map((template) => (
                            <article
                                key={template.id}
                                className="grid content-start gap-4 rounded-2xl border bg-card p-5"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <h2 className="font-semibold">
                                        {template.name}
                                    </h2>
                                    <Badge variant="secondary">
                                        {template.archived_at
                                            ? 'Archived'
                                            : template.has_design
                                              ? 'Design + brief'
                                              : 'Creative brief'}
                                    </Badge>
                                </div>
                                {template.description && (
                                    <p className="text-sm text-muted-foreground">
                                        {template.description}
                                    </p>
                                )}
                                <p className="line-clamp-4 text-sm whitespace-pre-wrap">
                                    {template.brief}
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {template.hashtags.map((tag) => (
                                        <Badge key={tag} variant="outline">
                                            {tag}
                                        </Badge>
                                    ))}
                                </div>
                                <details className="rounded-xl border p-3">
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Edit template · revision{' '}
                                        {template.revision}
                                    </summary>
                                    <div className="mt-4">
                                        <TemplateForm
                                            key={template.revision}
                                            template={template}
                                            projects={projects}
                                            workspaceId={props.workspaceId}
                                        />
                                    </div>
                                </details>
                                <p className="text-xs text-muted-foreground">
                                    Choose this template when creating an idea,
                                    or apply its saved design in the creator.
                                </p>
                            </article>
                        ))}
                    </div>
                    <Pagination page={templates} />
                </>
            )}
            {tab === 'ideas' && (
                <>
                    <details className="rounded-2xl border bg-card p-5">
                        <summary className="cursor-pointer font-semibold">
                            Capture a new idea
                        </summary>
                        <div className="mt-5 max-w-2xl">
                            <IdeaForm
                                key={ideas.total}
                                templates={templateOptions}
                                workspaceId={props.workspaceId}
                            />
                        </div>
                    </details>
                    <p className="text-sm text-muted-foreground">
                        Converting an idea creates an unscheduled draft with no
                        selected destinations. Internal briefs stay private;
                        finish and submit the draft for review before
                        publishing.
                    </p>
                    {ideas.data.length === 0 && (
                        <p className="rounded-2xl border border-dashed p-8 text-center text-muted-foreground">
                            Your next campaign starts here. Capture an idea or
                            try a different filter.
                        </p>
                    )}
                    <div className="grid gap-5 lg:grid-cols-2">
                        {ideas.data.map((idea) => (
                            <IdeaCard
                                key={`${idea.id}:${idea.revision}`}
                                idea={idea}
                                templates={templateOptions}
                                workspaceId={props.workspaceId}
                            />
                        ))}
                    </div>
                    <Pagination page={ideas} />
                </>
            )}
        </main>
    );
}
export default function ContentPageView(props: Props) {
    return <ContentWorkspace key={props.workspaceId} {...props} />;
}

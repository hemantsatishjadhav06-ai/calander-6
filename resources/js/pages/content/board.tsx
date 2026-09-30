import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { move } from '@/actions/App/Http/Controllers/Content/ContentIdeaBoardController';
import {
    Feedback,
    Field,
    IdeaForm,
    selectStyle,
} from '@/components/content/content-forms';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useContentMutation } from '@/hooks/content/use-content-mutation';
import { convert, index } from '@/routes/content/ideas';
import type { ContentIdea } from '@/types/content';

type Props = {
    workspaceId: string;
    canManage: boolean;
    ideas: ContentIdea[];
    templateOptions: { id: string; name: string }[];
};
const lanes: { status: ContentIdea['status']; label: string }[] = [
    { status: 'inbox', label: 'Inbox' },
    { status: 'planned', label: 'Planned' },
    { status: 'drafted', label: 'Drafted' },
    { status: 'archived', label: 'Archived' },
];

function IdeaBoard(props: Props) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('');
    const mutation = useContentMutation();
    const [dragged, setDragged] = useState<string | null>(null);
    const visible = props.ideas.filter(
        (idea) =>
            (!category ||
                (idea.category || '__uncategorized__') === category) &&
            `${idea.title} ${idea.brief ?? ''} ${idea.tags.join(' ')}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const categories = [
        ...new Set(
            props.ideas
                .map((idea) => idea.category)
                .filter((value): value is string => Boolean(value)),
        ),
    ].sort();
    const moveIdea = async (
        idea: ContentIdea,
        status: ContentIdea['status'],
        beforeId: string | null = null,
    ) => {
        if (beforeId === idea.id || mutation.busy) return;
        const result = await mutation.run(move.url(idea.id), 'POST', {
            expected_workspace_id: props.workspaceId,
            expected_revision: idea.revision,
            status,
            before_id: beforeId,
        });
        if (result) router.reload();
    };
    const drop = (
        status: ContentIdea['status'],
        beforeId: string | null = null,
    ) => {
        const idea = props.ideas.find((item) => item.id === dragged);
        setDragged(null);
        if (idea) void moveIdea(idea, status, beforeId);
    };
    return (
        <main className="grid w-full gap-6 p-4 md:p-8">
            <Head title="Idea board" />
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-semibold">Idea board</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Drag to arrange ideas, or use the move controls. Drafted
                        cards keep their draft link when archived.
                    </p>
                </div>
                <Link
                    href={index()}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    List view
                </Link>
            </header>
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Search ideas">
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Title, brief or tag"
                    />
                </Field>
                <Field label="Campaign / category">
                    <select
                        className={selectStyle}
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                    >
                        <option value="">All categories</option>
                        <option value="__uncategorized__">Uncategorized</option>
                        {categories.map((value) => (
                            <option key={value}>{value}</option>
                        ))}
                    </select>
                </Field>
            </div>
            {props.canManage && (
                <details className="rounded-2xl border bg-card p-4">
                    <summary className="cursor-pointer font-semibold">
                        Capture a new idea
                    </summary>
                    <div className="mt-4 max-w-2xl">
                        <IdeaForm
                            key={props.ideas.length}
                            workspaceId={props.workspaceId}
                            templates={props.templateOptions}
                        />
                    </div>
                </details>
            )}
            <Feedback error={mutation.error} />
            <div className="grid items-start gap-4 lg:grid-cols-4">
                {lanes.map((lane) => {
                    const cards = visible.filter(
                        (idea) => idea.status === lane.status,
                    );
                    return (
                        <section
                            key={lane.status}
                            aria-label={`${lane.label} ideas`}
                            className="grid min-h-40 content-start gap-3 rounded-2xl bg-muted/50 p-3"
                            onDragOver={(event) => {
                                if (props.canManage && !mutation.busy)
                                    event.preventDefault();
                            }}
                            onDrop={(event) => {
                                event.preventDefault();
                                if (props.canManage) drop(lane.status);
                            }}
                        >
                            <h2 className="flex items-center justify-between p-1 font-semibold">
                                {lane.label}
                                <Badge variant="secondary">
                                    {cards.length}
                                </Badge>
                            </h2>
                            {cards.length === 0 && (
                                <p className="p-3 text-sm text-muted-foreground">
                                    No matching ideas
                                </p>
                            )}
                            {cards.map((idea, position) => (
                                <article
                                    key={`${idea.id}:${idea.revision}`}
                                    draggable={
                                        props.canManage && !mutation.busy
                                    }
                                    onDragStart={(event) => {
                                        setDragged(idea.id);
                                        event.dataTransfer.effectAllowed =
                                            'move';
                                        event.dataTransfer.setData(
                                            'text/plain',
                                            idea.id,
                                        );
                                    }}
                                    onDragEnd={() => setDragged(null)}
                                    onDragOver={(event) => {
                                        if (props.canManage && !mutation.busy)
                                            event.preventDefault();
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        if (props.canManage)
                                            drop(lane.status, idea.id);
                                    }}
                                    className="grid gap-3 rounded-xl border bg-card p-4"
                                >
                                    <h3 className="font-medium">
                                        {idea.title}
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        {idea.category || 'Uncategorized'}
                                        {idea.due_on ? ` · ${idea.due_on}` : ''}
                                    </p>
                                    {idea.brief && (
                                        <p className="line-clamp-3 text-sm whitespace-pre-wrap">
                                            {idea.brief}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-1">
                                        {idea.tags.map((tag) => (
                                            <Badge key={tag} variant="outline">
                                                {tag}
                                            </Badge>
                                        ))}
                                    </div>
                                    {idea.post_url && (
                                        <Link
                                            className={buttonVariants({
                                                variant: 'outline',
                                                size: 'sm',
                                            })}
                                            href={idea.post_url}
                                        >
                                            Open draft
                                        </Link>
                                    )}
                                    {props.canManage && (
                                        <>
                                            <Field label={`Move ${idea.title}`}>
                                                <select
                                                    className={selectStyle}
                                                    value={idea.status}
                                                    disabled={mutation.busy}
                                                    onChange={(event) => {
                                                        void moveIdea(
                                                            idea,
                                                            event.target
                                                                .value as ContentIdea['status'],
                                                        );
                                                    }}
                                                >
                                                    {lanes
                                                        .filter((item) =>
                                                            idea.draft_post_id
                                                                ? [
                                                                      'drafted',
                                                                      'archived',
                                                                  ].includes(
                                                                      item.status,
                                                                  )
                                                                : item.status !==
                                                                  'drafted',
                                                        )
                                                        .map((item) => (
                                                            <option
                                                                key={
                                                                    item.status
                                                                }
                                                                value={
                                                                    item.status
                                                                }
                                                            >
                                                                {item.label}
                                                            </option>
                                                        ))}
                                                </select>
                                            </Field>
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    aria-label={`Move ${idea.title} up`}
                                                    disabled={
                                                        mutation.busy ||
                                                        position === 0
                                                    }
                                                    onClick={() => {
                                                        void moveIdea(
                                                            idea,
                                                            lane.status,
                                                            cards[position - 1]
                                                                .id,
                                                        );
                                                    }}
                                                >
                                                    ↑ Up
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    aria-label={`Move ${idea.title} down`}
                                                    disabled={
                                                        mutation.busy ||
                                                        position ===
                                                            cards.length - 1
                                                    }
                                                    onClick={() => {
                                                        void moveIdea(
                                                            idea,
                                                            lane.status,
                                                            cards[position + 2]
                                                                ?.id ?? null,
                                                        );
                                                    }}
                                                >
                                                    ↓ Down
                                                </Button>
                                            </div>
                                            {!idea.draft_post_id && (
                                                <details>
                                                    <summary className="cursor-pointer text-sm font-medium">
                                                        Edit idea
                                                    </summary>
                                                    <div className="mt-3">
                                                        <IdeaForm
                                                            idea={idea}
                                                            workspaceId={
                                                                props.workspaceId
                                                            }
                                                            templates={
                                                                props.templateOptions
                                                            }
                                                        />
                                                    </div>
                                                </details>
                                            )}
                                            {!idea.draft_post_id &&
                                                idea.status !== 'archived' && (
                                                    <Button
                                                        size="sm"
                                                        disabled={mutation.busy}
                                                        onClick={async () => {
                                                            const result =
                                                                await mutation.run<{
                                                                    post_url: string;
                                                                }>(
                                                                    convert.url(
                                                                        idea.id,
                                                                    ),
                                                                    'POST',
                                                                    {
                                                                        expected_revision:
                                                                            idea.revision,
                                                                    },
                                                                );
                                                            if (result)
                                                                router.visit(
                                                                    result.post_url,
                                                                );
                                                        }}
                                                    >
                                                        Create reviewable draft
                                                    </Button>
                                                )}
                                        </>
                                    )}
                                </article>
                            ))}
                        </section>
                    );
                })}
            </div>
        </main>
    );
}
export default function BoardPage(props: Props) {
    return <IdeaBoard key={props.workspaceId} {...props} />;
}

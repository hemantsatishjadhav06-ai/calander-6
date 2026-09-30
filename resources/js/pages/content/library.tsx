import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    index,
    restore,
    update,
    uploadVersion,
    versions,
} from '@/actions/App/Http/Controllers/Content/ContentAssetLibraryController';
import {
    Feedback,
    Field,
    selectStyle,
} from '@/components/content/content-forms';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useContentMutation } from '@/hooks/content/use-content-mutation';
import { creatorRequest } from '@/lib/creator-api';
import { index as brandIndex } from '@/routes/content/brand';
import { store } from '@/routes/creator/assets';
import type { ContentPage, LibraryAsset } from '@/types/content';

type Props = {
    workspaceId: string;
    canManage: boolean;
    assets: ContentPage<LibraryAsset>;
    filters: {
        q: string;
        folder: string;
        tag: string;
        starred: boolean;
        archived: boolean;
    };
    folders: string[];
    tags: string[];
};

export function AssetCard({
    asset,
    workspaceId,
    canManage,
}: {
    asset: LibraryAsset;
    workspaceId: string;
    canManage: boolean;
}) {
    const [form, setForm] = useState({
        name: asset.name,
        folder: asset.folder ?? '',
        tags: asset.tags.join(', '),
        starred: asset.starred,
        archived: Boolean(asset.archived_at),
    });
    const [history, setHistory] = useState<LibraryAsset[] | null>(null);
    const [historyError, setHistoryError] = useState('');
    const [loading, setLoading] = useState(false);
    const historyAbort = useRef<AbortController | null>(null);
    const mutation = useContentMutation();
    useEffect(() => () => historyAbort.current?.abort(), []);
    const save = async (values = form) => {
        const result = await mutation.run(update.url(asset.id), 'PUT', {
            ...values,
            folder: values.folder.trim() || null,
            tags: [
                ...new Set(
                    values.tags
                        .split(',')
                        .map((tag) => tag.trim())
                        .filter(Boolean),
                ),
            ],
            expected_workspace_id: workspaceId,
            expected_revision: asset.revision,
        });
        if (result) {
            toast.success('Asset details saved');
            router.reload();
        }
    };
    return (
        <article className="grid content-start gap-3 rounded-2xl border bg-card p-4">
            <img
                src={asset.content_url}
                alt={asset.name}
                className="h-48 w-full rounded-xl bg-muted object-contain"
            />
            <div className="flex items-start justify-between gap-2">
                <h2 className="font-semibold">{asset.name}</h2>
                <Badge variant="secondary">v{asset.version}</Badge>
            </div>
            <p className="text-xs text-muted-foreground">
                {asset.width} × {asset.height} · {asset.kind}
                {asset.folder ? ` · ${asset.folder}` : ''}
            </p>
            <div className="flex flex-wrap gap-1">
                {asset.tags.map((tag) => (
                    <Badge key={tag} variant="outline">
                        {tag}
                    </Badge>
                ))}
            </div>
            {canManage && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        aria-pressed={asset.starred}
                        disabled={mutation.busy}
                        onClick={() => {
                            void save({ ...form, starred: !asset.starred });
                        }}
                    >
                        {asset.starred ? '★ Starred' : '☆ Star'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={mutation.busy}
                        onClick={() => {
                            void save({
                                ...form,
                                archived: !asset.archived_at,
                            });
                        }}
                    >
                        {asset.archived_at ? 'Return to library' : 'Archive'}
                    </Button>
                </div>
            )}
            {canManage && (
                <details className="rounded-xl border p-3">
                    <summary className="cursor-pointer text-sm font-medium">
                        Edit organization
                    </summary>
                    <form
                        className="mt-4 grid gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <fieldset
                            disabled={mutation.busy}
                            className="grid gap-3"
                        >
                            <Field label="Asset name">
                                <Input
                                    required
                                    maxLength={200}
                                    value={form.name}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            name: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Folder">
                                <Input
                                    maxLength={100}
                                    placeholder="Campaigns / Fall"
                                    value={form.folder}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            folder: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Tags · comma-separated">
                                <Input
                                    value={form.tags}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            tags: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Button type="submit">Save organization</Button>
                        </fieldset>
                    </form>
                </details>
            )}
            <Button
                variant="outline"
                size="sm"
                disabled={loading}
                onClick={async () => {
                    if (history) {
                        setHistory(null);
                        return;
                    }
                    historyAbort.current?.abort();
                    const controller = new AbortController();
                    historyAbort.current = controller;
                    setLoading(true);
                    setHistoryError('');
                    try {
                        const result = await creatorRequest<{
                            assets: LibraryAsset[];
                        }>(versions.url(asset.id), {
                            signal: controller.signal,
                        });
                        if (!controller.signal.aborted)
                            setHistory(result.assets);
                    } catch (error) {
                        if (!controller.signal.aborted)
                            setHistoryError(
                                error instanceof Error
                                    ? error.message
                                    : 'Version history could not be loaded',
                            );
                    } finally {
                        if (!controller.signal.aborted) setLoading(false);
                    }
                }}
            >
                {loading
                    ? 'Loading history…'
                    : history
                      ? 'Close version history'
                      : 'Version history'}
            </Button>
            {history && (
                <div className="grid gap-3 rounded-xl border p-3">
                    <p className="text-xs text-muted-foreground">
                        Restoring creates a new immutable reference. Existing
                        designs and approved posts keep their selected versions.
                        Choose the new asset explicitly in the creator to use
                        it.
                    </p>
                    {history.map((version) => (
                        <div
                            key={version.id}
                            className="flex items-center justify-between gap-2"
                        >
                            <a
                                href={version.content_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-sm underline"
                            >
                                Version {version.version}
                                {version.archived_at ? ' · archived' : ''}
                            </a>
                            {canManage && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={mutation.busy}
                                    onClick={async () => {
                                        const result = await mutation.run(
                                            restore.url(version.id),
                                            'POST',
                                            {
                                                expected_workspace_id:
                                                    workspaceId,
                                                expected_revision:
                                                    version.revision,
                                            },
                                        );
                                        if (result) {
                                            toast.success(
                                                `Version ${version.version} restored as a new asset reference`,
                                            );
                                            setHistory(null);
                                            router.reload();
                                        }
                                    }}
                                >
                                    Restore v{version.version}
                                </Button>
                            )}
                        </div>
                    ))}
                    {canManage && (
                        <Field label="Upload a new version">
                            <Input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                disabled={mutation.busy}
                                onChange={async (event) => {
                                    const file = event.target.files?.[0];
                                    event.target.value = '';
                                    if (!file) return;
                                    const data = new FormData();
                                    data.set('file', file);
                                    data.set(
                                        'expected_workspace_id',
                                        workspaceId,
                                    );
                                    data.set(
                                        'expected_revision',
                                        String(asset.revision),
                                    );
                                    const result = await mutation.run(
                                        uploadVersion.url(asset.id),
                                        'POST',
                                        data,
                                    );
                                    if (result) {
                                        toast.success(
                                            'New immutable version uploaded',
                                        );
                                        setHistory(null);
                                        router.reload();
                                    }
                                }}
                            />
                        </Field>
                    )}
                </div>
            )}
            <Feedback error={mutation.error || historyError} />
        </article>
    );
}

function AssetLibrary(props: Props) {
    const [query, setQuery] = useState(props.filters.q);
    const [kind, setKind] = useState('image');
    const upload = useContentMutation();
    const filter = (values: Record<string, string | number>) =>
        router.get(
            index.url(),
            {
                ...props.filters,
                starred: Number(props.filters.starred),
                archived: Number(props.filters.archived),
                q: query,
                ...values,
            },
            { preserveState: true, replace: true },
        );
    return (
        <main className="mx-auto grid w-full max-w-7xl gap-6 p-4 md:p-8">
            <Head title="Asset library" />
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-semibold">Asset library</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Private workspace images and logos, organized without
                        changing saved designs.
                    </p>
                </div>
                <Link
                    href={brandIndex()}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Brand, templates & ideas
                </Link>
            </header>
            <form
                className="grid gap-3 rounded-2xl border bg-card p-4 md:grid-cols-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    filter({});
                }}
            >
                <Field label="Search assets">
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search names"
                    />
                </Field>
                <Field label="Folder filter">
                    <select
                        className={selectStyle}
                        value={props.filters.folder}
                        onChange={(event) =>
                            filter({ folder: event.target.value })
                        }
                    >
                        <option value="">All folders</option>
                        {props.folders.map((folder) => (
                            <option key={folder}>{folder}</option>
                        ))}
                    </select>
                </Field>
                <Field label="Tag filter">
                    <select
                        className={selectStyle}
                        value={props.filters.tag}
                        onChange={(event) =>
                            filter({ tag: event.target.value })
                        }
                    >
                        <option value="">All tags</option>
                        {props.tags.map((tag) => (
                            <option key={tag}>{tag}</option>
                        ))}
                    </select>
                </Field>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={props.filters.starred}
                        onChange={(event) =>
                            filter({ starred: Number(event.target.checked) })
                        }
                    />
                    Starred only
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={props.filters.archived}
                        onChange={(event) =>
                            filter({ archived: Number(event.target.checked) })
                        }
                    />
                    Archived assets
                </label>
                <Button type="submit">Search</Button>
            </form>
            {props.canManage && (
                <section className="grid gap-3 rounded-2xl border bg-card p-4 md:grid-cols-2">
                    <Field label="Upload as">
                        <select
                            className={selectStyle}
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                        >
                            <option value="image">Image</option>
                            <option value="logo">Brand logo</option>
                        </select>
                    </Field>
                    <Field
                        label={upload.busy ? 'Uploading…' : 'Upload new asset'}
                    >
                        <Input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            disabled={upload.busy}
                            onChange={async (event) => {
                                const file = event.target.files?.[0];
                                event.target.value = '';
                                if (!file) return;
                                const data = new FormData();
                                data.set('file', file);
                                data.set('name', file.name);
                                data.set('kind', kind);
                                data.set(
                                    'expected_workspace_id',
                                    props.workspaceId,
                                );
                                const result = await upload.run(
                                    store.url(),
                                    'POST',
                                    data,
                                );
                                if (result) {
                                    toast.success('Asset uploaded');
                                    router.reload();
                                }
                            }}
                        />
                    </Field>
                    <Feedback error={upload.error} />
                </section>
            )}
            {props.assets.data.length === 0 && (
                <p className="rounded-2xl border border-dashed p-8 text-center text-muted-foreground">
                    No assets match these filters.
                </p>
            )}
            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                {props.assets.data.map((asset) => (
                    <AssetCard
                        key={`${asset.id}:${asset.revision}`}
                        asset={asset}
                        workspaceId={props.workspaceId}
                        canManage={props.canManage}
                    />
                ))}
            </div>
            <nav
                aria-label="Library pagination"
                className="flex items-center justify-between"
            >
                <p className="text-sm text-muted-foreground">
                    {props.assets.total} assets · Page{' '}
                    {props.assets.current_page} of {props.assets.last_page}
                </p>
                <div className="flex gap-2">
                    {props.assets.prev_page_url && (
                        <Link
                            className={buttonVariants({ variant: 'outline' })}
                            href={props.assets.prev_page_url}
                        >
                            Previous
                        </Link>
                    )}
                    {props.assets.next_page_url && (
                        <Link
                            className={buttonVariants({ variant: 'outline' })}
                            href={props.assets.next_page_url}
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </main>
    );
}
export default function LibraryPage(props: Props) {
    return <AssetLibrary key={props.workspaceId} {...props} />;
}

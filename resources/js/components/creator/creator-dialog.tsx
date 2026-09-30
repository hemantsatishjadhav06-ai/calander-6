import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import ContentWorkspaceController from '@/actions/App/Http/Controllers/Content/ContentWorkspaceController';
import CreatorAssetController from '@/actions/App/Http/Controllers/Creator/CreatorAssetController';
import CreatorExportController from '@/actions/App/Http/Controllers/Creator/CreatorExportController';
import CreatorProjectController from '@/actions/App/Http/Controllers/Creator/CreatorProjectController';
import { useConfirm } from '@/components/common/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { ArrowDown, Loader2, X } from '@/components/ui/icons';
import { useCreatorImages } from '@/hooks/creator/use-creator-images';
import {
    addSlide,
    assertDocument,
    commitHistory,
    createDocument,
    createHistory,
    createLayer,
    createSlide,
    importLayerDescriptors,
    redoHistory,
    renderSlide,
    serializeDocument,
    undoHistory,
} from '@/lib/creator';
import type { CreatorDocument, CreatorHistory } from '@/lib/creator';
import { CreatorApiError, creatorRequest } from '@/lib/creator-api';
import {
    creatorZip,
    downloadCreatorBlob,
    encodeCreatorCanvas,
} from '@/lib/creator-export';
import type { CreatorExportFormat } from '@/lib/creator-export';
import type { PostView } from '@/types/compose';
import type {
    CreatorAssetView,
    CreatorPostContext,
    CreatorProjectView,
    CreatorProjectSummary,
} from '@/types/creator';

import { CreatorField, creatorInputClass } from './creator-controls';
import { CreatorEditor } from './creator-editor';
import { CreatorGenerationPanel } from './creator-generation-panel';
import type { CreatorGenerationOutput } from './creator-generation-panel';

type ContentContext = {
    workspace_id: string;
    brand: {
        palette: string[];
        logo_asset_id: string | null;
        default_hashtags: string[];
        first_comment: string;
        first_comment_enabled: boolean;
        logo?: CreatorAssetView | null;
    };
    templates: {
        id: string;
        name: string;
        revision: number;
        has_design: boolean;
    }[];
};
type LocalRecovery = {
    document: CreatorDocument;
    name: string;
    project_id: string | null;
    revision: number | null;
};

type Props = {
    postId: string;
    targetSegmentRef: string;
    initialProjectId?: string;
    onClose: () => void;
    onAttached: (post: PostView) => void;
};

export function CreatorDialog({
    postId,
    targetSegmentRef,
    initialProjectId,
    onClose,
    onAttached,
}: Props) {
    const { workspaces } = usePage().props;
    const confirm = useConfirm();
    const [initial] = useState(() => createDocument());
    const [history, setHistory] = useState<CreatorHistory>(() =>
        createHistory(initial),
    );
    const [editorIdentity, setEditorIdentity] = useState(0);
    const [project, setProject] = useState<CreatorProjectView | null>(null);
    const [name, setName] = useState('Untitled design');
    const [projects, setProjects] = useState<CreatorProjectSummary[]>([]);
    const [assets, setAssets] = useState<CreatorAssetView[]>([]);
    const [assetCursor, setAssetCursor] = useState<string | null>(null);
    const [assetQuery, setAssetQuery] = useState('');
    const [projectCursor, setProjectCursor] = useState<string | null>(null);
    const [context, setContext] = useState<CreatorPostContext | null>(null);
    const [content, setContent] = useState<ContentContext | null>(null);
    const [selectedAssetId, setSelectedAssetId] = useState<string | null>(null);
    const [activeSlideId, setActiveSlideId] = useState(initial.slides[0].id);
    const [format, setFormat] = useState<CreatorExportFormat>('png');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [assetUploads, setAssetUploads] = useState(0);
    const assetUploadCount = useRef(0);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [templateName, setTemplateName] = useState('');
    const mounted = useRef(true);
    const snapshot = useRef({ history, project, name });
    snapshot.current = { history, project, name };
    const savingPromise = useRef<Promise<CreatorProjectView> | null>(null);
    const exportRequest = useRef<{ form: FormData; signature: string } | null>(
        null,
    );
    const actionLock = useRef(false);
    const loadAsset = useCreatorImages(assets);
    const scene = history.present;
    const dirty =
        serializeDocument(scene) !==
            serializeDocument(project?.document ?? initial) ||
        name !== (project?.name ?? 'Untitled design');
    const recoveryKey = `creator:${workspaces.current?.id ?? 'none'}:${postId}`;

    const mergeAssets = useCallback((incoming: CreatorAssetView[]) => {
        setAssets((previous) => [
            ...new Map(
                [...previous, ...incoming].map((asset) => [asset.id, asset]),
            ).values(),
        ]);
    }, []);

    function adopt(
        saved: CreatorProjectView | null,
        document: CreatorDocument = saved?.document ?? createDocument(),
        nextName = saved?.name ?? 'Untitled design',
    ) {
        const canonical = assertDocument(document);
        setProject(saved);
        setName(nextName);
        setHistory(createHistory(canonical));
        setEditorIdentity((value) => value + 1);
        setActiveSlideId(canonical.slides[0].id);
        setSelectedAssetId(null);
        exportRequest.current = null;
        snapshot.current = {
            history: createHistory(canonical),
            project: saved,
            name: nextName,
        };
    }

    useEffect(() => {
        mounted.current = true;
        const controller = new AbortController();
        async function load() {
            try {
                const [projectList, assetList, postContext, contentContext] =
                    await Promise.all([
                        creatorRequest<{
                            projects: CreatorProjectSummary[];
                            workspace_id: string;
                            next_cursor?: string | null;
                        }>(CreatorProjectController.index().url, {
                            signal: controller.signal,
                        }),
                        creatorRequest<{
                            assets: CreatorAssetView[];
                            workspace_id: string;
                            next_cursor?: string | null;
                        }>(CreatorAssetController.index().url, {
                            signal: controller.signal,
                        }),
                        creatorRequest<CreatorPostContext>(
                            CreatorExportController.context(postId).url,
                            { signal: controller.signal },
                        ),
                        creatorRequest<ContentContext>(
                            ContentWorkspaceController.context().url,
                            { signal: controller.signal },
                        ),
                    ]);
                if (controller.signal.aborted) return;
                if (
                    [
                        projectList.workspace_id,
                        assetList.workspace_id,
                        postContext.workspace_id,
                        contentContext.workspace_id,
                    ].some((id) => id !== workspaces.current?.id)
                )
                    throw new Error(
                        'Your active company changed in another tab. Close Creator and reload before editing.',
                    );
                setProjects(projectList.projects);
                setProjectCursor(projectList.next_cursor ?? null);
                setAssetCursor(assetList.next_cursor ?? null);
                setAssets(
                    contentContext.brand.logo
                        ? [
                              ...assetList.assets.filter(
                                  (asset) =>
                                      asset.id !==
                                      contentContext.brand.logo!.id,
                              ),
                              contentContext.brand.logo,
                          ]
                        : assetList.assets,
                );
                setContext(postContext);
                setContent(contentContext);
                const linkedId =
                    initialProjectId ??
                    postContext.post.media.find(
                        (media) => media.creator_export?.project_id,
                    )?.creator_export?.project_id;
                let saved: CreatorProjectView | null = null;
                if (linkedId) {
                    const linked = await creatorRequest<{
                        project: CreatorProjectView;
                        assets: CreatorAssetView[];
                    }>(CreatorProjectController.show(linkedId).url, {
                        signal: controller.signal,
                    });
                    if (controller.signal.aborted) return;
                    saved = linked.project;
                    mergeAssets(linked.assets);
                    setProjects((previous) => [
                        linked.project,
                        ...previous.filter(
                            (item) => item.id !== linked.project.id,
                        ),
                    ]);
                }
                let recovered: LocalRecovery | null = null;
                try {
                    const value = sessionStorage.getItem(recoveryKey);
                    if (value) recovered = JSON.parse(value) as LocalRecovery;
                } catch {
                    /* Storage can be disabled by the browser. */
                }
                if (recovered) {
                    const canonical = assertDocument(recovered.document);
                    let matching: CreatorProjectView | null =
                        saved?.id === recovered.project_id ? saved : null;
                    if (!matching && recovered.project_id) {
                        const recoveredProject = await creatorRequest<{
                            project: CreatorProjectView;
                            assets: CreatorAssetView[];
                        }>(
                            CreatorProjectController.show(recovered.project_id)
                                .url,
                            { signal: controller.signal },
                        );
                        if (controller.signal.aborted) return;
                        matching = recoveredProject.project;
                        mergeAssets(recoveredProject.assets);
                        setProjects((previous) => [
                            recoveredProject.project,
                            ...previous.filter(
                                (item) =>
                                    item.id !== recoveredProject.project.id,
                            ),
                        ]);
                    }
                    saved =
                        matching && matching.revision === recovered.revision
                            ? matching
                            : null;
                    if (!controller.signal.aborted) {
                        adopt(saved, canonical, recovered.name);
                        toast.info(
                            saved || !recovered.project_id
                                ? 'Your unsaved design was recovered.'
                                : 'Recovered as a separate design because the saved project changed.',
                        );
                    }
                } else if (saved) {
                    adopt(saved);
                }
            } catch (reason) {
                if (!controller.signal.aborted)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Could not load Creator.',
                    );
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }
        void load();
        return () => {
            mounted.current = false;
            controller.abort();
        };
    }, [
        postId,
        recoveryKey,
        mergeAssets,
        initialProjectId,
        workspaces.current?.id,
    ]);

    useEffect(() => {
        if (loading) return;
        try {
            if (dirty)
                sessionStorage.setItem(
                    recoveryKey,
                    JSON.stringify({
                        document: scene,
                        name,
                        project_id: project?.id ?? null,
                        revision: project?.revision ?? null,
                    }),
                );
            else sessionStorage.removeItem(recoveryKey);
        } catch {
            /* The editor remains usable without browser recovery storage. */
        }
    }, [scene, name, project, dirty, loading, recoveryKey]);

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    async function close() {
        if (busy || saving) return;
        if (
            dirty &&
            !(await confirm({
                title: 'Close with unsaved design changes?',
                description:
                    'Your local recovery copy will stay in this browser. Save the project to make it available to your company.',
                actionLabel: 'Close',
                cancelLabel: 'Keep editing',
            }))
        )
            return;
        try {
            const latest = await creatorRequest<CreatorPostContext>(
                CreatorExportController.context(postId).url,
            );
            if (mounted.current) onAttached(latest.post);
        } catch {
            /* Closing stays available when the network is interrupted. */
        }
        if (mounted.current) onClose();
    }

    async function saveProject(): Promise<CreatorProjectView> {
        if (savingPromise.current) return savingPromise.current;
        if (assetUploadCount.current > 0)
            throw new Error(
                'Wait for the current image upload to finish before saving or exporting.',
            );
        const current = snapshot.current;
        if (!current.name.trim())
            throw new Error('Give this design a name before saving.');
        if (
            current.project &&
            current.name === current.project.name &&
            serializeDocument(current.history.present) ===
                serializeDocument(current.project.document)
        )
            return current.project;
        setSaving(true);
        const task = (async () => {
            const result = await creatorRequest<{
                project: CreatorProjectView;
            }>(
                current.project
                    ? CreatorProjectController.update(current.project.id).url
                    : CreatorProjectController.store().url,
                {
                    method: current.project ? 'PUT' : 'POST',
                    body: {
                        expected_workspace_id: workspaces.current?.id,
                        name: current.name.trim(),
                        document: assertDocument(current.history.present),
                        ...(current.project
                            ? { expected_revision: current.project.revision }
                            : {}),
                    },
                },
            );
            snapshot.current.project = result.project;
            if (mounted.current) {
                setProject(result.project);
                setProjects((previous) => [
                    result.project,
                    ...previous.filter((item) => item.id !== result.project.id),
                ]);
            }
            return result.project;
        })();
        savingPromise.current = task;
        try {
            return await task;
        } finally {
            savingPromise.current = null;
            if (mounted.current) setSaving(false);
        }
    }

    async function run(label: string, work: () => Promise<void>) {
        if (actionLock.current) return;
        actionLock.current = true;
        setBusy(label);
        setError(null);
        try {
            await work();
        } catch (reason) {
            if (mounted.current)
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'Creator could not complete this action.',
                );
        } finally {
            actionLock.current = false;
            if (mounted.current) setBusy(null);
        }
    }

    async function selectProject(id: string) {
        if (
            dirty &&
            !(await confirm({
                title: 'Switch designs?',
                description:
                    'Save your current design first if you want to keep these changes.',
                actionLabel: 'Discard and switch',
                cancelLabel: 'Keep editing',
            }))
        )
            return;
        void run('Opening design…', async () => {
            if (!id) {
                adopt(null);
                return;
            }
            const result = await creatorRequest<{
                project: CreatorProjectView;
                assets: CreatorAssetView[];
            }>(CreatorProjectController.show(id).url);
            if (mounted.current) {
                mergeAssets(result.assets);
                adopt(result.project);
            }
        });
    }

    async function upload(
        file: File,
        kind: 'image' | 'logo',
    ): Promise<CreatorAssetView> {
        assetUploadCount.current += 1;
        setAssetUploads(assetUploadCount.current);
        try {
            const form = new FormData();
            form.append('file', file);
            form.append('name', file.name.slice(0, 200));
            form.append('kind', kind);
            form.append('expected_workspace_id', workspaces.current?.id ?? '');
            const result = await creatorRequest<{ asset: CreatorAssetView }>(
                CreatorAssetController.store().url,
                { method: 'POST', body: form },
            );
            if (mounted.current) mergeAssets([result.asset]);
            return result.asset;
        } finally {
            assetUploadCount.current -= 1;
            if (mounted.current) setAssetUploads(assetUploadCount.current);
        }
    }

    async function browseAssets(search: string, more = false) {
        await run('Loading company assets…', async () => {
            const result = await creatorRequest<{
                assets: CreatorAssetView[];
                workspace_id: string;
                next_cursor: string | null;
            }>(
                CreatorAssetController.index({
                    query: {
                        search,
                        ...(more && assetQuery === search && assetCursor
                            ? { cursor: assetCursor }
                            : {}),
                    },
                }).url,
            );
            if (result.workspace_id !== workspaces.current?.id)
                throw new Error(
                    'Your active company changed. Reload before continuing.',
                );
            if (mounted.current) {
                mergeAssets(result.assets);
                setAssetCursor(result.next_cursor);
                setAssetQuery(search);
            }
        });
    }

    async function captureCanvas(): Promise<CreatorAssetView> {
        const current = snapshot.current.history.present;
        const selectedSlide =
            current.slides.find((slide) => slide.id === activeSlideId) ??
            current.slides[0];
        const rendered = await renderSlide(current, selectedSlide.id, {
            assets: loadAsset,
        });
        const blob = await encodeCreatorCanvas(rendered, 'png');
        return upload(
            new File([blob], 'creator-canvas.png', { type: blob.type }),
            'image',
        );
    }

    function applyOutputs(
        outputs: CreatorGenerationOutput[],
        operation: string,
    ) {
        if (
            actionLock.current ||
            savingPromise.current ||
            assetUploadCount.current > 0
        )
            throw new Error(
                'Wait for the current save or export to finish before adding generated images.',
            );
        mergeAssets(outputs.map((output) => output.asset));
        let next = snapshot.current.history.present;
        if (operation === 'layerize') {
            const base =
                outputs.find((output) => output.z_index === 0) ?? outputs[0];
            if (!base) throw new Error('No layers were returned.');
            const scale = Math.min(
                next.canvas.width / base.asset.width,
                next.canvas.height / base.asset.height,
            );
            const frame = {
                width: Math.max(1, Math.round(base.asset.width * scale)),
                height: Math.max(1, Math.round(base.asset.height * scale)),
            };
            const layers = importLayerDescriptors(
                outputs.map((output, index) => ({
                    assetId: output.asset.id,
                    name: output.name ?? `Layer ${index + 1}`,
                    zIndex: output.z_index ?? index,
                    bounds: output.bounding_box?.normalized
                        ? { normalized: output.bounding_box.normalized }
                        : output.bounding_box?.absolute
                          ? {
                                normalized: output.bounding_box.absolute.map(
                                    (value, coordinate) =>
                                        (value /
                                            (coordinate % 2 === 0
                                                ? base.asset.width
                                                : base.asset.height)) *
                                        1000,
                                ) as [number, number, number, number],
                            }
                          : undefined,
                    sourceLayout:
                        output.asset.width === base.asset.width &&
                        output.asset.height === base.asset.height
                            ? 'full-canvas'
                            : 'cropped',
                })),
                frame,
            ).map((layer) => ({
                ...layer,
                x: layer.x + (next.canvas.width - frame.width) / 2,
                y: layer.y + (next.canvas.height - frame.height) / 2,
            }));
            const slide = { ...createSlide('Separated layers'), layers };
            next =
                next.slides.length === 1 && next.slides[0].layers.length === 0
                    ? { ...next, slides: [slide] }
                    : addSlide(next, slide);
            setActiveSlideId(slide.id);
        } else {
            for (const [index, output] of outputs.entries()) {
                const slide = {
                    ...createSlide(`Generated ${index + 1}`),
                    ...(operation === 'remove_background'
                        ? { background_color: '#00000000' }
                        : {}),
                    layers: [
                        createLayer('image', {
                            name: output.name ?? output.asset.name,
                            asset_id: output.asset.id,
                            width: next.canvas.width,
                            height: next.canvas.height,
                            fit: 'contain',
                        }),
                    ],
                };
                next =
                    next.slides.length === 1 &&
                    next.slides[0].layers.length === 0
                        ? { ...next, slides: [slide] }
                        : addSlide(next, slide);
            }
        }
        const canonical = assertDocument(next);
        setHistory((previous) => commitHistory(previous, canonical));
    }

    async function renderedFiles(document: CreatorDocument) {
        const files = [];
        for (const [index, slide] of document.slides.entries()) {
            const canvas = await renderSlide(document, slide.id, {
                assets: loadAsset,
            });
            const blob = await encodeCreatorCanvas(canvas, format);
            canvas.width = 0;
            canvas.height = 0;
            const filename = `slide-${String(index + 1).padStart(2, '0')}.${format === 'jpeg' ? 'jpg' : format}`;
            files.push({ slideId: slide.id, blob, name: filename });
        }
        return files;
    }

    async function attach() {
        const saved = await saveProject();
        const signature = `${saved.id}:${saved.revision}:${format}`;
        if (
            !exportRequest.current ||
            exportRequest.current.signature !== signature
        ) {
            const files = await renderedFiles(saved.document);
            const latest = await creatorRequest<CreatorPostContext>(
                CreatorExportController.context(postId).url,
            );
            const replaced = latest.post.media
                .filter(
                    (media) => media.creator_export?.project_id === saved.id,
                )
                .map((media) => media.id);
            const segmentMediaIds = latest.post.placements?.length
                ? latest.post.placements
                      .filter(
                          (placement) =>
                              placement.segment_ref === targetSegmentRef,
                      )
                      .map((placement) => placement.media_id)
                : targetSegmentRef === '__head__'
                  ? latest.post.media.map((media) => media.id)
                  : [];
            if (
                latest.post.media.some(
                    (media) =>
                        segmentMediaIds.includes(media.id) &&
                        !replaced.includes(media.id) &&
                        media.kind === 'video',
                )
            )
                throw new Error(
                    'This post section already contains a video. Remove it before adding Creator images.',
                );
            const form = new FormData();
            form.append('project_id', saved.id);
            form.append('project_revision', String(saved.revision));
            form.append('expected_post_revision', latest.revision);
            form.append('idempotency_key', crypto.randomUUID());
            form.append('target_segment_ref', targetSegmentRef);
            replaced.forEach((id, index) =>
                form.append(`replace_media_ids[${index}]`, id),
            );
            files.forEach((file, index) => {
                form.append(`slides[${index}][slide_id]`, file.slideId);
                form.append(
                    `slides[${index}][file]`,
                    new File([file.blob], file.name, { type: file.blob.type }),
                );
                form.append(
                    `slides[${index}][alt_text]`,
                    saved.document.slides[index].name,
                );
            });
            exportRequest.current = { form, signature };
        }
        try {
            const result = await creatorRequest<{ post: PostView }>(
                CreatorExportController.store(postId).url,
                { method: 'POST', body: exportRequest.current.form },
            );
            exportRequest.current = null;
            if (mounted.current) {
                onAttached(result.post);
                toast.success(
                    `${saved.document.slides.length} slide${saved.document.slides.length === 1 ? '' : 's'} attached to the draft.`,
                );
                onClose();
            }
        } catch (reason) {
            if (
                reason instanceof CreatorApiError &&
                reason.status >= 400 &&
                reason.status < 500
            )
                exportRequest.current = null;
            throw reason;
        }
    }

    const library = (
        <div className="flex flex-col gap-3 p-3">
            <h3 className="font-semibold">Company templates</h3>
            <p className="text-xs text-muted-foreground">
                Save editable designs as independent snapshots. Starting from a
                template never edits its source.
            </p>
            <input
                aria-label="Template name"
                placeholder="Name this template…"
                className={creatorInputClass}
                value={templateName}
                onChange={(event) => setTemplateName(event.currentTarget.value)}
            />
            <Button
                variant="outline"
                disabled={Boolean(busy) || saving || !templateName.trim()}
                onClick={() => {
                    void run('Saving template…', async () => {
                        const saved = await saveProject();
                        await creatorRequest(
                            ContentWorkspaceController.storeTemplate().url,
                            {
                                method: 'POST',
                                body: {
                                    name: templateName.trim(),
                                    brief: '',
                                    caption: context?.post.base_text ?? '',
                                    hashtags:
                                        content?.brand.default_hashtags ?? [],
                                    first_comment: content?.brand
                                        .first_comment_enabled
                                        ? content.brand.first_comment
                                        : '',
                                    source_project_id: saved.id,
                                    source_project_revision: saved.revision,
                                    expected_workspace_id:
                                        workspaces.current?.id,
                                },
                            },
                        );
                        const refreshed = await creatorRequest<ContentContext>(
                            ContentWorkspaceController.context().url,
                        );
                        if (mounted.current) {
                            setContent(refreshed);
                            setTemplateName('');
                            toast.success('Template saved for this company.');
                        }
                    });
                }}
            >
                Save current design as template
            </Button>
            {content?.templates
                .filter((template) => template.has_design)
                .map((template) => (
                    <Button
                        key={template.id}
                        variant="secondary"
                        className="h-auto justify-start py-3 text-left whitespace-normal"
                        disabled={Boolean(busy) || saving}
                        onClick={() => {
                            void (async () => {
                                if (
                                    dirty &&
                                    !(await confirm({
                                        title: 'Start from this template?',
                                        description:
                                            'Save the current design first to keep your unsaved changes.',
                                        actionLabel: 'Start new design',
                                    }))
                                )
                                    return;
                                await run('Opening template…', async () => {
                                    const created = await creatorRequest<{
                                        project: CreatorProjectView;
                                    }>(
                                        ContentWorkspaceController.instantiateTemplate(
                                            template.id,
                                        ).url,
                                        {
                                            method: 'POST',
                                            body: {
                                                expected_revision:
                                                    template.revision,
                                            },
                                        },
                                    );
                                    const result = await creatorRequest<{
                                        project: CreatorProjectView;
                                        assets: CreatorAssetView[];
                                    }>(
                                        CreatorProjectController.show(
                                            created.project.id,
                                        ).url,
                                    );
                                    if (mounted.current) {
                                        mergeAssets(result.assets);
                                        adopt(result.project);
                                        setProjects((previous) => [
                                            result.project,
                                            ...previous,
                                        ]);
                                    }
                                });
                            })();
                        }}
                    >
                        {template.name}
                    </Button>
                ))}
        </div>
    );

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) void close();
            }}
        >
            <DialogContent
                showCloseButton={false}
                className="flex h-[96dvh] max-h-[1100px] w-[98vw] max-w-none flex-col gap-0 overflow-hidden rounded-2xl p-0 sm:max-w-[1500px]"
            >
                <div className="flex shrink-0 flex-wrap items-center gap-3 px-4 py-3">
                    <div className="mr-auto">
                        <DialogTitle>Creator</DialogTitle>
                        <DialogDescription className="mt-1 text-xs">
                            {workspaces.current?.name ?? 'Company'} · Editable
                            designs for your social posts
                        </DialogDescription>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={
                            history.past.length === 0 || Boolean(busy) || saving
                        }
                        onClick={() => setHistory(undoHistory)}
                    >
                        Undo
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={
                            history.future.length === 0 ||
                            Boolean(busy) ||
                            saving
                        }
                        onClick={() => setHistory(redoHistory)}
                    >
                        Redo
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Close Creator"
                        disabled={Boolean(busy) || saving}
                        onClick={() => {
                            void close();
                        }}
                    >
                        <X />
                    </Button>
                </div>
                <div className="flex shrink-0 flex-wrap items-end gap-3 border-t border-border px-4 py-3">
                    <CreatorField
                        label="Design name"
                        className="min-w-40 flex-1"
                    >
                        <input
                            className={creatorInputClass}
                            value={name}
                            maxLength={200}
                            disabled={loading || Boolean(busy) || saving}
                            onChange={(event) =>
                                setName(event.currentTarget.value)
                            }
                        />
                    </CreatorField>
                    <CreatorField label="Open saved design" className="w-52">
                        <select
                            className={creatorInputClass}
                            value={project?.id ?? ''}
                            disabled={loading || Boolean(busy) || saving}
                            onChange={(event) => {
                                void selectProject(event.currentTarget.value);
                            }}
                        >
                            <option value="">New design</option>
                            {projects.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </CreatorField>
                    {projectCursor && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={Boolean(busy) || saving}
                            onClick={() => {
                                void run('Loading saved designs…', async () => {
                                    const result = await creatorRequest<{
                                        projects: CreatorProjectSummary[];
                                        workspace_id: string;
                                        next_cursor: string | null;
                                    }>(
                                        CreatorProjectController.index({
                                            query: { cursor: projectCursor },
                                        }).url,
                                    );
                                    if (
                                        result.workspace_id !==
                                        workspaces.current?.id
                                    )
                                        throw new Error(
                                            'Your active company changed. Reload before continuing.',
                                        );
                                    if (mounted.current) {
                                        setProjects((previous) => [
                                            ...new Map(
                                                [
                                                    ...previous,
                                                    ...result.projects,
                                                ].map((item) => [
                                                    item.id,
                                                    item,
                                                ]),
                                            ).values(),
                                        ]);
                                        setProjectCursor(result.next_cursor);
                                    }
                                });
                            }}
                        >
                            More designs
                        </Button>
                    )}
                    <span
                        role="status"
                        className="pb-2 text-xs text-muted-foreground"
                    >
                        {saving
                            ? 'Saving…'
                            : dirty
                              ? 'Unsaved changes'
                              : project
                                ? `Saved · revision ${project.revision}`
                                : 'New project'}
                    </span>
                </div>
                {error && (
                    <div
                        role="alert"
                        className="shrink-0 border-y border-destructive/20 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    >
                        {error}
                    </div>
                )}
                {loading ? (
                    <div className="flex flex-1 items-center justify-center gap-2 text-sm text-muted-foreground">
                        <Loader2 className="size-5 animate-spin" />
                        Loading company designs…
                    </div>
                ) : (
                    context && (
                        <CreatorEditor
                            key={editorIdentity}
                            document={scene}
                            onChange={(next) => {
                                setHistory((previous) =>
                                    commitHistory(
                                        previous,
                                        typeof next === 'function'
                                            ? next(previous.present)
                                            : next,
                                    ),
                                );
                            }}
                            assets={assets}
                            onBrowseAssets={browseAssets}
                            hasMoreAssets={assetCursor !== null}
                            assetQuery={assetQuery}
                            loadAsset={loadAsset}
                            onUpload={upload}
                            onSelectedAssetChange={setSelectedAssetId}
                            activeSlideId={activeSlideId}
                            onActiveSlideChange={setActiveSlideId}
                            brandColors={content?.brand.palette ?? []}
                            disabled={Boolean(busy) || saving}
                            libraryPanel={library}
                            generationPanel={
                                <CreatorGenerationPanel
                                    projectId={project?.id ?? null}
                                    workspaceId={workspaces.current?.id ?? ''}
                                    disabled={
                                        Boolean(busy) ||
                                        saving ||
                                        assetUploads > 0
                                    }
                                    ensureProject={async () =>
                                        (await saveProject()).id
                                    }
                                    selectedAssetId={selectedAssetId}
                                    captureCanvas={captureCanvas}
                                    onOutputs={applyOutputs}
                                    onAssets={mergeAssets}
                                    aspectRatio={
                                        Math.abs(
                                            scene.canvas.width /
                                                scene.canvas.height -
                                                1,
                                        ) < 0.01
                                            ? '1:1'
                                            : Math.abs(
                                                    scene.canvas.width /
                                                        scene.canvas.height -
                                                        0.8,
                                                ) < 0.01
                                              ? '4:5'
                                              : scene.canvas.height >
                                                  scene.canvas.width
                                                ? '9:16'
                                                : '16:9'
                                    }
                                    preparePrompt={async (brief) =>
                                        (
                                            await creatorRequest<{
                                                prompt: string;
                                            }>(
                                                ContentWorkspaceController.prompt()
                                                    .url,
                                                {
                                                    method: 'POST',
                                                    body: {
                                                        brief,
                                                        expected_workspace_id:
                                                            workspaces.current
                                                                ?.id,
                                                    },
                                                },
                                            )
                                        ).prompt
                                    }
                                />
                            }
                        />
                    )
                )}
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-t border-border bg-background p-3">
                    <p className="mr-auto max-w-lg text-xs text-muted-foreground">
                        {busy ??
                            'Saving a changed design makes its older post exports stale. Attach the latest slides before review or publishing.'}
                    </p>
                    <select
                        aria-label="Export format"
                        title="PNG and WebP preserve transparency. JPEG uses a white background."
                        className={`${creatorInputClass} w-24`}
                        value={format}
                        disabled={Boolean(busy) || saving}
                        onChange={(event) =>
                            setFormat(
                                event.currentTarget
                                    .value as CreatorExportFormat,
                            )
                        }
                    >
                        <option value="png">PNG</option>
                        <option value="jpeg">JPEG</option>
                        <option value="webp">WebP</option>
                    </select>
                    <Button
                        variant="outline"
                        disabled={
                            loading ||
                            Boolean(busy) ||
                            saving ||
                            assetUploads > 0 ||
                            !context
                        }
                        onClick={() => {
                            void run('Saving design…', async () => {
                                await saveProject();
                                toast.success('Design saved.');
                            });
                        }}
                    >
                        Save project
                    </Button>
                    <Button
                        variant="outline"
                        disabled={
                            loading ||
                            Boolean(busy) ||
                            saving ||
                            assetUploads > 0 ||
                            !context
                        }
                        onClick={() => {
                            void run('Rendering download…', async () => {
                                const files = await renderedFiles(
                                    snapshot.current.history.present,
                                );
                                if (files.length === 1)
                                    downloadCreatorBlob(
                                        files[0].blob,
                                        files[0].name,
                                    );
                                else
                                    downloadCreatorBlob(
                                        await creatorZip(files),
                                        'creator-slides.zip',
                                    );
                            });
                        }}
                    >
                        <ArrowDown />
                        Download
                    </Button>
                    <Button
                        disabled={
                            loading ||
                            Boolean(busy) ||
                            saving ||
                            assetUploads > 0 ||
                            !context
                        }
                        onClick={() => {
                            void run('Rendering and attaching…', attach);
                        }}
                    >
                        Attach{' '}
                        {scene.slides.length > 1
                            ? `${scene.slides.length} slides`
                            : 'to draft'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

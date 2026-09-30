import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import CreatorGenerationController from '@/actions/App/Http/Controllers/Creator/CreatorGenerationController';
import { Button } from '@/components/ui/button';
import { creatorRequest } from '@/lib/creator-api';
import { creatorGenerationOptions } from '@/lib/creator-generation';
import type { CreatorAssetView } from '@/types/creator';

import { CreatorField, creatorInputClass } from './creator-controls';

export type CreatorGenerationOutput = {
    asset: CreatorAssetView;
    width: number;
    height: number;
    z_index?: number;
    bounding_box?: {
        absolute?: [number, number, number, number];
        normalized?: [number, number, number, number];
    } | null;
    name?: string;
    description?: string;
};
type Generation = {
    id: string;
    project_id: string | null;
    operation: string;
    endpoint_id: string;
    prompt: string;
    asset_id: string | null;
    status: string;
    can_confirm: boolean;
    can_cancel: boolean;
    can_retry_import?: boolean;
    total_outputs?: number | null;
    quote: {
        amount: number;
        currency: string;
        basis: string;
        estimated: boolean;
        hard_cap: boolean;
        expires_at: string;
        disclosure: string;
    } | null;
    outputs: CreatorGenerationOutput[];
    error: { code: string; message: string } | null;
    queue_position: number | null;
};
type Capabilities = {
    workspace_id: string;
    resolutions?: string[];
    configured: boolean;
    provider: 'fal';
    reason?: string;
    operations: {
        id: string;
        label: string;
        endpoint_id: string;
        requires_asset: boolean;
    }[];
};
type Props = {
    disabled?: boolean;
    workspaceId: string;
    projectId: string | null;
    ensureProject: () => Promise<string>;
    selectedAssetId: string | null;
    captureCanvas: () => Promise<CreatorAssetView>;
    onOutputs: (outputs: CreatorGenerationOutput[], operation: string) => void;
    onAssets: (assets: CreatorAssetView[]) => void;
    aspectRatio: string;
    preparePrompt?: (brief: string) => Promise<string>;
};
const ACTIVE = new Set([
    'submitting',
    'queued',
    'running',
    'importing',
    'cancel_requested',
]);
const PROMPTS: Record<string, string> = {
    enhance:
        'Enhance the photo naturally. Preserve the subject, composition and product details.',
    relight:
        'Relight with soft directional studio lighting. Preserve the subject and composition.',
    angle: 'Show the subject from a three-quarter camera angle. Preserve its identity and details.',
    text_edit: 'Replace the text with: ',
    expand: 'Extend the background naturally to the requested aspect ratio. Preserve the original subject.',
    layerize:
        'Separate the background, foreground subjects, logos and text into independently editable transparent layers.',
};

export function CreatorGenerationPanel({
    disabled = false,
    workspaceId,
    projectId,
    ensureProject,
    selectedAssetId,
    captureCanvas,
    onOutputs,
    onAssets,
    aspectRatio,
    preparePrompt,
}: Props) {
    const [capabilities, setCapabilities] = useState<Capabilities | null>(null);
    const [operation, setOperation] = useState('generate');
    const [prompt, setPrompt] = useState('');
    const [source, setSource] = useState<'selected' | 'canvas'>('selected');
    const [count, setCount] = useState(1);
    const [resolution, setResolution] = useState('1K');
    const [jobs, setJobs] = useState<Generation[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const mounted = useRef(true);
    const actionLock = useRef(false);
    const assetsCallback = useRef(onAssets);
    assetsCallback.current = onAssets;
    const imported = useRef(new Set<string>());
    const currentProject = useRef(projectId);
    const projectEpoch = useRef(0);
    const preparingProject = useRef<string | null>(null);
    if (currentProject.current !== projectId) {
        currentProject.current = projectId;
        projectEpoch.current += 1;
    }
    const currentOperation = capabilities?.operations.find(
        (item) => item.id === operation,
    );

    const acceptJob = useCallback((job: Generation) => {
        if (!mounted.current) return;
        const expected = currentProject.current ?? preparingProject.current;
        if (job.project_id !== expected) return;
        setJobs((previous) =>
            [job, ...previous.filter((item) => item.id !== job.id)].slice(
                0,
                20,
            ),
        );
        if (job.status === 'completed' && !imported.current.has(job.id)) {
            imported.current.add(job.id);
            assetsCallback.current(job.outputs.map((output) => output.asset));
        }
    }, []);

    useEffect(() => {
        mounted.current = true;
        const controller = new AbortController();
        void creatorRequest<Capabilities>(
            CreatorGenerationController.capabilities().url,
            { signal: controller.signal },
        )
            .then((result) => {
                if (controller.signal.aborted) return;
                if (result.workspace_id !== workspaceId)
                    throw new Error(
                        'Your active company changed. Close Creator and reload.',
                    );
                setCapabilities(result);
            })
            .catch((reason: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Could not load AI capabilities.',
                    );
            });
        return () => {
            mounted.current = false;
            controller.abort();
        };
    }, [workspaceId]);

    useEffect(() => {
        setJobs((previous) =>
            previous.filter((job) => job.project_id === projectId),
        );
        if (!projectId) {
            setJobs([]);
            return;
        }
        const controller = new AbortController();
        void creatorRequest<{ generations: Generation[] }>(
            CreatorGenerationController.index({
                query: { project_id: projectId },
            }).url,
            { signal: controller.signal },
        )
            .then(({ generations }) => {
                if (
                    controller.signal.aborted ||
                    currentProject.current !== projectId
                )
                    return;
                for (const job of [...generations].reverse()) acceptJob(job);
            })
            .catch((reason: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Could not restore generation history.',
                    );
            });
        return () => controller.abort();
    }, [projectId, acceptJob]);

    const activeIds = jobs
        .filter((job) => ACTIVE.has(job.status))
        .map((job) => job.id)
        .sort()
        .join(',');
    useEffect(() => {
        if (!activeIds) return;
        let stopped = false;
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        const poll = async () => {
            for (const id of activeIds.split(',')) {
                if (stopped) return;
                try {
                    const result = await creatorRequest<{
                        generation: Generation;
                    }>(CreatorGenerationController.show(id).url, {
                        signal: controller.signal,
                    });
                    if (!stopped) acceptJob(result.generation);
                } catch (reason) {
                    if (!stopped)
                        setError(
                            reason instanceof Error
                                ? reason.message
                                : 'Status check failed. Your job has not been resubmitted.',
                        );
                }
            }
            if (!stopped)
                timer = setTimeout(() => {
                    void poll();
                }, 4000);
        };
        timer = setTimeout(() => {
            void poll();
        }, 1500);
        return () => {
            stopped = true;
            clearTimeout(timer);
            controller.abort();
        };
    }, [activeIds, acceptJob]);

    async function action(work: () => Promise<void>) {
        if (actionLock.current || disabled) return;
        actionLock.current = true;
        setBusy(true);
        setError(null);
        try {
            await work();
        } catch (reason) {
            if (mounted.current)
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'The provider request could not be completed.',
                );
        } finally {
            actionLock.current = false;
            if (mounted.current) setBusy(false);
        }
    }

    function quote() {
        void action(async () => {
            const initialProject = currentProject.current;
            const initialEpoch = projectEpoch.current;
            const id = await ensureProject();
            const stillCurrent = () =>
                mounted.current &&
                ((projectEpoch.current === initialEpoch &&
                    currentProject.current === initialProject &&
                    (initialProject === null || initialProject === id)) ||
                    (initialProject === null &&
                        projectEpoch.current === initialEpoch + 1 &&
                        currentProject.current === id));
            if (!stillCurrent()) return;
            preparingProject.current = id;
            let assetId: string | undefined;
            if (currentOperation?.requires_asset) {
                if (source === 'canvas') assetId = (await captureCanvas()).id;
                else if (selectedAssetId) assetId = selectedAssetId;
                else
                    throw new Error(
                        'Select an image layer, or choose the current canvas.',
                    );
            }
            if (!stillCurrent()) return;
            const { generation } = await creatorRequest<{
                generation: Generation;
            }>(CreatorGenerationController.quote().url, {
                method: 'POST',
                body: {
                    project_id: id,
                    expected_workspace_id: workspaceId,
                    operation,
                    prompt,
                    asset_id: assetId,
                    idempotency_key: crypto.randomUUID(),
                    options: creatorGenerationOptions(operation, {
                        aspectRatio,
                        resolution,
                        count,
                    }),
                },
            });
            if (stillCurrent()) acceptJob(generation);
        });
    }

    return (
        <div className="flex flex-col gap-3 p-3">
            <div>
                <h3 className="font-semibold">Create with fal.ai</h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Generate photos, edit selected artwork, or separate it into
                    layers. Results are added only when you choose.
                </p>
            </div>
            {capabilities && !capabilities.configured && (
                <p
                    role="status"
                    className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs"
                >
                    {capabilities.reason ??
                        'fal.ai is not configured for this application. Your workspace administrator must connect the server-side provider before generation is available.'}{' '}
                    Local editing, uploads and export still work.
                </p>
            )}
            <CreatorField label="AI tool">
                <select
                    className={creatorInputClass}
                    value={operation}
                    onChange={(event) => {
                        setOperation(event.currentTarget.value);
                        setPrompt(PROMPTS[event.currentTarget.value] ?? '');
                    }}
                >
                    {(
                        capabilities?.operations ?? [
                            {
                                id: 'generate',
                                label: 'Generate image',
                                endpoint_id: '',
                                requires_asset: false,
                            },
                        ]
                    ).map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.label}
                        </option>
                    ))}
                </select>
            </CreatorField>
            {currentOperation?.requires_asset && (
                <CreatorField label="Image to send">
                    <select
                        className={creatorInputClass}
                        value={source}
                        onChange={(event) =>
                            setSource(
                                event.currentTarget.value as
                                    | 'selected'
                                    | 'canvas',
                            )
                        }
                    >
                        <option value="selected">
                            Selected image layer
                            {selectedAssetId ? '' : ' (none selected)'}
                        </option>
                        <option value="canvas">
                            Current canvas with all visible layers
                        </option>
                    </select>
                </CreatorField>
            )}
            <CreatorField label="Describe the post or edit">
                <textarea
                    className={creatorInputClass}
                    rows={5}
                    maxLength={10000}
                    placeholder="A warm editorial product photo, room for a headline at the top…"
                    value={prompt}
                    onChange={(event) => setPrompt(event.currentTarget.value)}
                />
            </CreatorField>
            {preparePrompt && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy || disabled || !prompt.trim()}
                    onClick={() => {
                        void action(async () => {
                            const prepared = await preparePrompt(prompt);
                            if (mounted.current) setPrompt(prepared);
                        });
                    }}
                >
                    Apply company brand guidance
                </Button>
            )}
            {(operation === 'generate' || operation === 'edit') && (
                <div className="grid grid-cols-2 gap-2">
                    <CreatorField label="Images">
                        <select
                            className={creatorInputClass}
                            value={count}
                            onChange={(event) =>
                                setCount(Number(event.currentTarget.value))
                            }
                        >
                            {[1, 2, 3, 4].map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </CreatorField>
                    <CreatorField label="Resolution">
                        <select
                            className={creatorInputClass}
                            value={resolution}
                            onChange={(event) =>
                                setResolution(event.currentTarget.value)
                            }
                        >
                            {(capabilities?.resolutions ?? ['1K', '2K']).map(
                                (value) => (
                                    <option key={value}>{value}</option>
                                ),
                            )}
                        </select>
                    </CreatorField>
                </div>
            )}
            <Button
                disabled={
                    busy ||
                    disabled ||
                    !capabilities?.configured ||
                    (currentOperation?.requires_asset &&
                        source === 'selected' &&
                        !selectedAssetId)
                }
                onClick={quote}
            >
                {busy ? 'Working…' : 'Get cost estimate'}
            </Button>
            <p className="text-[11px] text-muted-foreground">
                The estimate step does not send your image to fal.ai.
                Confirmation sends the prompt and chosen image and starts a
                billable request.
            </p>
            {error && (
                <p
                    role="alert"
                    className="rounded-lg bg-destructive/10 p-3 text-xs text-destructive"
                >
                    {error}
                </p>
            )}
            {jobs.length > 0 && (
                <h4 className="border-t border-border pt-3 text-xs font-semibold">
                    Generation history
                </h4>
            )}
            {jobs.map((job) => (
                <div
                    key={job.id}
                    className="flex flex-col gap-2 rounded-xl border border-border bg-muted/20 p-3"
                >
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-semibold capitalize">
                            {job.operation.replaceAll('_', ' ')}
                        </span>
                        <span
                            role="status"
                            className="text-[10px] text-muted-foreground"
                        >
                            {job.status.replaceAll('_', ' ')}
                        </span>
                    </div>
                    <p className="text-[10px] break-all text-muted-foreground">
                        {job.endpoint_id}
                    </p>
                    <p className="line-clamp-3 text-xs">{job.prompt}</p>
                    {job.can_confirm && job.quote && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-semibold">
                                Estimated{' '}
                                {new Intl.NumberFormat('en', {
                                    style: 'currency',
                                    currency: job.quote.currency,
                                    maximumFractionDigits: 4,
                                }).format(job.quote.amount)}
                            </p>
                            <p className="text-[11px] text-muted-foreground">
                                {job.quote.disclosure} This is an estimate, not
                                a hard spending cap.
                            </p>
                            <Button
                                size="sm"
                                disabled={
                                    busy ||
                                    disabled ||
                                    Date.parse(job.quote.expires_at) <=
                                        Date.now()
                                }
                                onClick={() => {
                                    void action(async () => {
                                        const result = await creatorRequest<{
                                            generation: Generation;
                                        }>(
                                            CreatorGenerationController.confirm(
                                                job.id,
                                            ).url,
                                            {
                                                method: 'POST',
                                                body: { confirm: true },
                                            },
                                        );
                                        acceptJob(result.generation);
                                    });
                                }}
                            >
                                Confirm and generate
                            </Button>
                        </div>
                    )}
                    {job.queue_position !== null && ACTIVE.has(job.status) && (
                        <p className="text-xs text-muted-foreground">
                            Queue position: {job.queue_position}
                        </p>
                    )}
                    {job.error && (
                        <p role="alert" className="text-xs text-destructive">
                            {job.error.message}
                        </p>
                    )}
                    {job.status === 'unknown' && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            Submission outcome is uncertain. Check this request
                            before generating again to avoid duplicate charges.
                        </p>
                    )}
                    {job.status === 'importing' && (
                        <p
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            Importing {job.outputs.length} of{' '}
                            {job.total_outputs ?? '…'} images into your company
                            library…
                        </p>
                    )}
                    {job.can_retry_import && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy || disabled}
                            onClick={() => {
                                void action(async () => {
                                    const result = await creatorRequest<{
                                        generation: Generation;
                                    }>(
                                        CreatorGenerationController.show(job.id)
                                            .url,
                                    );
                                    acceptJob(result.generation);
                                });
                            }}
                        >
                            Retry image import
                        </Button>
                    )}
                    {job.can_cancel && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy || disabled}
                            onClick={() => {
                                void action(async () => {
                                    const result = await creatorRequest<{
                                        generation: Generation;
                                    }>(
                                        CreatorGenerationController.cancel(
                                            job.id,
                                        ).url,
                                        {
                                            method: 'POST',
                                            body: { confirm: true },
                                        },
                                    );
                                    acceptJob(result.generation);
                                });
                            }}
                        >
                            Request cancellation
                        </Button>
                    )}
                    {job.status === 'completed' && job.outputs.length > 0 && (
                        <>
                            <div className="grid grid-cols-3 gap-1">
                                {job.outputs.map((output) => (
                                    <img
                                        key={output.asset.id}
                                        src={output.asset.content_url}
                                        alt={output.name ?? output.asset.name}
                                        className="aspect-square rounded bg-muted object-contain"
                                    />
                                ))}
                            </div>
                            <Button
                                variant="secondary"
                                size="sm"
                                disabled={busy || disabled}
                                onClick={() => {
                                    if (disabled) return;
                                    try {
                                        onOutputs(job.outputs, job.operation);
                                        toast.success(
                                            job.operation === 'layerize'
                                                ? 'Layers added to the design.'
                                                : 'Generated images added as slides.',
                                        );
                                    } catch (reason) {
                                        toast.error(
                                            reason instanceof Error
                                                ? reason.message
                                                : 'Could not add these outputs.',
                                        );
                                    }
                                }}
                            >
                                {job.operation === 'layerize'
                                    ? 'Add editable layers'
                                    : 'Add images as slides'}
                            </Button>
                        </>
                    )}
                </div>
            ))}
        </div>
    );
}

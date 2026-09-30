import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CreatorDialog } from '@/components/creator/creator-dialog';
import { addLayer, addSlide, createDocument, createLayer } from '@/lib/creator';
import type { CreatorDocument } from '@/lib/creator';
import type * as CreatorModule from '@/lib/creator';
import type * as CreatorApiModule from '@/lib/creator-api';
import { CreatorApiError } from '@/lib/creator-api';
import type { MediaView, PostView } from '@/types/compose';
import type { CreatorProjectView } from '@/types/creator';

const mocks = vi.hoisted(() => ({
    request: vi.fn(),
    confirm: vi.fn(),
    renderSlide: vi.fn(),
    encode: vi.fn(),
    zip: vi.fn(),
    download: vi.fn(),
    loadAsset: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            workspaces: {
                current: { id: 'workspace-1', name: 'Test company' },
            },
        },
    }),
}));
vi.mock('@/components/common/confirm-dialog', () => ({
    useConfirm: () => mocks.confirm,
}));
vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ children }: { children: ReactNode }) => (
        <div role="dialog">{children}</div>
    ),
    DialogContent: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DialogDescription: ({ children }: { children: ReactNode }) => (
        <p>{children}</p>
    ),
    DialogTitle: ({ children }: { children: ReactNode }) => <h2>{children}</h2>,
}));
vi.mock('@/components/creator/creator-canvas', () => ({
    CreatorCanvas: () => <div aria-label="Preview canvas" />,
}));
vi.mock('@/hooks/creator/use-creator-images', () => ({
    useCreatorImages: () => mocks.loadAsset,
}));
vi.mock('@/lib/creator-api', async (original) => ({
    ...(await original<typeof CreatorApiModule>()),
    creatorRequest: mocks.request,
}));
vi.mock('@/lib/creator', async (original) => ({
    ...(await original<typeof CreatorModule>()),
    renderSlide: mocks.renderSlide,
}));
vi.mock('@/lib/creator-export', () => ({
    encodeCreatorCanvas: mocks.encode,
    creatorZip: mocks.zip,
    downloadCreatorBlob: mocks.download,
}));
vi.mock('sonner', () => ({
    toast: { error: vi.fn(), info: vi.fn(), success: vi.fn() },
}));
vi.mock(
    '@/actions/App/Http/Controllers/Creator/CreatorProjectController',
    () => ({
        default: {
            index: () => ({ url: '/projects' }),
            store: () => ({ url: '/projects' }),
            show: (id: string) => ({ url: `/projects/${id}` }),
            update: (id: string) => ({ url: `/projects/${id}` }),
        },
    }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Creator/CreatorAssetController',
    () => ({
        default: {
            index: () => ({ url: '/assets' }),
            store: () => ({ url: '/assets' }),
        },
    }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Creator/CreatorExportController',
    () => ({
        default: {
            context: (id: string) => ({ url: `/posts/${id}/context` }),
            store: (id: string) => ({ url: `/posts/${id}/exports` }),
        },
    }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Content/ContentWorkspaceController',
    () => ({
        default: {
            context: () => ({ url: '/content/context' }),
            prompt: () => ({ url: '/content/prompt' }),
            storeTemplate: () => ({ url: '/content/templates' }),
            instantiateTemplate: (id: string) => ({
                url: `/content/templates/${id}`,
            }),
        },
    }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Creator/CreatorGenerationController',
    () => ({
        default: {
            capabilities: () => ({ url: '/generations/capabilities' }),
            index: () => ({ url: '/generations' }),
            quote: () => ({ url: '/generations/quote' }),
            show: (id: string) => ({ url: `/generations/${id}` }),
            confirm: (id: string) => ({ url: `/generations/${id}/confirm` }),
            cancel: (id: string) => ({ url: `/generations/${id}/cancel` }),
        },
    }),
);

const capabilities = {
    workspace_id: 'workspace-1',
    configured: true,
    provider: 'fal',
    resolutions: ['1K', '2K'],
    operations: [
        {
            id: 'generate',
            label: 'Generate image',
            endpoint_id: 'fal-ai/test',
            requires_asset: false,
        },
    ],
};
let post: PostView;
let projects: CreatorProjectView[];
let exportAttempts: FormData[];
let exportFailure: Error | null;
let requestOverride:
    | ((url: string, options?: { method?: string; body?: unknown }) => unknown)
    | null;

function project(
    document = createDocument(),
    id = 'project-1',
    name = 'Saved design',
): CreatorProjectView {
    return {
        id,
        name,
        revision: 1,
        document,
        updated_at: '2026-09-30T00:00:00Z',
    };
}

function media(kind: 'image' | 'video', id = 'media-1'): MediaView {
    return {
        id,
        kind,
        url: '/media/file',
        mime: kind === 'image' ? 'image/png' : 'video/mp4',
        alt_text: null,
        duration_seconds: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        edit_url: '/media/file/edit',
        source_edit_url: null,
    };
}

function context() {
    return {
        workspace_id: 'workspace-1',
        post,
        revision: 'post-revision-1',
        review_status: 'draft',
    };
}

function setup(targetSegmentRef = '__head__') {
    const onClose = vi.fn();
    const onAttached = vi.fn();
    return {
        ...render(
            <CreatorDialog
                postId="post-1"
                targetSegmentRef={targetSegmentRef}
                onClose={onClose}
                onAttached={onAttached}
            />,
        ),
        onClose,
        onAttached,
    };
}

async function ready() {
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save project' }),
        ).toBeEnabled(),
    );
}

beforeEach(() => {
    sessionStorage.clear();
    projects = [];
    exportAttempts = [];
    exportFailure = null;
    requestOverride = null;
    post = {
        id: 'post-1',
        base_text: 'Draft caption',
        segments: [],
        status: 'draft',
        published_at: null,
        updated_at: '2026-09-30T00:00:00Z',
        scheduled_at: null,
        auto_repost: false,
        destination: { kind: 'none', id: null },
        targets: [],
        media: [],
    };
    mocks.confirm.mockReset().mockResolvedValue(false);
    mocks.renderSlide
        .mockReset()
        .mockImplementation(async () => ({ width: 1080, height: 1080 }));
    mocks.encode
        .mockReset()
        .mockImplementation(
            async (_canvas: HTMLCanvasElement, format: string) =>
                new Blob(['encoded design'], { type: `image/${format}` }),
        );
    mocks.zip
        .mockReset()
        .mockResolvedValue(new Blob(['archive'], { type: 'application/zip' }));
    mocks.download.mockReset();
    mocks.request
        .mockReset()
        .mockImplementation(
            async (
                url: string,
                options?: { method?: string; body?: unknown },
            ) => {
                const overridden = requestOverride?.(url, options);
                if (overridden !== undefined) return overridden;
                if (url === '/projects' && options?.method === 'POST') {
                    const body = options.body as {
                        name: string;
                        document: CreatorDocument;
                    };
                    const saved = project(
                        body.document,
                        'new-project',
                        body.name,
                    );
                    projects.push(saved);
                    return { project: saved };
                }
                if (url === '/projects')
                    return { workspace_id: 'workspace-1', projects };
                if (url.startsWith('/projects/'))
                    return {
                        project: projects.find(
                            (item) => item.id === url.split('/').at(-1),
                        ),
                        assets: [],
                    };
                if (url === '/assets')
                    return { workspace_id: 'workspace-1', assets: [] };
                if (url === '/posts/post-1/context') return context();
                if (url === '/content/context')
                    return {
                        workspace_id: 'workspace-1',
                        brand: {
                            palette: [],
                            logo_asset_id: null,
                            default_hashtags: [],
                            first_comment: '',
                            first_comment_enabled: false,
                        },
                        templates: [],
                    };
                if (url === '/generations/capabilities') return capabilities;
                if (url === '/generations') return { generations: [] };
                if (url === '/posts/post-1/exports') {
                    exportAttempts.push(options?.body as FormData);
                    if (exportFailure) throw exportFailure;
                    return { post };
                }
                throw new Error(`Unexpected test request: ${url}`);
            },
        );
});

afterEach(() => {
    sessionStorage.clear();
});

describe('Creator dialog editing lifecycle', () => {
    it('keeps a failed save editable with local recovery and does not close', async () => {
        requestOverride = (url, options) => {
            if (url === '/projects' && options?.method === 'POST')
                return Promise.reject(new Error('Save unavailable'));
        };
        const { onClose } = setup();
        await ready();
        fireEvent.change(screen.getByLabelText('Design name'), {
            target: { value: 'Keep my unsaved title' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add text' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save project' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Save unavailable',
        );
        expect(screen.getByLabelText('Design name')).toHaveValue(
            'Keep my unsaved title',
        );
        expect(screen.getByRole('button', { name: 'Add text' })).toBeEnabled();
        expect(
            JSON.parse(
                sessionStorage.getItem('creator:workspace-1:post-1') ?? '{}',
            ),
        ).toMatchObject({
            name: 'Keep my unsaved title',
            document: { slides: [{ layers: [{ type: 'text' }] }] },
        });
        expect(onClose).not.toHaveBeenCalled();
    });

    it('honors Keep editing when closing or switching a dirty design', async () => {
        projects = [project()];
        const { onClose } = setup();
        await ready();
        fireEvent.change(screen.getByLabelText('Design name'), {
            target: { value: 'Unsaved title' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Close Creator' }));
        await waitFor(() => expect(mocks.confirm).toHaveBeenCalledTimes(1));
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Open saved design'), {
            target: { value: 'project-1' },
        });
        await waitFor(() => expect(mocks.confirm).toHaveBeenCalledTimes(2));
        expect(screen.getByLabelText('Design name')).toHaveValue(
            'Unsaved title',
        );
        expect(
            mocks.request.mock.calls.some(
                ([url]) => url === '/projects/project-1',
            ),
        ).toBe(false);
    });

    it('closes with recovery preserved after confirmation even if the refresh fails', async () => {
        mocks.confirm.mockResolvedValue(true);
        const { onClose } = setup();
        await ready();
        fireEvent.change(screen.getByLabelText('Design name'), {
            target: { value: 'Recover me' },
        });
        requestOverride = (url) => {
            if (url.endsWith('/context'))
                return Promise.reject(new Error('Offline'));
        };
        fireEvent.click(screen.getByRole('button', { name: 'Close Creator' }));
        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
        expect(sessionStorage.getItem('creator:workspace-1:post-1')).toContain(
            'Recover me',
        );
    });

    it('retains the AI tab and prompt when quote preparation saves a brand-new project', async () => {
        let finishQuote!: (value: unknown) => void;
        requestOverride = (url) => {
            if (url === '/generations/quote')
                return new Promise((resolve) => {
                    finishQuote = resolve;
                });
        };
        setup();
        await ready();
        fireEvent.click(screen.getByRole('tab', { name: 'AI tools' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        fireEvent.change(screen.getByLabelText('Describe the post or edit'), {
            target: { value: 'A thoughtful product photograph' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        );
        await waitFor(() =>
            expect(
                mocks.request.mock.calls.some(
                    ([url]) => url === '/generations/quote',
                ),
            ).toBe(true),
        );
        expect(screen.getByRole('tab', { name: 'AI tools' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByLabelText('Describe the post or edit')).toHaveValue(
            'A thoughtful product photograph',
        );
        await act(async () =>
            finishQuote({
                generation: {
                    id: 'quote-1',
                    project_id: 'new-project',
                    operation: 'generate',
                    endpoint_id: 'fal-ai/test',
                    prompt: 'A thoughtful product photograph',
                    asset_id: null,
                    status: 'quoted',
                    can_confirm: true,
                    can_cancel: false,
                    quote: {
                        amount: 0.04,
                        currency: 'USD',
                        basis: 'image',
                        estimated: true,
                        hard_cap: false,
                        expires_at: new Date(Date.now() + 60_000).toISOString(),
                        disclosure: 'Estimated provider cost.',
                    },
                    outputs: [],
                    error: null,
                    queue_position: null,
                },
            }),
        );
        expect(
            await screen.findByRole('button', { name: 'Confirm and generate' }),
        ).toBeEnabled();
    });
});

describe('Creator export integration', () => {
    it('renders every layer on every slide and downloads a single ordered JPEG archive', async () => {
        let document = createDocument();
        document = addLayer(
            document,
            document.slides[0].id,
            createLayer('text', { text: 'Headline' }),
        );
        document = addLayer(
            document,
            document.slides[0].id,
            createLayer('shape'),
        );
        document = addLayer(
            document,
            document.slides[0].id,
            createLayer('image', { asset_id: 'photo-1' }),
        );
        document = addLayer(
            document,
            document.slides[0].id,
            createLayer('drawing'),
        );
        document = addSlide(document);
        document = addLayer(
            document,
            document.slides[1].id,
            createLayer('text', { text: 'Second slide' }),
        );
        projects = [project(document)];
        setup();
        await ready();
        fireEvent.change(screen.getByLabelText('Open saved design'), {
            target: { value: 'project-1' },
        });
        await waitFor(() =>
            expect(screen.getByLabelText('Design name')).toHaveValue(
                'Saved design',
            ),
        );
        fireEvent.change(screen.getByLabelText('Export format'), {
            target: { value: 'jpeg' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Download' }));
        await waitFor(() => expect(mocks.download).toHaveBeenCalledTimes(1));
        expect(mocks.renderSlide).toHaveBeenCalledTimes(2);
        expect(mocks.renderSlide).toHaveBeenNthCalledWith(
            1,
            document,
            document.slides[0].id,
            expect.any(Object),
        );
        expect(mocks.renderSlide).toHaveBeenNthCalledWith(
            2,
            document,
            document.slides[1].id,
            expect.any(Object),
        );
        expect(mocks.encode.mock.calls.map((call) => call[1])).toEqual([
            'jpeg',
            'jpeg',
        ]);
        expect(mocks.zip).toHaveBeenCalledWith([
            expect.objectContaining({
                name: 'slide-01.jpg',
                slideId: document.slides[0].id,
            }),
            expect.objectContaining({
                name: 'slide-02.jpg',
                slideId: document.slides[1].id,
            }),
        ]);
        expect(mocks.download).toHaveBeenCalledWith(
            expect.any(Blob),
            'creator-slides.zip',
        );
    });

    it('reuses the same files and idempotency key after an uncertain server failure', async () => {
        exportFailure = new CreatorApiError('Temporary server failure', 503);
        const { onClose, onAttached } = setup();
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Add text' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Temporary server failure',
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Add text' })).toBeEnabled();
        exportFailure = null;
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        await waitFor(() => expect(onAttached).toHaveBeenCalledWith(post));
        expect(exportAttempts).toHaveLength(2);
        expect(exportAttempts[0]).toBe(exportAttempts[1]);
        expect(exportAttempts[0].get('idempotency_key')).toBeTruthy();
        expect(mocks.renderSlide).toHaveBeenCalledTimes(1);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('keeps editing after a legacy platform validation failure and refreshes revision for retry', async () => {
        exportFailure = new CreatorApiError(
            'This platform supports at most four images.',
            422,
        );
        const { onClose } = setup();
        await ready();
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'at most four images',
        );
        const first = exportAttempts[0];
        exportFailure = null;
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
        expect(exportAttempts[1]).not.toBe(first);
        expect(exportAttempts[1].get('idempotency_key')).not.toBe(
            first.get('idempotency_key'),
        );
        expect(mocks.renderSlide).toHaveBeenCalledTimes(2);
    });

    it('blocks mixed image/video attachment to the legacy head section', async () => {
        post.media = [media('video')];
        const { onClose } = setup();
        await ready();
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'already contains a video',
        );
        expect(exportAttempts).toHaveLength(0);
        expect(onClose).not.toHaveBeenCalled();
    });

    it('permits attaching to a different thread section from an existing video', async () => {
        post.media = [media('video')];
        const { onAttached } = setup('section-2');
        await ready();
        fireEvent.click(
            screen.getByRole('button', { name: 'Attach to draft' }),
        );
        await waitFor(() => expect(onAttached).toHaveBeenCalledWith(post));
        expect(exportAttempts[0].get('target_segment_ref')).toBe('section-2');
    });
});

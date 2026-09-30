import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CreatorGenerationPanel } from '@/components/creator/creator-generation-panel';
import type { CreatorGenerationOutput } from '@/components/creator/creator-generation-panel';

const mocks = vi.hoisted(() => ({
    request: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
}));
vi.mock('@/lib/creator-api', () => ({ creatorRequest: mocks.request }));
vi.mock('sonner', () => ({
    toast: { success: mocks.success, error: mocks.error },
}));
vi.mock(
    '@/actions/App/Http/Controllers/Creator/CreatorGenerationController',
    () => ({
        default: {
            capabilities: () => ({ url: '/generations/capabilities' }),
            index: ({ query }: { query: { project_id: string } }) => ({
                url: `/generations?project=${query.project_id}`,
            }),
            quote: () => ({ url: '/generations/quote' }),
            confirm: (id: string) => ({ url: `/generations/${id}/confirm` }),
            cancel: (id: string) => ({ url: `/generations/${id}/cancel` }),
            show: (id: string) => ({ url: `/generations/${id}` }),
            retryImport: (id: string) => ({
                url: `/generations/${id}/retry-import`,
            }),
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
        {
            id: 'edit',
            label: 'Edit image',
            endpoint_id: 'fal-ai/test/edit',
            requires_asset: true,
        },
    ],
};

const output: CreatorGenerationOutput = {
    asset: {
        id: 'generated-1',
        name: 'Generated product',
        kind: 'image',
        mime: 'image/png',
        width: 1024,
        height: 1024,
        content_url: '/creator/assets/generated-1/content',
    },
    width: 1024,
    height: 1024,
};

function job(overrides: Record<string, unknown> = {}) {
    return {
        id: 'job-1',
        project_id: 'project-1',
        operation: 'generate',
        endpoint_id: 'fal-ai/test',
        prompt: 'A product on a desk',
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
        ...overrides,
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
}

function setup(
    overrides: Partial<ComponentProps<typeof CreatorGenerationPanel>> = {},
) {
    const props: ComponentProps<typeof CreatorGenerationPanel> = {
        workspaceId: 'workspace-1',
        projectId: 'project-1',
        ensureProject: vi.fn(async () => 'project-1'),
        selectedAssetId: null,
        captureCanvas: vi.fn(async () => output.asset),
        onOutputs: vi.fn(),
        onAssets: vi.fn(),
        aspectRatio: '1:1',
        ...overrides,
    };
    return { ...render(<CreatorGenerationPanel {...props} />), props };
}

beforeEach(() => {
    mocks.request.mockReset().mockImplementation(async (url: string) => {
        if (url === '/generations/capabilities') return capabilities;
        if (url.startsWith('/generations?project=')) return { generations: [] };
        if (url === '/generations/quote') return { generation: job() };
        throw new Error(`Unexpected test request: ${url}`);
    });
    mocks.success.mockClear();
    mocks.error.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Creator generation panel', () => {
    it('keeps unconfigured provider state explanatory and prevents provider requests', async () => {
        mocks.request.mockResolvedValue({
            ...capabilities,
            configured: false,
            reason: 'No provider configured',
            generations: [],
        });
        setup();
        expect(
            await screen.findByText(/No provider configured/),
        ).toHaveTextContent('Local editing, uploads and export still work');
        expect(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        );
        expect(
            mocks.request.mock.calls.some(
                ([url]) => url === '/generations/quote',
            ),
        ).toBe(false);
    });

    it('fails closed when capabilities belong to a different active company', async () => {
        mocks.request.mockImplementation(async (url: string) =>
            url === '/generations/capabilities'
                ? { ...capabilities, workspace_id: 'workspace-2' }
                : { generations: [] },
        );
        setup();
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'active company changed',
        );
        expect(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        ).toBeDisabled();
    });

    it('deduplicates rapid quote clicks and requires separate explicit confirmation', async () => {
        const quote = deferred<{ generation: ReturnType<typeof job> }>();
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations/quote') return quote.promise;
            return { generations: [] };
        });
        const { props } = setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        fireEvent.change(screen.getByLabelText('Describe the post or edit'), {
            target: { value: 'Keep this prompt' },
        });
        const button = screen.getByRole('button', {
            name: 'Get cost estimate',
        });
        fireEvent.click(button);
        fireEvent.click(button);
        await waitFor(() =>
            expect(
                mocks.request.mock.calls.filter(
                    ([url]) => url === '/generations/quote',
                ),
            ).toHaveLength(1),
        );
        expect(props.ensureProject).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByRole('button', { name: 'Confirm and generate' }),
        ).not.toBeInTheDocument();
        await act(async () => quote.resolve({ generation: job() }));
        expect(
            screen.getByRole('button', { name: 'Confirm and generate' }),
        ).toBeEnabled();
        expect(
            mocks.request.mock.calls.some(([url]) =>
                String(url).endsWith('/confirm'),
            ),
        ).toBe(false);
        expect(mocks.request).toHaveBeenCalledWith(
            '/generations/quote',
            expect.objectContaining({
                method: 'POST',
                body: expect.objectContaining({
                    project_id: 'project-1',
                    expected_workspace_id: 'workspace-1',
                    prompt: 'Keep this prompt',
                    idempotency_key: expect.any(String),
                }),
            }),
        );
    });

    it('preserves editable inputs on failure and allows a deliberate retry', async () => {
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations/quote')
                throw new Error('Provider estimate unavailable');
            return { generations: [] };
        });
        setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        fireEvent.change(screen.getByLabelText('Describe the post or edit'), {
            target: { value: 'My carefully written prompt' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Provider estimate unavailable',
        );
        expect(screen.getByLabelText('Describe the post or edit')).toHaveValue(
            'My carefully written prompt',
        );
        expect(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        ).toBeEnabled();
    });

    it('requires an image for edits and captures the whole canvas only when chosen', async () => {
        const { props } = setup();
        await screen.findByRole('option', { name: 'Edit image' });
        fireEvent.change(screen.getByLabelText('AI tool'), {
            target: { value: 'edit' },
        });
        expect(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Image to send'), {
            target: { value: 'canvas' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        );
        await screen.findByRole('button', { name: 'Confirm and generate' });
        expect(props.captureCanvas).toHaveBeenCalledTimes(1);
        expect(mocks.request).toHaveBeenCalledWith(
            '/generations/quote',
            expect.objectContaining({
                body: expect.objectContaining({
                    operation: 'edit',
                    asset_id: output.asset.id,
                }),
            }),
        );
    });

    it('offers only resolutions the configured server supports', async () => {
        setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        expect(
            screen.getByLabelText('Resolution').querySelectorAll('option'),
        ).toHaveLength(2);
        expect(
            screen.queryByRole('option', { name: '4K' }),
        ).not.toBeInTheDocument();
    });

    it('imports completed assets once but does not change slides until requested', async () => {
        mocks.request.mockImplementation(async (url: string) =>
            url === '/generations/capabilities'
                ? capabilities
                : {
                      generations: [
                          job({
                              status: 'completed',
                              can_confirm: false,
                              outputs: [output],
                          }),
                      ],
                  },
        );
        const { props } = setup();
        const button = await screen.findByRole('button', {
            name: 'Add images as slides',
        });
        expect(props.onAssets).toHaveBeenCalledExactlyOnceWith([output.asset]);
        expect(props.onOutputs).not.toHaveBeenCalled();
        fireEvent.click(button);
        expect(props.onOutputs).toHaveBeenCalledExactlyOnceWith(
            [output],
            'generate',
        );
    });

    it('deduplicates confirmation clicks while the submission result is pending', async () => {
        const submission = deferred<{ generation: ReturnType<typeof job> }>();
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url.endsWith('/confirm')) return submission.promise;
            return { generations: [job()] };
        });
        setup();
        const button = await screen.findByRole('button', {
            name: 'Confirm and generate',
        });
        fireEvent.click(button);
        fireEvent.click(button);
        await waitFor(() =>
            expect(
                mocks.request.mock.calls.filter(([url]) =>
                    String(url).endsWith('/confirm'),
                ),
            ).toHaveLength(1),
        );
        await act(async () =>
            submission.resolve({
                generation: job({ status: 'unknown', can_confirm: false }),
            }),
        );
        expect(
            screen.getByText(/Submission outcome is uncertain/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Confirm and generate' }),
        ).not.toBeInTheDocument();
    });

    it('sends cancellation once and does not resubmit a queued job', async () => {
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url.endsWith('/cancel'))
                return {
                    generation: job({
                        status: 'cancelled',
                        can_confirm: false,
                        can_cancel: false,
                    }),
                };
            return {
                generations: [
                    job({
                        status: 'queued',
                        can_confirm: false,
                        can_cancel: true,
                    }),
                ],
            };
        });
        setup();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Request cancellation' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', { name: 'Request cancellation' }),
            ).not.toBeInTheDocument(),
        );
        expect(
            mocks.request.mock.calls.filter(([url]) =>
                String(url).endsWith('/cancel'),
            ),
        ).toHaveLength(1);
        expect(
            mocks.request.mock.calls.some(([url]) =>
                String(url).endsWith('/confirm'),
            ),
        ).toBe(false);
    });

    it('does not submit a quote after the panel closes while project preparation is pending', async () => {
        const saving = deferred<string>();
        const { unmount } = setup({
            ensureProject: vi.fn(() => saving.promise),
        });
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Get cost estimate' }),
        );
        unmount();
        await act(async () => saving.resolve('project-1'));
        expect(
            mocks.request.mock.calls.some(
                ([url]) => url === '/generations/quote',
            ),
        ).toBe(false);
    });

    it('clears prior history immediately when switching projects and ignores its late history response', async () => {
        const oldHistory = deferred<{
            generations: ReturnType<typeof job>[];
        }>();
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations?project=project-1')
                return oldHistory.promise;
            return { generations: [] };
        });
        const { props, rerender } = setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        rerender(<CreatorGenerationPanel {...props} projectId="project-2" />);
        await act(async () =>
            oldHistory.resolve({
                generations: [job({ prompt: 'Old project private prompt' })],
            }),
        );
        expect(
            screen.queryByText('Old project private prompt'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Confirm and generate' }),
        ).not.toBeInTheDocument();
    });

    it('ignores completed output from a pending quote after a different project becomes active', async () => {
        const quote = deferred<{ generation: ReturnType<typeof job> }>();
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations/quote') return quote.promise;
            return { generations: [] };
        });
        const { props, rerender } = setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
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
        rerender(<CreatorGenerationPanel {...props} projectId="project-2" />);
        await act(async () =>
            quote.resolve({
                generation: job({
                    status: 'completed',
                    can_confirm: false,
                    outputs: [output],
                }),
            }),
        );
        expect(props.onAssets).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Add images as slides' }),
        ).not.toBeInTheDocument();
    });

    it('reports slide-limit insertion failures without claiming success or losing the result', async () => {
        mocks.request.mockImplementation(async (url: string) =>
            url === '/generations/capabilities'
                ? capabilities
                : {
                      generations: [
                          job({
                              status: 'completed',
                              can_confirm: false,
                              outputs: [output],
                          }),
                      ],
                  },
        );
        setup({
            onOutputs: vi.fn(() => {
                throw new Error('A carousel supports at most ten slides.');
            }),
        });
        const button = await screen.findByRole('button', {
            name: 'Add images as slides',
        });
        fireEvent.click(button);
        expect(mocks.error).toHaveBeenCalledExactlyOnceWith(
            'A carousel supports at most ten slides.',
        );
        expect(mocks.success).not.toHaveBeenCalled();
        expect(button).toBeEnabled();
        expect(screen.getByAltText('Generated product')).toBeInTheDocument();
    });

    it('retries an interrupted image import without issuing another billable submission', async () => {
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations/job-1')
                return {
                    generation: job({
                        status: 'completed',
                        can_confirm: false,
                        outputs: [output],
                    }),
                };
            return {
                generations: [
                    job({
                        status: 'import_failed',
                        can_confirm: false,
                        can_retry_import: true,
                    }),
                ],
            };
        });
        const { props } = setup();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Retry image import' }),
        );
        await screen.findByRole('button', { name: 'Add images as slides' });
        expect(props.onAssets).toHaveBeenCalledExactlyOnceWith([output.asset]);
        expect(
            mocks.request.mock.calls.filter(
                ([url]) => url === '/generations/job-1',
            ),
        ).toHaveLength(1);
        expect(
            mocks.request.mock.calls.some(([url]) =>
                /\/(quote|confirm)$/.test(url),
            ),
        ).toBe(false);
    });

    it('stops active-job polling after close and never resubmits the provider job', async () => {
        let resolveHistory!: (value: unknown) => void;
        mocks.request.mockImplementation(async (url: string) => {
            if (url === '/generations/capabilities') return capabilities;
            if (url === '/generations/job-1')
                return {
                    generation: job({ status: 'running', can_confirm: false }),
                };
            return new Promise((resolve) => {
                resolveHistory = resolve;
            });
        });
        const { unmount } = setup();
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Get cost estimate' }),
            ).toBeEnabled(),
        );
        vi.useFakeTimers();
        await act(async () =>
            resolveHistory({
                generations: [job({ status: 'queued', can_confirm: false })],
            }),
        );
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1500);
        });
        expect(
            mocks.request.mock.calls.filter(
                ([url]) => url === '/generations/job-1',
            ),
        ).toHaveLength(1);
        unmount();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(20_000);
        });
        expect(
            mocks.request.mock.calls.filter(
                ([url]) => url === '/generations/job-1',
            ),
        ).toHaveLength(1);
        expect(
            mocks.request.mock.calls.some(([url]) =>
                /\/(quote|confirm)$/.test(url),
            ),
        ).toBe(false);
    });
});

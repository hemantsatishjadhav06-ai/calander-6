import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { TemplateAssignments } from '@/components/content/template-assignments';
import BoardPage from '@/pages/content/board';
import LibraryPage, { AssetCard } from '@/pages/content/library';
import type {
    ContentIdea,
    LibraryAsset,
    TemplateDestination,
} from '@/types/content';

const mocks = vi.hoisted(() => ({
    run: vi.fn(),
    request: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    get: vi.fn(),
    error: '',
    busy: false,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
    }: {
        children: ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    router: { reload: mocks.reload, visit: mocks.visit, get: mocks.get },
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
vi.mock('@/hooks/content/use-content-mutation', () => ({
    useContentMutation: () => ({
        run: mocks.run,
        busy: mocks.busy,
        error: mocks.error,
    }),
}));
vi.mock('@/lib/creator-api', () => ({ creatorRequest: mocks.request }));
vi.mock('@/components/content/content-forms', () => ({
    Field: ({ label, children }: { label: string; children: ReactNode }) => (
        <label>
            {label}
            {children}
        </label>
    ),
    Feedback: ({ error }: { error: string }) =>
        error ? <p role="alert">{error}</p> : null,
    selectStyle: '',
    IdeaForm: () => <div>Idea editor</div>,
}));
vi.mock(
    '@/actions/App/Http/Controllers/Content/ContentAssetLibraryController',
    () => ({
        index: Object.assign(() => ({ url: '/library' }), {
            url: () => '/library',
        }),
        update: { url: (id: string) => `/library/${id}` },
        restore: { url: (id: string) => `/library/${id}/restore` },
        versions: { url: (id: string) => `/library/${id}/versions` },
        uploadVersion: { url: (id: string) => `/library/${id}/versions` },
    }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Content/ContentIdeaBoardController',
    () => ({ move: { url: (id: string) => `/ideas/${id}/move` } }),
);
vi.mock(
    '@/actions/App/Http/Controllers/Content/ContentWorkspaceController',
    () => ({
        draftTemplate: { url: (id: string) => `/templates/${id}/draft` },
    }),
);
vi.mock('@/routes/content/brand', () => ({ index: () => ({ url: '/brand' }) }));
vi.mock('@/routes/content/ideas', () => ({
    index: () => ({ url: '/ideas' }),
    convert: { url: (id: string) => `/ideas/${id}/draft` },
}));
vi.mock('@/routes/creator/assets', () => ({
    store: { url: () => '/creator/assets' },
}));

const asset: LibraryAsset = {
    id: 'asset-1',
    workspace_id: 'workspace-1',
    name: 'Campaign image',
    content_url: '/private/image',
    kind: 'image',
    mime: 'image/png',
    width: 100,
    height: 100,
    size_bytes: 300,
    folder: 'Campaign',
    tags: ['launch'],
    starred: false,
    archived_at: null,
    revision: 3,
    version: 2,
    root_asset_id: 'root-1',
    parent_asset_id: 'root-1',
    created_at: null,
};
function idea(
    id: string,
    title: string,
    values: Partial<ContentIdea> = {},
): ContentIdea {
    return {
        id,
        title,
        brief: null,
        caption: null,
        category: 'Launch',
        tags: [],
        status: 'planned',
        due_on: null,
        template_id: null,
        draft_post_id: null,
        creator_project_id: null,
        revision: 1,
        post_url: null,
        ...values,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.run.mockResolvedValue({});
    mocks.request.mockResolvedValue({ assets: [asset] });
    mocks.error = '';
    mocks.busy = false;
});

describe('asset library interactions', () => {
    it('saves organized metadata and stars using the workspace and expected revision', async () => {
        render(<AssetCard asset={asset} workspaceId="workspace-1" canManage />);
        fireEvent.change(screen.getByLabelText('Folder'), {
            target: { value: 'Autumn' },
        });
        fireEvent.change(screen.getByLabelText('Tags · comma-separated'), {
            target: { value: 'launch, autumn, launch' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save organization' }),
        );
        await waitFor(() =>
            expect(mocks.run).toHaveBeenCalledWith(
                '/library/asset-1',
                'PUT',
                expect.objectContaining({
                    folder: 'Autumn',
                    tags: ['launch', 'autumn'],
                    expected_workspace_id: 'workspace-1',
                    expected_revision: 3,
                }),
            ),
        );
        expect(mocks.reload).toHaveBeenCalled();
    });
    it('archives reversibly without a delete request', async () => {
        render(<AssetCard asset={asset} workspaceId="workspace-1" canManage />);
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
        await waitFor(() =>
            expect(mocks.run).toHaveBeenCalledWith(
                '/library/asset-1',
                'PUT',
                expect.objectContaining({ archived: true }),
            ),
        );
    });
    it('loads history and restores the chosen revision as a new immutable reference', async () => {
        render(<AssetCard asset={asset} workspaceId="workspace-1" canManage />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Version history' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Restore v2' }),
        );
        await waitFor(() =>
            expect(mocks.run).toHaveBeenCalledWith(
                '/library/asset-1/restore',
                'POST',
                { expected_workspace_id: 'workspace-1', expected_revision: 3 },
            ),
        );
        expect(mocks.reload).toHaveBeenCalled();
    });
    it('prevents delayed history from crossing a workspace switch', async () => {
        let finish!: (result: { assets: LibraryAsset[] }) => void;
        mocks.request.mockImplementation(
            (_url: string, options: { signal: AbortSignal }) =>
                new Promise((resolve) => {
                    finish = resolve;
                    expect(options.signal.aborted).toBe(false);
                }),
        );
        const props = {
            canManage: true,
            assets: {
                data: [asset],
                total: 1,
                current_page: 1,
                last_page: 1,
                prev_page_url: null,
                next_page_url: null,
            },
            filters: {
                q: '',
                folder: '',
                tag: '',
                starred: false,
                archived: false,
            },
            folders: [],
            tags: [],
        };
        const { rerender } = render(
            <LibraryPage {...props} workspaceId="workspace-1" />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Version history' }),
        );
        const signal = mocks.request.mock.calls[0][1].signal as AbortSignal;
        rerender(
            <LibraryPage
                {...props}
                workspaceId="workspace-2"
                assets={{ ...props.assets, data: [] }}
            />,
        );
        expect(signal.aborted).toBe(true);
        await act(async () => finish({ assets: [asset] }));
        expect(
            screen.queryByRole('button', { name: 'Restore v2' }),
        ).not.toBeInTheDocument();
    });
    it('shows retryable history failures and hides edit actions for readers', async () => {
        mocks.request.mockRejectedValue(new Error('Session expired. Reload.'));
        render(
            <AssetCard
                asset={asset}
                workspaceId="workspace-1"
                canManage={false}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Version history' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Session expired',
        );
        expect(
            screen.queryByRole('button', { name: 'Archive' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Version history' }),
        ).toBeEnabled();
    });
});

describe('ordered idea board', () => {
    it('moves within a lane using a stable before anchor and prevents moving past its boundary', async () => {
        render(
            <BoardPage
                workspaceId="workspace-1"
                canManage
                ideas={[
                    idea('first', 'First'),
                    idea('second', 'Second'),
                    idea('third', 'Third'),
                ]}
                templateOptions={[]}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Move First up' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Move First down' }),
        );
        await waitFor(() =>
            expect(mocks.run).toHaveBeenCalledWith(
                '/ideas/first/move',
                'POST',
                {
                    expected_workspace_id: 'workspace-1',
                    expected_revision: 1,
                    status: 'planned',
                    before_id: 'third',
                },
            ),
        );
    });
    it('filters by category and preserves an archived converted draft link', () => {
        render(
            <BoardPage
                workspaceId="workspace-1"
                canManage
                ideas={[
                    idea('first', 'First'),
                    idea('other', 'Other', { category: 'Evergreen' }),
                    idea('done', 'Converted', {
                        status: 'archived',
                        draft_post_id: 'post',
                        post_url: '/posts/post',
                    }),
                ]}
                templateOptions={[]}
            />,
        );
        fireEvent.change(screen.getByLabelText('Campaign / category'), {
            target: { value: 'Launch' },
        });
        expect(screen.queryByText('Other')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open draft' }),
        ).toHaveAttribute('href', '/posts/post');
        const select = screen.getByLabelText('Move Converted');
        expect(
            within(select).queryByRole('option', { name: 'Inbox' }),
        ).not.toBeInTheDocument();
        expect(
            within(select).getByRole('option', { name: 'Drafted' }),
        ).toBeInTheDocument();
    });
    it('resets private category and search state on workspace change', () => {
        const props = {
            canManage: true,
            ideas: [idea('first', 'First')],
            templateOptions: [],
        };
        const { rerender } = render(
            <BoardPage {...props} workspaceId="workspace-1" />,
        );
        fireEvent.change(screen.getByLabelText('Search ideas'), {
            target: { value: 'private query' },
        });
        rerender(<BoardPage {...props} workspaceId="workspace-2" />);
        expect(screen.getByLabelText('Search ideas')).toHaveValue('');
    });
});

describe('template assignments', () => {
    function AssignmentHarness() {
        const [destination, setDestination] = useState<TemplateDestination>({
            kind: 'none',
        });
        const [mediaIds, setMediaIds] = useState<string[]>([]);
        return (
            <>
                <TemplateAssignments
                    accounts={[
                        { id: 'account', name: 'Brand', platform: 'instagram' },
                    ]}
                    assets={[
                        asset,
                        { ...asset, id: 'asset-2', name: 'Second image' },
                    ]}
                    destination={destination}
                    mediaIds={mediaIds}
                    onDestination={setDestination}
                    onMedia={setMediaIds}
                />
                <output data-testid="assignment-state">
                    {JSON.stringify({ destination, mediaIds })}
                </output>
            </>
        );
    }
    it('selects explicit accounts and retains the chosen media ordering', () => {
        render(<AssignmentHarness />);
        fireEvent.change(screen.getByLabelText('Default destinations'), {
            target: { value: 'accounts' },
        });
        fireEvent.click(screen.getByLabelText('Brand · instagram'));
        fireEvent.click(screen.getByLabelText('Second image · v2'));
        fireEvent.click(screen.getByLabelText('Campaign image · v2'));
        expect(screen.getByTestId('assignment-state')).toHaveTextContent(
            '"mediaIds":["asset-2","asset-1"]',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Move media 2 up' }),
        );
        expect(screen.getByTestId('assignment-state')).toHaveTextContent(
            '"mediaIds":["asset-1","asset-2"]',
        );
        expect(screen.getByTestId('assignment-state')).toHaveTextContent(
            '"ids":["account"]',
        );
    });
});

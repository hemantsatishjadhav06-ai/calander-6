import { useHttp } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type {
    Account,
    PostApproval,
    PostView,
    TargetView,
} from '@/types/compose';

import { PostApprovalPanel, reviewPreviews } from '../post-approval-panel';

vi.mock('@inertiajs/react', () => ({ useHttp: vi.fn() }));
vi.mock('@/hooks/posts/use-scheduling-timezone', () => ({
    useSchedulingTimezone: () => 'Asia/Kolkata',
}));
vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ open, children }: { open: boolean; children: ReactNode }) =>
        open ? <div>{children}</div> : null,
    DialogContent: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DialogDescription: ({ children }: { children: ReactNode }) => (
        <p>{children}</p>
    ),
    DialogFooter: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DialogHeader: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DialogTitle: ({ children }: { children: ReactNode }) => <h2>{children}</h2>,
}));
vi.mock('../platform-preview-panel', () => ({
    PlatformPreviewPanel: ({ preview }: { preview: PlatformPreview }) => (
        <div>
            {preview.accountHandle}:{' '}
            {preview.items.map((item) => item.text).join(' | ')}
        </div>
    ),
}));

const httpPost = vi.fn();
const transform = vi.fn();
const save = vi.fn();
const adopt = vi.fn();

function approval(partial: Partial<PostApproval> = {}): PostApproval {
    return {
        required: true,
        status: 'awaiting_approval',
        revision: 'reviewed-revision',
        reviewed_revision: null,
        requested_at: '2026-10-02T10:00:00Z',
        approved_at: null,
        approved_by: null,
        can_approve: true,
        planned_schedule_at: null,
        rejection_reason: null,
        rejected_at: null,
        rejected_by: null,
        ...partial,
    };
}

const accounts: Account[] = ['neopolis', 'more-space'].map((id) => ({
    id,
    platform: 'x',
    handle: `@${id}`,
    display_name: id,
    avatar_url: null,
    max_text_length: 280,
    x_premium: false,
}));

function post(review = approval()): PostView {
    return {
        id: 'post-1',
        base_text: 'Base content',
        segments: ['Base content'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-10-02T10:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'all', id: null },
        media: [],
        approval: review,
        targets: accounts.map(
            (account, index) =>
                ({
                    id: `target-${index}`,
                    connected_account_id: account.id,
                    platform: 'x',
                    handle: account.handle,
                    display_name: account.display_name,
                    avatar_url: null,
                    sections: [index ? 'Brand override' : 'Base content'],
                    content_override: index
                        ? { segments: ['Brand override'] }
                        : null,
                    auto_split: true,
                    format: 'feed',
                    issues: [],
                    status: 'pending',
                    error_kind: null,
                    error_message: null,
                    attempts: 0,
                    remote_id: null,
                }) satisfies TargetView,
        ),
    };
}

function props(saved = post()) {
    return {
        post: saved,
        required: true,
        unsavedChanges: false,
        plannedAt: null,
        accounts,
        limits: [],
        onSaveDraft: save,
        onGetServerPost: () => saved,
        onServerPost: adopt,
    };
}

beforeEach(() => {
    vi.clearAllMocks();
    save.mockResolvedValue(true);
    httpPost.mockResolvedValue({
        post: post(
            approval({
                status: 'approved',
                reviewed_revision: 'reviewed-revision',
            }),
        ),
    });
    vi.mocked(useHttp).mockReturnValue({
        post: httpPost,
        transform,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
});

describe('owner draft review', () => {
    it('previews every destination with its persisted content override', () => {
        const previews = reviewPreviews(post(), accounts, []);
        expect(previews.map(({ preview }) => preview.accountHandle)).toEqual([
            '@neopolis',
            '@more-space',
        ]);
        expect(previews[0].preview.items[0].text).toBe('Base content');
        expect(previews[1].preview.items[0].text).toBe('Brand override');
    });

    it('shows the owner all destinations and approves the previewed revision without publishing', async () => {
        render(<PostApprovalPanel {...props()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review destinations' }),
        );
        expect(
            await screen.findByText('@neopolis: Base content'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('@more-space: Brand override'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Approve draft' }));
        await waitFor(() =>
            expect(httpPost).toHaveBeenCalledWith(
                '/posts/post-1/review/approve',
            ),
        );
        expect(transform.mock.calls.at(-1)?.[0]()).toEqual({
            revision: 'reviewed-revision',
        });
        expect(adopt).toHaveBeenCalledOnce();
        expect(httpPost).toHaveBeenCalledOnce();
    });

    it('sends change feedback against the previewed revision', async () => {
        render(<PostApprovalPanel {...props()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review destinations' }),
        );
        await screen.findByRole('button', { name: 'Request changes' });
        fireEvent.change(
            screen.getByLabelText(
                'Feedback when requesting changes (optional)',
            ),
            { target: { value: 'Please correct the offer details.' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request changes' }),
        );
        await waitFor(() =>
            expect(httpPost).toHaveBeenCalledWith(
                '/posts/post-1/review/reject',
            ),
        );
        expect(transform.mock.calls.at(-1)?.[0]()).toEqual({
            revision: 'reviewed-revision',
            reason: 'Please correct the offer details.',
        });
    });

    it('does not approve a revision changed while the preview was open', async () => {
        let latest = post();
        render(
            <PostApprovalPanel
                {...props(latest)}
                onGetServerPost={() => latest}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review destinations' }),
        );
        await screen.findByRole('button', { name: 'Approve draft' });
        latest = post(approval({ revision: 'new-revision', status: 'draft' }));
        fireEvent.click(screen.getByRole('button', { name: 'Approve draft' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'changed after the preview opened',
        );
        expect(httpPost).not.toHaveBeenCalled();
    });

    it('disables approval as soon as local unsaved edits change the preview', async () => {
        const base = props();
        const rendered = render(<PostApprovalPanel {...base} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review destinations' }),
        );
        await screen.findByRole('button', { name: 'Approve draft' });
        rendered.rerender(<PostApprovalPanel {...base} unsavedChanges />);
        expect(
            screen.getByRole('button', { name: 'Approve draft' }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'publishing plan changed',
        );
        expect(httpPost).not.toHaveBeenCalled();
    });

    it('does not expose review actions to other members', () => {
        render(
            <PostApprovalPanel
                {...props(post(approval({ can_approve: false })))}
            />,
        );
        expect(screen.getByText('Awaiting approval')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Review destinations' }),
        ).not.toBeInTheDocument();
    });

    it('leaves workspaces with approval disabled unchanged', () => {
        const { container } = render(
            <PostApprovalPanel
                {...props(post(approval({ required: false })))}
                required={false}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});

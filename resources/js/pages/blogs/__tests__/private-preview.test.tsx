import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { BlogContext, BlogDraft } from '@/types/blogs';

import BlogPreview from '../preview';

const { startPolling, stopPolling, usePoll } = vi.hoisted(() => ({
    startPolling: vi.fn(),
    stopPolling: vi.fn(),
    usePoll: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePoll,
    Head: () => null,
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
    Form: ({
        children,
        ...props
    }: React.ComponentProps<'form'> & {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => React.ReactNode;
    }) => <form {...props}>{children({ processing: false, errors: {} })}</form>,
}));

beforeEach(() => {
    vi.clearAllMocks();
    usePoll.mockReturnValue({ start: startPolling, stop: stopPolling });
});

function draft(overrides: Partial<BlogDraft> = {}): BlogDraft {
    return {
        id: 'article-1',
        title: 'Comparing homes',
        slug: 'comparing-homes',
        excerpt: 'A practical guide.',
        body: 'Compare verified property information before choosing a home.',
        featured_image_url: null,
        featured_image_alt: null,
        seo_title: null,
        seo_description: null,
        canonical_url: null,
        content_revision: 1,
        revision: 'a'.repeat(64),
        status: 'awaiting_approval',
        requested_at: '2026-10-02T10:00:00Z',
        approved_at: null,
        approved_by: null,
        rejected_at: null,
        rejection_reason: null,
        updated_at: '2026-10-02T10:00:00Z',
        can_review: true,
        ...overrides,
    };
}

function show(
    blog: BlogDraft,
    publication: BlogContext['publication'] = {
        available: false,
        reason: 'Website publishing needs a verified site connection.',
    },
) {
    return render(
        <BlogPreview
            blog={blog}
            brand={{
                name: 'Neopolis',
                website_url: 'https://neopolisinfra.com',
            }}
            publication={publication}
        />,
    );
}

describe('private article review', () => {
    it('renders untrusted article text without executing HTML or fetching external images', () => {
        const payload =
            '<img src="https://tracking.example/pixel" onerror="alert(1)"><script>alert(2)</script>';
        const view = show(
            draft({
                body: payload,
                featured_image_url: 'https://images.example/project.jpg',
            }),
        );
        expect(screen.getByText(payload)).toBeInTheDocument();
        expect(view.container.querySelector('script')).toBeNull();
        expect(view.container.querySelector('img')).toBeNull();
    });

    it('binds each owner review submission to the rendered revision without providing publishing controls', () => {
        const view = show(draft());
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Request changes' }),
        ).toBeInTheDocument();
        const forms = view.container.querySelectorAll('form');
        expect(forms).toHaveLength(2);
        for (const form of forms) {
            expect(form.querySelector('input[name="revision"]')).toHaveValue(
                'a'.repeat(64),
            );
        }
        expect(
            screen.queryByRole('button', { name: /publish/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Approval keeps this draft private. Only the workspace owner can publish an approved version.',
            ),
        ).toBeInTheDocument();
    });

    it('shows members a private preview without approval buttons', () => {
        show(draft({ can_review: false }));
        expect(
            screen.queryByRole('button', { name: 'Approve this version' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Request changes' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'The workspace owner reviews and approves blog drafts.',
            ),
        ).toBeInTheDocument();
    });

    it('requires both owner approval and a verified publishing connection before showing the publish action', () => {
        const view = show(draft({ status: 'approved' }));
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();

        for (const status of [
            'draft',
            'awaiting_approval',
            'rejected',
        ] as const) {
            view.rerender(
                <BlogPreview
                    blog={draft({ status })}
                    brand={{ name: 'Neopolis', website_url: null }}
                    publication={{ available: true, reason: 'Connected.' }}
                />,
            );
            expect(
                screen.queryByRole('button', {
                    name: 'Publish approved version',
                }),
            ).not.toBeInTheDocument();
        }

        view.rerender(
            <BlogPreview
                blog={draft({ status: 'approved', can_review: false })}
                brand={{ name: 'Neopolis', website_url: null }}
                publication={{ available: true, reason: 'Connected.' }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();
        expect(startPolling).not.toHaveBeenCalled();
    });

    it('offers an explicit owner publish submission bound to the approved revision', () => {
        const view = show(draft({ status: 'approved' }), {
            available: true,
            reason: 'Verified website connected.',
        });
        const button = screen.getByRole('button', {
            name: 'Publish approved version',
        });
        expect(button).toBeEnabled();
        const form = button.closest('form');
        expect(form).toHaveAttribute('action', '/blogs/article-1/publish');
        expect(form).toHaveAttribute('method', 'post');
        expect(form?.querySelector('input[name="revision"]')).toHaveValue(
            'a'.repeat(64),
        );
        expect(view.container.querySelectorAll('form')).toHaveLength(1);
        expect(
            screen.getByText(
                'Approval keeps this draft private. Only the workspace owner can publish an approved version.',
            ),
        ).toBeInTheDocument();
        expect(startPolling).not.toHaveBeenCalled();
    });

    it('polls queued publication and stops after the approved version becomes live', () => {
        const view = show(
            draft({ status: 'approved', publication_status: 'queued' }),
            { available: true, reason: 'Connected.' },
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Publishing queued.',
        );
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();
        expect(usePoll).toHaveBeenCalledWith(
            3000,
            { only: ['blog', 'publication'] },
            { autoStart: false },
        );
        expect(startPolling).toHaveBeenCalledOnce();

        view.rerender(
            <BlogPreview
                blog={draft({
                    status: 'approved',
                    publication_status: 'published',
                    published_revision: 'a'.repeat(64),
                    published_url:
                        'https://neopolisinfra.com/blog/comparing-homes/',
                })}
                brand={{
                    name: 'Neopolis',
                    website_url: 'https://neopolisinfra.com',
                }}
                publication={{ available: true, reason: 'Connected.' }}
            />,
        );
        expect(stopPolling).toHaveBeenCalledOnce();
        expect(screen.getByRole('status')).toHaveTextContent(
            'This approved version is live on your website.',
        );
        expect(
            screen.getByRole('link', { name: 'View published article' }),
        ).toHaveAttribute(
            'href',
            'https://neopolisinfra.com/blog/comparing-homes/',
        );
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();
    });

    it('shows publication in progress without offering duplicate publication or edits', () => {
        show(draft({ status: 'approved', publication_status: 'publishing' }), {
            available: true,
            reason: 'Connected.',
        });
        expect(screen.getByRole('status')).toHaveTextContent(
            'Publishing the approved version to your website…',
        );
        expect(
            screen.queryByRole('button', { name: /publish/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Edit draft' }),
        ).not.toBeInTheDocument();
        expect(startPolling).toHaveBeenCalledOnce();
    });

    it('keeps a revised draft private while linking to its previously published version', () => {
        show(
            draft({
                status: 'draft',
                content_revision: 2,
                publication_status: 'published',
                published_revision: 'b'.repeat(64),
                published_url:
                    'https://neopolisinfra.com/blog/comparing-homes/',
            }),
            { available: true, reason: 'Connected.' },
        );
        expect(
            screen.getByText(
                'The previously published version remains live. This draft stays private until you approve and publish it.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'View previously published article',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();
    });

    it('shows publishing failures as escaped text and allows an explicit approved retry', () => {
        const message = '<script>provider response</script>';
        const view = show(
            draft({
                status: 'approved',
                publication_status: 'failed',
                publication_error: message,
            }),
            { available: true, reason: 'Connected.' },
        );
        expect(screen.getByRole('alert')).toHaveTextContent(message);
        expect(view.container.querySelector('script')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Publish approved version' }),
        ).toBeEnabled();
        expect(startPolling).not.toHaveBeenCalled();
    });

    it('keeps the live link and prevents duplicate publication while showing a recovery warning', () => {
        const warning =
            'The article is live. Restoring the website publishing settings needs attention.';
        show(
            draft({
                status: 'approved',
                publication_status: 'published',
                publication_error: warning,
                published_revision: 'a'.repeat(64),
                published_url:
                    'https://neopolisinfra.com/blog/comparing-homes/',
            }),
            { available: true, reason: 'Connected.' },
        );
        expect(screen.getByRole('alert')).toHaveTextContent(warning);
        expect(screen.getByRole('status')).toHaveTextContent(
            'This approved version is live on your website.',
        );
        expect(
            screen.getByRole('link', { name: 'View published article' }),
        ).toHaveAttribute(
            'href',
            'https://neopolisinfra.com/blog/comparing-homes/',
        );
        expect(
            screen.queryByRole('button', { name: 'Publish approved version' }),
        ).not.toBeInTheDocument();
        expect(startPolling).not.toHaveBeenCalled();
    });

    it.each([
        'javascript:alert(1)',
        'https://other.example/blog/comparing-homes/',
        'https://user:password@neopolisinfra.com/blog/comparing-homes/',
    ])(
        'does not link to an unsafe published destination: %s',
        (published_url) => {
            show(
                draft({
                    status: 'approved',
                    published_revision: 'a'.repeat(64),
                    published_url,
                }),
                { available: true, reason: 'Connected.' },
            );
            expect(
                screen.queryByRole('link', { name: 'View published article' }),
            ).not.toBeInTheDocument();
        },
    );
});

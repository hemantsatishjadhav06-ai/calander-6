import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { BlogDraft } from '@/types/blogs';

import BlogPreview from '../preview';

vi.mock('@inertiajs/react', () => ({
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

function show(blog: BlogDraft) {
    return render(
        <BlogPreview
            blog={blog}
            brand={{
                name: 'Neopolis',
                website_url: 'https://neopolisinfra.com',
            }}
            publication={{
                available: false,
                reason: 'Website publishing needs a verified site connection.',
            }}
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
                'This draft is private, including after approval.',
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
});

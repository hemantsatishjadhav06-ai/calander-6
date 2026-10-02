import { router } from '@inertiajs/core';
import type { InertiaConfig, Page } from '@inertiajs/core';
import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { BlogDraft } from '@/types/blogs';

import BlogEdit from '../edit';

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();
    return {
        ...actual,
        Head: () => null,
        Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
            <a {...props}>{children}</a>
        ),
    };
});

afterEach(() => vi.restoreAllMocks());

function draft(overrides: Partial<BlogDraft> = {}): BlogDraft {
    return {
        id: 'article-1',
        title: 'Comparing homes',
        slug: 'comparing-homes',
        body: 'Original saved article.',
        excerpt: null,
        featured_image_url: null,
        featured_image_alt: null,
        seo_title: null,
        seo_description: null,
        canonical_url: null,
        content_revision: 1,
        revision: 'a'.repeat(64),
        status: 'draft',
        requested_at: null,
        approved_at: null,
        approved_by: null,
        rejected_at: null,
        rejection_reason: null,
        updated_at: '2026-10-02T10:00:00Z',
        can_review: true,
        ...overrides,
    };
}

function editor(blog: BlogDraft) {
    return (
        <BlogEdit
            blog={blog}
            brand={{
                name: 'Neopolis',
                website_url: 'https://neopolisinfra.com',
            }}
            publication={{
                available: false,
                reason: 'Website publishing needs a verified site connection.',
            }}
        />
    );
}

describe('blog editor conflicts', () => {
    it('preserves unsaved article text and the submitted revision until the user loads the latest version', () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        const view = render(editor(draft()));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Article body' }),
            { target: { value: 'My unsaved article.' } },
        );
        fireEvent.submit(view.container.querySelector('form')!);
        expect(patch).toHaveBeenCalledWith(
            '/blogs/article-1',
            expect.objectContaining({
                body: 'My unsaved article.',
                revision: 'a'.repeat(64),
            }),
            expect.any(Object),
        );
        act(() =>
            patch.mock.calls[0][2]?.onError?.({
                revision: 'This draft changed. Reload the latest version.',
            }),
        );
        view.rerender(
            editor(
                draft({
                    body: 'A teammate saved this version.',
                    revision: 'b'.repeat(64),
                    content_revision: 2,
                }),
            ),
        );
        expect(
            screen.getByRole('textbox', { name: 'Article body' }),
        ).toHaveValue('My unsaved article.');
        expect(
            view.container.querySelector('input[name="revision"]'),
        ).toHaveValue('a'.repeat(64));
        fireEvent.click(
            screen.getByRole('button', { name: 'Load latest saved version' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Article body' }),
        ).toHaveValue('A teammate saved this version.');
        expect(
            view.container.querySelector('input[name="revision"]'),
        ).toHaveValue('b'.repeat(64));
    });

    it('includes SEO and featured image edits in the actual submission and adopts the new saved revision', () => {
        const patch = vi
            .spyOn(router, 'patch')
            .mockImplementation(() => undefined);
        const view = render(editor(draft()));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search description' }),
            { target: { value: 'A verified search description.' } },
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Featured image URL' }),
            { target: { value: 'https://neopolisinfra.com/project.jpg' } },
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Canonical URL' }),
            {
                target: {
                    value: 'https://neopolisinfra.com/blog/comparing-homes',
                },
            },
        );
        fireEvent.submit(view.container.querySelector('form')!);
        expect(patch).toHaveBeenCalledWith(
            '/blogs/article-1',
            expect.objectContaining({
                seo_description: 'A verified search description.',
                featured_image_url: 'https://neopolisinfra.com/project.jpg',
                canonical_url: 'https://neopolisinfra.com/blog/comparing-homes',
            }),
            expect.any(Object),
        );
        const saved = draft({
            revision: 'b'.repeat(64),
            seo_description: 'A verified search description.',
            featured_image_url: 'https://neopolisinfra.com/project.jpg',
            canonical_url: 'https://neopolisinfra.com/blog/comparing-homes',
        });
        act(() => {
            patch.mock.calls[0][2]?.onSuccess?.({
                props: { blog: saved },
            } as unknown as Page<InertiaConfig['sharedPageProps']>);
        });
        expect(
            view.container.querySelector('input[name="revision"]'),
        ).toHaveValue('b'.repeat(64));
        expect(
            screen.queryByText(
                'Save your changes before opening the review preview.',
            ),
        ).not.toBeInTheDocument();
    });
});

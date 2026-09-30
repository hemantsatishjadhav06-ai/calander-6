import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import Features from '@/pages/public/features';
import type { PublicSiteProps } from '@/types/public';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('@/components/public/public-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const site: PublicSiteProps = {
    appName: 'SM Manager',
    company: '',
    contactEmail: '',
    address: '',
    jurisdiction: '',
    effectiveDate: '2026-09-25',
    registrationsEnabled: false,
    repoUrl: '',
};

describe('review link feature copy', () => {
    it('describes anonymous previews separately from recorded revision approvals', () => {
        render(<Features {...site} platforms={[]} />);

        const heading = screen.getByRole('heading', {
            name: 'Share a preview without another login.',
        });
        const sharing = heading.closest('section');

        expect(sharing).not.toBeNull();
        expect(
            within(sharing!).getByText(
                'Preview links are read-only; they do not record approval.',
            ),
        ).toBeInTheDocument();
        expect(
            within(sharing!).getByText(
                'Recorded approvals require a signed-in workspace reviewer and apply to the reviewed revision.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Get sign-off/)).not.toBeInTheDocument();
    });
});

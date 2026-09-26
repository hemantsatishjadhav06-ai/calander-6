import { render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import Developers from '@/pages/public/developers';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: React.ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ url: '/developers', props: { repoUrl: '' } }),
}));

beforeAll(() => {
    // The header's ThemeToggle -> useAppearance() -> prefersDark() ->
    // window.matchMedia, which jsdom does not implement.
    globalThis.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }) as unknown as typeof window.matchMedia;
});

/** As long as a real Railway host, which is where the overflow was found. */
const API_BASE_URL = 'https://sm-manager-production-33df.up.railway.app/api/v1';

function renderPage() {
    render(
        <Developers
            appName="SM Manager"
            company=""
            contactEmail=""
            address=""
            jurisdiction=""
            effectiveDate="2026-09-25"
            registrationsEnabled
            repoUrl=""
            apiBaseUrl={API_BASE_URL}
            mcpUrl="https://sm-manager-production-33df.up.railway.app/mcp"
            mcpTools={[
                {
                    name: 'get-post-tool',
                    description: 'Get one post by id.',
                },
                {
                    name: 'publish-post-tool',
                    description:
                        'Publish a post now. Irreversible. Requires confirm=true.',
                },
            ]}
        />,
    );
}

describe('Developers page', () => {
    /**
     * A long instance URL has no spaces to wrap at. Printed as-is it pushed
     * the page wider than a phone screen on the live Railway host, so every
     * place the bare URL is shown must be allowed to break anywhere.
     */
    it('lets the instance API URL wrap wherever it is shown on its own', () => {
        renderPage();

        const urls = screen.getAllByText(API_BASE_URL);

        expect(urls.length).toBeGreaterThanOrEqual(2);
        for (const url of urls) {
            expect(url.className).toContain('break-all');
        }
    });

    it('marks only the tools that wait for confirmation', () => {
        renderPage();

        const badges = screen.getAllByText('Asks first');

        expect(badges).toHaveLength(1);
        expect(
            badges[0].closest('li')?.textContent?.includes('publish-post-tool'),
        ).toBe(true);
    });

    it('counts the tools the server exposes', () => {
        renderPage();

        expect(
            screen.getByText(/^2 tools covering posts, scheduling/),
        ).toBeTruthy();
    });
});

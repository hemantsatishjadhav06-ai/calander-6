import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { operatorName } from '@/components/public/legal-page';
import PublicShell from '@/components/public/public-shell';

// PublicShell renders Inertia <Link>s, which need an initialized router.
vi.mock('@inertiajs/react', () => ({
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
}));

function hrefs(): string[] {
    return screen
        .getAllByRole('link')
        .map((link) => link.getAttribute('href') ?? '');
}

describe('PublicShell', () => {
    it('always links the three legal pages from the footer', () => {
        render(
            <PublicShell appName="SM Manager" company="">
                <p>body</p>
            </PublicShell>,
        );

        expect(hrefs()).toEqual(
            expect.arrayContaining(['/privacy', '/terms', '/data-deletion']),
        );
    });

    /**
     * A "Get started" button that bounces off a closed registration route is
     * worse than no button, so the header follows the instance setting.
     */
    it('hides the register call to action when sign-ups are closed', () => {
        render(
            <PublicShell appName="SM Manager" company="" showRegister={false}>
                <p>body</p>
            </PublicShell>,
        );

        expect(screen.queryByText('Get started')).toBeNull();
        expect(screen.getByText('Log in')).toBeTruthy();
    });

    it('shows the register call to action when sign-ups are open', () => {
        render(
            <PublicShell appName="SM Manager" company="" showRegister>
                <p>body</p>
            </PublicShell>,
        );

        expect(screen.getByText('Get started')).toBeTruthy();
    });

    it('falls back to the app name in the copyright when no company is set', () => {
        render(
            <PublicShell appName="SM Manager" company="">
                <p>body</p>
            </PublicShell>,
        );

        expect(
            screen.getByText(`© ${new Date().getFullYear()} SM Manager`),
        ).toBeTruthy();
    });

    it('prefers the configured company in the copyright', () => {
        render(
            <PublicShell appName="SM Manager" company="Neopolis Infra LLP">
                <p>body</p>
            </PublicShell>,
        );

        expect(
            screen.getByText(
                `© ${new Date().getFullYear()} Neopolis Infra LLP`,
            ),
        ).toBeTruthy();
    });
});

describe('operatorName', () => {
    /**
     * An unset INSTANCE_COMPANY_NAME must not read as "operated by SM Manager",
     * which names the software rather than whoever is answerable for the data.
     */
    it('describes the operator generically when none is configured', () => {
        expect(operatorName('')).toBe('the operator of this instance');
    });

    it('uses the configured company when there is one', () => {
        expect(operatorName('Neopolis Infra LLP')).toBe('Neopolis Infra LLP');
    });
});

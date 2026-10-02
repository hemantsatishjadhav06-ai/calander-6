import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { GettingStartedCard } from '../getting-started-card';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    router: { post: vi.fn() },
}));

afterEach(() => vi.unstubAllGlobals());

describe('onboarding with blocked browser storage', () => {
    it('renders and collapses the checklist even when preferences cannot be saved', () => {
        const blocked = () => {
            throw new DOMException('Blocked', 'SecurityError');
        };
        vi.stubGlobal('localStorage', { getItem: blocked, setItem: blocked });

        render(
            <GettingStartedCard
                onboarding={{
                    welcomed: true,
                    dismissed: false,
                    complete: false,
                    steps: [
                        {
                            key: 'connect_account',
                            label: 'Connect an account',
                            done: false,
                            href: '/accounts',
                            clickToComplete: false,
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Finish setting up' }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Collapse checklist' }),
        );
        expect(
            screen.getByRole('button', { name: 'Expand checklist' }),
        ).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(
            screen.getByRole('button', { name: 'Expand checklist' }),
        );
        expect(
            screen.getByRole('button', { name: 'Collapse checklist' }),
        ).toHaveAttribute('aria-expanded', 'true');
    });
});

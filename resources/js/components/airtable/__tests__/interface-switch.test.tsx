import { cleanup, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import { InterfaceSwitch } from '../interface-switch';

const state = vi.hoisted(() => ({ url: '/dashboard' }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: state.url }),
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: { url: string };
    }) => <a href={href.url} {...props} />,
}));
afterEach(cleanup);

it('keeps both interfaces available while indicating the dashboard', () => {
    state.url = '/dashboard';
    render(<InterfaceSwitch />);
    expect(
        screen.getByRole('navigation', { name: 'Workspace interface' }),
    ).toBeTruthy();
    expect(
        screen
            .getByRole('link', { name: 'Dashboard' })
            .getAttribute('aria-current'),
    ).toBe('page');
    expect(
        screen.getByRole('link', { name: 'Airtable' }).getAttribute('href'),
    ).toBe('/airtable');
});

it('retains the selected Airtable interface on direct links and browser history URLs', () => {
    state.url = '/airtable?post=123&source=airtable';
    render(<InterfaceSwitch />);
    expect(
        screen
            .getByRole('link', { name: 'Airtable' })
            .getAttribute('aria-current'),
    ).toBe('page');
    expect(
        screen.getByRole('link', { name: 'Dashboard' }).getAttribute('href'),
    ).toBe('/dashboard');
});

import { usePage } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WorkspaceSelector } from '../workspace-selector';

vi.mock('@inertiajs/react', () => ({ usePage: vi.fn() }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/components/ui/sidebar', () => ({
    useSidebar: () => ({ state: 'expanded' }),
    SidebarMenu: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarMenuItem: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarMenuButton: ({ children }: { children: ReactNode }) => (
        <button>{children}</button>
    ),
}));
vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuTrigger: ({ children }: { children: ReactNode }) => (
        <button>{children}</button>
    ),
    DropdownMenuContent: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuItem: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
}));
vi.mock('../create-workspace-dialog', () => ({
    CreateWorkspaceDialog: () => null,
}));
vi.mock('@/lib/workspaces/switch-workspace', () => ({
    switchWorkspace: vi.fn(),
}));

const pendingImages: HTMLImageElement[] = [];

function setPage(logo = 'https://images.example/neopolis.svg') {
    const neopolis = {
        id: 'neopolis',
        name: 'Neopolis',
        logo,
        role: 'owner',
        permissions: [],
    };
    const moreSpace = {
        id: 'more-space',
        name: 'More Space',
        logo: 'https://images.example/more-space.svg',
        role: 'owner',
    };
    vi.mocked(usePage).mockReturnValue({
        props: {
            workspaces: {
                enabled: true,
                current: neopolis,
                all: [neopolis, moreSpace],
                canCreateWorkspaces: false,
            },
        },
    } as unknown as ReturnType<typeof usePage>);
}

beforeEach(() => {
    pendingImages.length = 0;
    setPage();
    vi.spyOn(window, 'Image').mockImplementation(function () {
        const image = document.createElement('img');
        Object.defineProperty(image, 'complete', { get: () => false });
        pendingImages.push(image);

        return image;
    });
});

afterEach(() => vi.restoreAllMocks());

describe('workspace logo availability', () => {
    it('keeps clean initials in the trigger and menu after logo error events', () => {
        const { container } = render(<WorkspaceSelector />);
        expect(pendingImages).toHaveLength(3);
        act(() => pendingImages.forEach((image) => fireEvent.error(image)));

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(
            Array.from(
                container.querySelectorAll('[data-slot="avatar-fallback"]'),
            ).map((fallback) => fallback.textContent),
        ).toEqual(['N', 'N', 'MS']);
        expect(screen.getAllByText('Neopolis')).toHaveLength(2);
        expect(screen.getByText('More Space')).toBeInTheDocument();
    });

    it('shows a successfully loaded logo instead of the trigger initials', async () => {
        const { container } = render(<WorkspaceSelector />);
        await act(() => fireEvent.load(pendingImages[0]));

        expect(screen.getByRole('img', { name: 'Neopolis' })).toHaveAttribute(
            'src',
            'https://images.example/neopolis.svg',
        );
        expect(
            container
                .querySelector('[data-slot="avatar"]')
                ?.querySelector('[data-slot="avatar-fallback"]'),
        ).toBeNull();
    });

    it('loads a replacement logo after the previous URL failed', async () => {
        const rendered = render(<WorkspaceSelector />);
        act(() => pendingImages.forEach((image) => fireEvent.error(image)));
        setPage('https://images.example/replacement.svg');
        rendered.rerender(<WorkspaceSelector />);
        await act(() => fireEvent.load(pendingImages[3]));

        expect(screen.getByRole('img', { name: 'Neopolis' })).toHaveAttribute(
            'src',
            'https://images.example/replacement.svg',
        );
    });
});

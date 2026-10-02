import { useHttp, usePage } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { NotificationItem } from '@/types/notifications';

import { NotificationBell } from '../notification-bell';

const routerVisit = vi.hoisted(() => vi.fn());
const toastError = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    usePage: vi.fn(),
    router: { visit: routerVisit },
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
}));
vi.mock('sonner', () => ({ toast: { error: toastError } }));

const httpGet = vi.fn();
const httpPost = vi.fn();
const httpDelete = vi.fn();
let sequence = 0;
let items: NotificationItem[];

async function openBell() {
    render(<NotificationBell />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Notifications (2 unread)' }),
    );
    await screen.findByText('First notice');
}

beforeEach(() => {
    vi.clearAllMocks();
    sequence += 1;
    items = ['First notice', 'Second notice'].map((title, index) => ({
        id: `notice-${sequence}-${index}`,
        title,
        event: 'test',
        body: '',
        href: null,
        icon: 'bell',
        read: false,
        timeLabel: 'Just now',
    }));
    vi.mocked(usePage).mockReturnValue({
        props: {
            notifications: { items, unreadCount: 2, nextCursor: 'next-page' },
        },
    } as unknown as ReturnType<typeof usePage>);
    vi.mocked(useHttp).mockReturnValue({
        get: httpGet,
        post: httpPost,
        delete: httpDelete,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
    httpPost.mockReset().mockResolvedValue(null);
    httpDelete.mockReset().mockResolvedValue(null);
    httpGet
        .mockReset()
        .mockResolvedValue({ items: [], unreadCount: 2, nextCursor: null });
});

describe('notification failure recovery', () => {
    it('restores a notification and unread count when deletion fails', async () => {
        httpDelete.mockRejectedValue(new Error('Offline'));
        await openBell();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Delete notification' })[0],
        );

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(screen.getByText('First notice')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Notifications (2 unread)' }),
        ).toBeInTheDocument();
    });

    it('restores the list when deleting all notifications fails', async () => {
        httpDelete.mockRejectedValue(new Error('Offline'));
        await openBell();
        fireEvent.click(
            screen.getByRole('button', { name: 'Delete all notifications' }),
        );

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(screen.getByText('First notice')).toBeInTheDocument();
        expect(screen.getByText('Second notice')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Notifications (2 unread)' }),
        ).toBeInTheDocument();
    });

    it('restores unread state when marking all read fails', async () => {
        httpPost.mockRejectedValue(new Error('Offline'));
        await openBell();
        fireEvent.click(screen.getByRole('button', { name: 'Mark all read' }));

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(
            screen.getByRole('button', { name: 'Notifications (2 unread)' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Mark all read' }),
        ).toBeEnabled();
    });

    it('restores unread state when marking one read fails', async () => {
        httpPost.mockRejectedValue(new Error('Offline'));
        await openBell();
        fireEvent.click(screen.getByRole('button', { name: /First notice/ }));

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(
            screen.getByRole('button', { name: 'Notifications (2 unread)' }),
        ).toBeInTheDocument();
    });

    it('restores an actionable notification when its action is rejected', async () => {
        items[0].actions = [
            {
                key: 'accept',
                label: 'Accept invitation',
                variant: 'primary',
                method: 'post',
                href: '/invitation/accept',
            },
        ];
        routerVisit.mockImplementation((_href, options) =>
            options.onError({ invitation: 'Expired' }),
        );
        await openBell();
        fireEvent.click(
            screen.getByRole('button', { name: 'Accept invitation' }),
        );

        await waitFor(() => expect(toastError).toHaveBeenCalled());
        expect(screen.getByText('First notice')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Notifications (2 unread)' }),
        ).toBeInTheDocument();
    });

    it('sends one mutation while an optimistic action is pending', async () => {
        let finishDelete: (() => void) | undefined;
        httpDelete.mockImplementation(
            () =>
                new Promise<void>((resolve) => {
                    finishDelete = resolve;
                }),
        );
        await openBell();
        const button = screen.getByRole('button', {
            name: 'Delete all notifications',
        });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(httpDelete).toHaveBeenCalledOnce();
        expect(button).toBeDisabled();
        await act(async () => {
            finishDelete?.();
        });
    });

    it('shows feedback when loading the next notification page fails', async () => {
        httpGet.mockRejectedValue(new Error('Offline'));
        await openBell();
        const scrollArea = screen.getByRole('button', {
            name: /First notice/,
        }).parentElement;
        expect(scrollArea).not.toBeNull();
        fireEvent.scroll(scrollArea!);

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'Could not load more notifications. Please try again.',
            ),
        );
        expect(screen.getByText('First notice')).toBeInTheDocument();
    });
});

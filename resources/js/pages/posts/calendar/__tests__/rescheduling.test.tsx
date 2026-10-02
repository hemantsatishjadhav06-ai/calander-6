import { useHttp } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type * as monthGridModule from '@/components/posts/calendar/month-grid';
import type * as weekGridModule from '@/components/posts/calendar/week-grid';

import CalendarIndex from '../index';

const routerReload = vi.hoisted(() => vi.fn());
const toastError = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    usePage: () => ({ url: '/calendar/203012' }),
    Head: () => null,
    Deferred: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    router: { visit: vi.fn(), reload: routerReload },
}));
vi.mock('sonner', () => ({ toast: { error: toastError } }));
vi.mock('@/hooks/posts/use-scheduling-timezone', () => ({
    useSchedulingTimezone: () => 'UTC',
}));
vi.mock('@/components/posts/calendar/calendar-header', () => ({
    CalendarHeader: () => null,
}));
vi.mock('@/components/posts/calendar/agenda-list', () => ({
    AgendaList: () => null,
}));
vi.mock('@/components/posts/calendar/month-grid', async (importOriginal) => ({
    ...(await importOriginal<typeof monthGridModule>()),
    MonthGrid: () => null,
}));
vi.mock('@/components/posts/calendar/week-grid', async (importOriginal) => ({
    ...(await importOriginal<typeof weekGridModule>()),
    WeekGrid: () => null,
}));
vi.mock('@dnd-kit/core', () => ({
    useSensor: vi.fn(),
    useSensors: vi.fn(),
    KeyboardSensor: vi.fn(),
    PointerSensor: vi.fn(),
    pointerWithin: vi.fn(),
    DndContext: ({
        children,
        onDragEnd,
    }: {
        children: React.ReactNode;
        onDragEnd: (event: unknown) => void;
    }) => (
        <div>
            <button
                onClick={() =>
                    onDragEnd({
                        active: {
                            id: 'post-post-1',
                            data: {
                                current: {
                                    scheduledAt: '2030-12-01T10:00:00Z',
                                },
                            },
                        },
                        over: { data: { current: { day: '2030-12-02' } } },
                    })
                }
            >
                Reschedule post
            </button>
            {children}
        </div>
    ),
}));

const httpPut = vi.fn();

beforeEach(() => {
    vi.clearAllMocks();
    httpPut.mockReset().mockResolvedValue({});
    vi.mocked(useHttp).mockReturnValue({
        transform: vi.fn(),
        put: httpPut,
    } as unknown as ReturnType<typeof useHttp>);
});

describe('calendar rescheduling failures', () => {
    it('keeps the current calendar and gives feedback when a schedule save fails', async () => {
        httpPut.mockRejectedValue(new Error('Offline'));
        render(<CalendarIndex yyyymm="203012" view="month" posts={[]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reschedule post' }),
        );

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'Could not reschedule this post. Please try again.',
            ),
        );
        expect(routerReload).not.toHaveBeenCalled();
    });

    it('blocks another drop until the first reschedule finishes', async () => {
        let finishSave: (() => void) | undefined;
        httpPut.mockImplementation(
            () =>
                new Promise<void>((resolve) => {
                    finishSave = resolve;
                }),
        );
        render(<CalendarIndex yyyymm="203012" view="month" posts={[]} />);
        const button = screen.getByRole('button', { name: 'Reschedule post' });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(httpPut).toHaveBeenCalledOnce();
        await act(async () => {
            finishSave?.();
        });
        expect(routerReload).toHaveBeenCalledWith({ only: ['posts'] });
    });
});

import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useRef, useState } from 'react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import {
    QueueForm,
    SeriesForm,
    WorkflowControls,
} from '@/components/scheduling/workflow-forms';
import SchedulingIndex from '@/pages/scheduling/index';
import type { EditorialQueue, SchedulingProps } from '@/types/scheduling';

const calls = vi.hoisted(() => ({
    requests: [] as { method: string; url: string; data: unknown }[],
    complete: (() => {}) as () => void,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setData] = useState(initial);
        const [processing, setProcessing] = useState(false);
        const transform = useRef<(value: T) => unknown>((value) => value);
        function submit(
            method: string,
            url: string,
            options: { onSuccess?: () => void } = {},
        ) {
            setProcessing(true);
            calls.requests.push({ method, url, data: transform.current(data) });
            calls.complete = () => {
                setProcessing(false);
                options.onSuccess?.();
            };
        }
        return {
            data,
            processing,
            errors: {},
            setData: (key: keyof T, value: T[keyof T]) =>
                setData((previous) => ({ ...previous, [key]: value })),
            transform: (callback: (value: T) => unknown) => {
                transform.current = callback;
            },
            post: (url: string, options: { onSuccess?: () => void }) =>
                submit('post', url, options),
            put: (url: string, options: { onSuccess?: () => void }) =>
                submit('put', url, options),
            delete: (url: string, options: { onSuccess?: () => void }) =>
                submit('delete', url, options),
            reset: (key: keyof T) =>
                setData((previous) => ({ ...previous, [key]: initial[key] })),
        };
    },
}));

const queue: EditorialQueue = {
    id: 'q1',
    name: 'News',
    category: 'Announcements',
    priority: 90,
    state: 'active',
    revision: 1,
    update_url: '/verified/queue/update',
    state_url: '/verified/queue/state',
    enqueue_url: '/verified/queue/enqueue',
};
const props: SchedulingProps = {
    workspaceId: 'company-a',
    workspaceName: 'Company A',
    canManage: true,
    queues: [
        queue,
        { ...queue, id: 'q2', name: 'Tips', category: 'Education' },
    ],
    series: [],
    entries: [],
    occurrences: [],
    posts: [{ id: 'draft1', label: 'Launch announcement', status: 'draft' }],
    urls: {
        series: '/verified/series',
        queues: '/verified/queues',
        fill: '/verified/fill',
        slots: '/verified/slots',
    },
};

beforeEach(() => {
    calls.requests.length = 0;
});
afterEach(cleanup);

it('submits timezone-aware series with nullable limits and the active company identity', () => {
    const cancel = vi.fn();
    render(
        <SeriesForm
            workspaceId="company-a"
            url="/verified/series"
            queues={[queue]}
            posts={props.posts}
            onCancel={cancel}
        />,
    );
    fireEvent.change(screen.getByLabelText('Series name'), {
        target: { value: 'Tuesday tips' },
    });
    fireEvent.change(screen.getByLabelText('Source post'), {
        target: { value: 'draft1' },
    });
    fireEvent.change(screen.getByLabelText('Timezone'), {
        target: { value: 'America/New_York' },
    });
    fireEvent.change(screen.getByLabelText('Priority queue after review'), {
        target: { value: 'q1' },
    });
    fireEvent.submit(
        screen.getByRole('form', { name: 'Create recurring series' }),
    );
    expect(calls.requests).toHaveLength(1);
    expect(calls.requests[0]).toMatchObject({
        method: 'post',
        url: '/verified/series',
        data: {
            expected_workspace_id: 'company-a',
            name: 'Tuesday tips',
            source_post_id: 'draft1',
            timezone: 'America/New_York',
            editorial_queue_id: 'q1',
            max_occurrences: null,
            ends_on: null,
        },
    });
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    act(() => calls.complete());
    expect(cancel).toHaveBeenCalledOnce();
});

it('updates queue name category and priority with its optimistic revision', () => {
    render(
        <QueueForm
            workspaceId="company-a"
            url={queue.update_url}
            queue={queue}
            onCancel={() => {}}
        />,
    );
    fireEvent.change(screen.getByLabelText('Category'), {
        target: { value: 'Product launches' },
    });
    fireEvent.change(
        screen.getByLabelText('Queue priority (0–100, higher first)'),
        { target: { value: '80' } },
    );
    fireEvent.submit(screen.getByRole('form', { name: 'Edit queue' }));
    expect(calls.requests[0]).toMatchObject({
        method: 'put',
        url: queue.update_url,
        data: {
            expected_revision: 1,
            expected_workspace_id: 'company-a',
            category: 'Product launches',
            priority: 80,
        },
    });
});

it('requires a separate stop confirmation and cancels without a request', () => {
    render(
        <WorkflowControls
            workspaceId="company-a"
            state="active"
            revision={3}
            url="/verified/state"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^Stop$/ }));
    expect(calls.requests).toHaveLength(0);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel stop' }));
    expect(
        screen.queryByRole('button', { name: 'Confirm stop' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^Stop$/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm stop' }));
    expect(calls.requests).toEqual([
        {
            method: 'post',
            url: '/verified/state',
            data: {
                expected_workspace_id: 'company-a',
                expected_revision: 3,
                state: 'stopped',
            },
        },
    ]);
    expect(screen.getByRole('button', { name: 'Confirm stop' })).toBeDisabled();
});

it('supports pause hold and resume without stale form state', () => {
    const { rerender } = render(
        <WorkflowControls
            workspaceId="company-a"
            state="active"
            revision={1}
            url="/verified/state"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
    expect(calls.requests[0].data).toMatchObject({ state: 'paused' });
    act(() => calls.complete());
    rerender(
        <WorkflowControls
            key="paused"
            workspaceId="company-a"
            state="paused"
            revision={2}
            url="/verified/state"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Hold' }));
    expect(calls.requests[1].data).toMatchObject({
        state: 'held',
        expected_revision: 2,
    });
    act(() => calls.complete());
    fireEvent.click(screen.getByRole('button', { name: 'Resume' }));
    expect(calls.requests[2].data).toMatchObject({ state: 'active' });
});

it('cancels new forms and clears unsaved edits when switching companies', () => {
    const { rerender } = render(<SchedulingIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'New series' }));
    fireEvent.change(screen.getByLabelText('Series name'), {
        target: { value: 'Private draft' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^Cancel$/ }));
    expect(screen.queryByLabelText('Series name')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'New series' }));
    expect(screen.getByLabelText('Series name')).toHaveValue('');
    rerender(
        <SchedulingIndex
            {...props}
            workspaceId="company-b"
            workspaceName="Company B"
            queues={[]}
        />,
    );
    expect(screen.queryByLabelText('Series name')).not.toBeInTheDocument();
    expect(screen.getByText('Company B')).toBeInTheDocument();
    expect(calls.requests).toHaveLength(0);
});

it('filters categories and submits the chosen draft to its named queue', () => {
    render(<SchedulingIndex {...props} />);
    fireEvent.change(screen.getByLabelText('Filter category'), {
        target: { value: 'Announcements' },
    });
    expect(screen.getByRole('heading', { name: 'News' })).toBeInTheDocument();
    expect(
        screen.queryByRole('heading', { name: 'Tips' }),
    ).not.toBeInTheDocument();
    const form = screen.getByRole('form', { name: 'Add draft to News' });
    fireEvent.change(within(form).getByLabelText('Draft'), {
        target: { value: 'draft1' },
    });
    fireEvent.submit(form);
    expect(calls.requests[0]).toMatchObject({
        url: queue.enqueue_url,
        data: {
            post_id: 'draft1',
            expected_workspace_id: 'company-a',
            priority: 50,
        },
    });
});

it('shows scheduling data but hides all management controls from ordinary members', () => {
    render(<SchedulingIndex {...props} canManage={false} />);
    expect(screen.getByRole('heading', { name: 'News' })).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'New series' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Fill next open slots' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Add draft' }),
    ).not.toBeInTheDocument();
});

/** @vitest-environment jsdom */

import { HttpNetworkError, HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
import { act, createElement, useEffect } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    composerReducer,
    initialComposerState,
    type ComposerState,
} from '@/lib/compose/composer-state';
import type { PostView } from '@/types/compose';

import { AUTOSAVE_DEBOUNCE_MS, useAutosave } from '../use-autosave';

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
}));

vi.mock('@/actions/App/Http/Controllers/Posts/PostController', () => ({
    default: {
        store: () => ({ url: '/posts' }),
        update: (id: string) => ({ url: `/posts/${id}` }),
    },
}));

const post: PostView = {
    id: 'post-1',
    base_text: 'Hello',
    segments: ['Hello'],
    status: 'draft',
    published_at: null,
    updated_at: '2026-07-17T10:00:00+00:00',
    scheduled_at: null,
    auto_repost: null,
    destination: { kind: 'all', id: null },
    targets: [],
    media: [],
};

const transform = vi.fn();
const httpPost = vi.fn();
const httpPut = vi.fn();

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let flushRef: (() => Promise<boolean>) | null = null;
let ensurePostRef: (() => Promise<string>) | null = null;
let serverPostRef: (() => PostView | null) | null = null;
let adoptServerPostRef: ((post: PostView) => void) | null = null;
const dispatch = vi.fn();

function draftState(overrides: Partial<ComposerState> = {}): ComposerState {
    return {
        ...initialComposerState(),
        saveState: 'dirty',
        segments: ['Hello'],
        ...overrides,
    };
}

function Harness({
    state,
    onSaved,
}: {
    state: ComposerState;
    onSaved: () => void;
}) {
    const { flush, ensurePost, getServerPost, adoptServerPost } = useAutosave({
        state,
        accountIds: [],
        dispatch,
        onSaved,
    });
    useEffect(() => {
        flushRef = flush;
        ensurePostRef = ensurePost;
        serverPostRef = getServerPost;
        adoptServerPostRef = adoptServerPost;
    }, [flush, ensurePost, getServerPost, adoptServerPost]);

    return null;
}

beforeEach(() => {
    transform.mockReset();
    dispatch.mockReset();
    httpPost.mockReset().mockResolvedValue({ post });
    httpPut.mockReset().mockImplementation((_url, opts) => {
        opts?.onSuccess?.({ post });

        return Promise.resolve();
    });
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post: httpPost,
        put: httpPut,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    flushRef = null;
    ensurePostRef = null;
    serverPostRef = null;
    adoptServerPostRef = null;
    vi.clearAllMocks();
});

describe('autosave failure handling', () => {
    it('makes the acknowledged post immediately available for revision-bound review requests', async () => {
        await act(async () => {
            root?.render(
                createElement(Harness, {
                    state: draftState(),
                    onSaved: vi.fn(),
                }),
            );
        });
        await act(async () => {
            expect(await flushRef?.()).toBe(true);
            expect(serverPostRef?.()).toEqual(post);
        });
    });

    it('adopts review responses into the synchronous server snapshot and composer baseline', async () => {
        await act(async () => {
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: post.id, saveState: 'saved' }),
                    onSaved: vi.fn(),
                }),
            );
        });
        const reviewed = { ...post, updated_at: '2026-10-02T10:00:00Z' };
        act(() => adoptServerPostRef?.(reviewed));
        expect(serverPostRef?.()).toEqual(reviewed);
        expect(dispatch).toHaveBeenCalledWith({
            type: 'syncServerPost',
            post: reviewed,
        });
    });
    it('waits for edits made during the forced save before allowing publishing to continue', async () => {
        const finishSaves: (() => void)[] = [];
        const payloads: {
            segments: string[];
            expected_updated_at: string | null;
        }[] = [];
        httpPut.mockImplementation((_url, opts) => {
            payloads.push(transform.mock.calls.at(-1)?.[0]());

            return new Promise<void>((resolve) => {
                const updatedAt = `2026-07-17T10:00:0${finishSaves.length + 1}+00:00`;
                finishSaves.push(() => {
                    opts.onSuccess({
                        post: { ...post, updated_at: updatedAt },
                    });
                    resolve();
                });
            });
        });
        const onSaved = vi.fn();
        const publish = vi.fn();
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        baselineUpdatedAt: post.updated_at,
                    }),
                    onSaved,
                }),
            ),
        );
        let submission: Promise<void> | undefined;
        await act(async () => {
            submission = flushRef?.().then((saved) => {
                if (saved) {
                    publish();
                }
            });
        });
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        baselineUpdatedAt: post.updated_at,
                        segments: ['Typed while saving'],
                    }),
                    onSaved,
                }),
            ),
        );
        await act(async () => {
            finishSaves[0]();
        });

        expect(httpPut).toHaveBeenCalledTimes(2);
        expect(publish).not.toHaveBeenCalled();
        expect(payloads[1]).toMatchObject({
            segments: ['Typed while saving'],
            expected_updated_at: '2026-07-17T10:00:01+00:00',
        });

        // Another edit during the follow-up request must be saved too.
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        baselineUpdatedAt: post.updated_at,
                        segments: ['Latest revision'],
                    }),
                    onSaved,
                }),
            ),
        );
        await act(async () => {
            finishSaves[1]();
        });
        expect(httpPut).toHaveBeenCalledTimes(3);
        expect(publish).not.toHaveBeenCalled();
        expect(payloads[2].segments).toEqual(['Latest revision']);

        await act(async () => {
            finishSaves[2]();
            await submission;
        });
        expect(publish).toHaveBeenCalledOnce();
    });

    it('does not allow publishing when the save of late edits fails', async () => {
        let finishFirstSave: (() => void) | undefined;
        httpPut
            .mockImplementationOnce(
                (_url, opts) =>
                    new Promise<void>((resolve) => {
                        finishFirstSave = () => {
                            opts.onSuccess({ post });
                            resolve();
                        };
                    }),
            )
            .mockRejectedValueOnce(new HttpNetworkError('Offline'));
        const onSaved = vi.fn();
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: post.id }),
                    onSaved,
                }),
            ),
        );
        let submission: Promise<boolean> | undefined;
        await act(async () => {
            submission = flushRef?.();
        });
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        segments: ['Late edit'],
                    }),
                    onSaved,
                }),
            ),
        );
        await act(async () => {
            finishFirstSave?.();
            expect(await submission).toBe(false);
        });

        expect(httpPut).toHaveBeenCalledTimes(2);
        expect(dispatch).toHaveBeenCalledWith({ type: 'saveFailedOffline' });
    });

    it.each([422, 500])(
        'reports a failed %s save and does not confirm persistence',
        async (status) => {
            httpPut.mockRejectedValue(
                new HttpResponseError('Save failed', {
                    status,
                    data: JSON.stringify({
                        errors: { segments: 'Invalid content' },
                    }),
                    headers: {},
                }),
            );
            const onSaved = vi.fn();
            act(() =>
                root?.render(
                    createElement(Harness, {
                        state: draftState({ postId: post.id }),
                        onSaved,
                    }),
                ),
            );

            await act(async () => {
                expect(await flushRef?.()).toBe(false);
            });

            expect(dispatch).toHaveBeenCalledWith({ type: 'saveFailed' });
            expect(onSaved).not.toHaveBeenCalled();
        },
    );

    it('preserves a server conflict and prevents another save until it is resolved', async () => {
        httpPut.mockRejectedValue(
            new HttpResponseError('Conflict', {
                status: 409,
                data: JSON.stringify({ post }),
                headers: {},
            }),
        );
        const onSaved = vi.fn();
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: post.id }),
                    onSaved,
                }),
            ),
        );

        await act(async () => expect(await flushRef?.()).toBe(false));
        expect(dispatch).toHaveBeenCalledWith({
            type: 'saveFailedStale',
            post,
        });

        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        saveState: 'conflict',
                        conflict: post,
                    }),
                    onSaved,
                }),
            ),
        );
        await act(async () => expect(await flushRef?.()).toBe(false));
        expect(httpPut).toHaveBeenCalledOnce();
    });

    it('reports a network failure as unsaved and allows a later retry', async () => {
        httpPut.mockRejectedValueOnce(new HttpNetworkError('Offline'));
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: post.id }),
                    onSaved: vi.fn(),
                }),
            ),
        );

        await act(async () => expect(await flushRef?.()).toBe(false));
        expect(dispatch).toHaveBeenCalledWith({ type: 'saveFailedOffline' });
        await act(async () => expect(await flushRef?.()).toBe(true));
        expect(httpPut).toHaveBeenCalledTimes(2);
    });

    it('does not return a draft id when creation fails', async () => {
        httpPost.mockRejectedValue(new Error('Save failed'));
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState(),
                    onSaved: vi.fn(),
                }),
            ),
        );

        await act(async () => expect(await ensurePostRef?.()).toBe(''));
        expect(dispatch).toHaveBeenCalledWith({ type: 'saveFailed' });
    });

    it('uses the latest edits when flushing behind an in-flight save', async () => {
        let finishFirstSave: (() => void) | undefined;
        httpPut.mockImplementationOnce(
            (_url, opts) =>
                new Promise<void>((resolve) => {
                    finishFirstSave = () => {
                        opts.onSuccess({ post });
                        resolve();
                    };
                }),
        );
        const onSaved = vi.fn();
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: post.id }),
                    onSaved,
                }),
            ),
        );
        let firstSave: Promise<boolean> | undefined;
        let queuedSave: Promise<boolean> | undefined;
        await act(async () => {
            firstSave = flushRef?.();
            await Promise.resolve();
            queuedSave = flushRef?.();
        });
        act(() =>
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: post.id,
                        segments: ['Latest content'],
                    }),
                    onSaved,
                }),
            ),
        );
        await act(async () => {
            finishFirstSave?.();
            await Promise.all([firstSave, queuedSave]);
        });

        expect(httpPut).toHaveBeenCalledTimes(2);
        expect(transform.mock.calls.at(-1)?.[0]().segments).toEqual([
            'Latest content',
        ]);
    });
});

describe('autosave debounce', () => {
    it('waits 500ms after draft edits before saving', () => {
        expect(AUTOSAVE_DEBOUNCE_MS).toBe(500);
    });
});

describe('useAutosave onSaved', () => {
    it('fires after a successful create (POST)', async () => {
        const onSaved = vi.fn();
        act(() => {
            root?.render(
                createElement(Harness, {
                    state: draftState({ postId: null }),
                    onSaved,
                }),
            );
        });

        await act(async () => {
            await flushRef?.();
        });

        expect(httpPost).toHaveBeenCalledOnce();
        expect(onSaved).toHaveBeenCalledOnce();
    });

    it('fires after a successful update (PUT)', async () => {
        const onSaved = vi.fn();
        act(() => {
            root?.render(
                createElement(Harness, {
                    state: draftState({
                        postId: 'post-1',
                        baselineUpdatedAt: post.updated_at,
                    }),
                    onSaved,
                }),
            );
        });

        await act(async () => {
            await flushRef?.();
        });

        expect(httpPut).toHaveBeenCalledOnce();
        expect(onSaved).toHaveBeenCalledOnce();
    });
});

describe('autosave debounce reset on placement-only changes', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('resets the timer for a placements-only edit mid-window, so the latest placements are saved (not the stale ones)', async () => {
        const onSaved = vi.fn();
        const baseState = draftState({
            postId: 'post-1',
            baselineUpdatedAt: post.updated_at,
        });

        act(() => {
            root?.render(createElement(Harness, { state: baseState, onSaved }));
        });

        // Partway through the original debounce window — not enough to fire.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).not.toHaveBeenCalled();

        // A placements-only edit lands mid-window (e.g. dragging media between
        // segments). saveState stays 'dirty'; nothing else in the currently
        // tracked deps changes — only `placements` does.
        const movedState = composerReducer(baseState, {
            type: 'moveMediaToSegment',
            mediaId: 'media-99',
            segmentRef: '__head__',
        });
        expect(movedState.placements).not.toBe(baseState.placements);
        expect(movedState.saveState).toBe('dirty');

        act(() => {
            root?.render(
                createElement(Harness, { state: movedState, onSaved }),
            );
        });

        // Past the ORIGINAL deadline (250ms + 250ms = 500ms elapsed). If the
        // timer was correctly reset by the placement change, nothing has
        // saved yet — the original timer must have been cleared.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).not.toHaveBeenCalled();

        // Past the NEW deadline (250ms mid-window + a fresh 500ms window from
        // the reset).
        await act(async () => {
            await vi.advanceTimersByTimeAsync(250);
        });
        expect(httpPut).toHaveBeenCalledOnce();

        // The saved payload must reflect the latest placements, not the stale
        // snapshot captured when the original timer was armed.
        const lastTransformCall = transform.mock.calls.at(-1) as
            | [() => { placements: unknown }]
            | undefined;
        const body = lastTransformCall?.[0]();
        expect(body?.placements).toContainEqual({
            media_id: 'media-99',
            segment_ref: '__head__',
            position: 0,
        });
    });
});

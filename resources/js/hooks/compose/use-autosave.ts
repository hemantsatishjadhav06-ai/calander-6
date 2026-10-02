import { HttpNetworkError, HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import PostController from '@/actions/App/Http/Controllers/Posts/PostController';
import {
    buildPutBody,
    type ComposerAction,
    composerHasContent,
    type ComposerState,
    flattenPlacements,
} from '@/lib/compose/composer-state';
import type { PostView } from '@/types/compose';

export const AUTOSAVE_DEBOUNCE_MS = 500;

type SaveResponse = { post: PostView };

function contentSignature(state: ComposerState, accountIds: string[]): string {
    return JSON.stringify({
        ...buildPutBody(state, accountIds),
        // The server version changes on every successful save; only editor
        // content and destination changes require another request.
        expected_updated_at: null,
    });
}

type UseAutosave = {
    state: ComposerState;
    accountIds: string[];
    dispatch: (action: ComposerAction) => void;
    /**
     * Called after each successful create/update so the host page can refresh
     * anything derived from the saved draft (e.g. the dashboard's recent-posts
     * feed, which is a deferred prop and otherwise stays stale until reload).
     */
    onSaved?: () => void;
    initialPost?: PostView | null;
    onServerPost?: (post: PostView) => void;
};

/**
 * Lazy POST→PUT autosave. Returns a `flush` callback to force an immediate save
 * (called on blur, visibility change, destination change, and submit).
 *
 * useHttp verbs take NO inline data — the request body is the hook's data. We
 * inject the dynamic payload via `transform()`, which runs at submit time (so it
 * always reflects the latest reducer state, avoiding React state-timing bugs).
 */
export function useAutosave({
    state,
    accountIds,
    dispatch,
    onSaved,
    initialPost = null,
    onServerPost,
}: UseAutosave) {
    // TForm must satisfy FormDataType; the hook's own data is unused (we submit
    // via transform), so Record<string, never> is the minimal valid shape.
    const http = useHttp<Record<string, never>, SaveResponse>({});
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    // The save currently in flight, tracked as a promise (not a boolean) so a
    // forced `flush` can await it before starting the next save — this is what
    // lets publishing wait for the draft (media, targets) to be persisted first.
    const inFlight = useRef<Promise<boolean> | null>(null);
    const stateRef = useRef(state);
    stateRef.current = state;
    const accountIdsRef = useRef(accountIds);
    accountIdsRef.current = accountIds;
    const savedContentRef = useRef<{
        postId: string;
        signature: string;
    } | null>(null);
    // Latest known post id, mirrored in a ref so a concurrent `ensurePost` can
    // read it after awaiting an in-flight create (the reducer `state` closure is
    // stale inside an async call).
    const postIdRef = useRef<string | null>(state.postId);
    // Latest server-acknowledged updated_at, mirrored in a ref so a save queued
    // behind an in-flight one sends the freshest `expected_updated_at` rather
    // than a stale React-closure snapshot — otherwise a single user's own
    // chained saves trip the optimistic-concurrency check and 409 against
    // themselves. Updated synchronously on every server response below, and via
    // the effect for external changes (hydrate / conflict resolution).
    const baselineRef = useRef<string | null>(state.baselineUpdatedAt);
    const serverPostRef = useRef<PostView | null>(initialPost);

    function rememberServerPost(post: PostView): void {
        serverPostRef.current = post;
        baselineRef.current = post.updated_at;
        onServerPost?.(post);
    }

    function adoptServerPost(post: PostView): void {
        rememberServerPost(post);
        dispatch({ type: 'syncServerPost', post });
    }

    /**
     * Create the draft post (POST). Shared by the autosave create-branch and by
     * `ensurePost`, which must create a draft even when the user hasn't typed
     * (e.g. a media-first upload). Returns the new post id.
     */
    async function createPost(): Promise<string> {
        const current = stateRef.current;
        const signature = contentSignature(current, accountIdsRef.current);
        http.transform(() => ({
            segments: current.segments,
            mentions: current.mentions,
            destination: current.destination,
            auto_repost: current.autoRepost,
            // Persist the thread structure and per-segment placements on the
            // very first save too, so a reload before the next autosave PUT
            // sees a consistent post (stale break ids would otherwise degrade
            // to positional fallbacks and misplace media onto the first post).
            segment_breaks: current.segmentBreaks,
            placements: flattenPlacements(current.placements),
        }));
        const created = await http.post(PostController.store().url);
        rememberServerPost(created.post);
        postIdRef.current = created.post.id;
        baselineRef.current = created.post.updated_at;
        savedContentRef.current = { postId: created.post.id, signature };
        dispatch({
            type: 'setPostId',
            postId: created.post.id,
            updatedAt: created.post.updated_at,
        });
        dispatch({ type: 'saveSucceeded', post: created.post });
        onSaved?.();

        return created.post.id;
    }

    /**
     * Persist the current reducer snapshot. No guards — callers coordinate via
     * `inFlight`. A brand-new post is created (POST); thereafter edits go via
     * PUT. `transform()` reads `state` at submit time so it captures the freshest
     * snapshot, including media added moments before a publish.
     */
    async function persist(): Promise<void> {
        dispatch({ type: 'saveStarted' });

        const postId = postIdRef.current;
        if (postId === null) {
            await createPost();

            return;
        }

        // expected_updated_at comes from the ref (latest server version), not the
        // possibly-stale closure, so a save queued behind another never 409s.
        const current = stateRef.current;
        const signature = contentSignature(current, accountIdsRef.current);
        http.transform(() => ({
            ...buildPutBody(current, accountIdsRef.current),
            expected_updated_at: baselineRef.current,
        }));
        await http.put(PostController.update(postId).url, {
            // onSuccess's first arg is the parsed response body (TResponse).
            onSuccess: (data) => {
                rememberServerPost(data.post);
                baselineRef.current = data.post.updated_at;
                dispatch({ type: 'saveSucceeded', post: data.post });
                onSaved?.();
            },
        });
        savedContentRef.current = { postId, signature };
    }

    function reportSaveFailure(error: unknown): void {
        savedContentRef.current = null;
        if (
            error instanceof HttpResponseError &&
            error.response.status === 409
        ) {
            try {
                const raw = error.response.data;
                const body = (
                    typeof raw === 'string' ? JSON.parse(raw) : raw
                ) as SaveResponse;
                if (body?.post?.id) {
                    dispatch({ type: 'saveFailedStale', post: body.post });

                    return;
                }
            } catch {
                // An invalid conflict response is still a failed save.
            }
        }
        dispatch({
            type:
                error instanceof HttpNetworkError
                    ? 'saveFailedOffline'
                    : 'saveFailed',
        });
    }

    /**
     * Run `work` serialized behind any in-flight save, tracking it on `inFlight`
     * so the debounce, `flush`, and `ensurePost` wait rather than overlap. The
     * tracked promise resolves false on failure so dependent actions never
     * publish a stale draft. Background saves do not produce unhandled rejections.
     */
    function enqueueSave(work: () => Promise<unknown>): Promise<boolean> {
        const prior = inFlight.current ?? Promise.resolve();
        const tracked: Promise<boolean> = prior
            .then(work, work)
            .then(
                () => true,
                (error: unknown) => {
                    reportSaveFailure(error);

                    return false;
                },
            )
            .finally(() => {
                if (inFlight.current === tracked) {
                    inFlight.current = null;
                }
            });
        inFlight.current = tracked;

        return tracked;
    }

    /**
     * Guarantee a persisted post id before a dependent action (e.g. media
     * upload). If a draft already exists, returns its id immediately; otherwise
     * creates one (serialized behind any in-flight save), regardless of
     * saveState.
     */
    async function ensurePost(): Promise<string> {
        if (postIdRef.current !== null) {
            return postIdRef.current;
        }
        if (inFlight.current) {
            const saved = await inFlight.current;

            return saved ? (postIdRef.current ?? '') : '';
        }
        dispatch({ type: 'saveStarted' });
        const saved = await enqueueSave(createPost);

        return saved ? (postIdRef.current ?? '') : '';
    }

    /** Debounced autosave: only when dirty and nothing already in flight. */
    async function save() {
        if (inFlight.current || state.saveState !== 'dirty') {
            return;
        }
        // An empty composer with no draft yet has nothing worth a POST — a
        // destination change alone must not create a blank draft. Media-first
        // uploads still create one via `ensurePost` (media counts as content).
        if (postIdRef.current === null && !composerHasContent(state)) {
            dispatch({ type: 'saveSkippedEmpty' });

            return;
        }
        await enqueueSave(persist);
    }

    /**
     * Force an immediate, awaitable save. Resolves only once the latest edits
     * are durably persisted, so callers (e.g. publishing) can rely on media and
     * targets being saved server-side before the publish request fires.
     */
    async function flush(): Promise<boolean> {
        for (;;) {
            if (timer.current) {
                clearTimeout(timer.current);
                timer.current = null;
            }
            // Other flushes and the debounce can share this save. Recheck after
            // every request so typing during the await is persisted before a
            // publish continuation can make the post read-only.
            if (inFlight.current) {
                if (!(await inFlight.current)) {
                    return false;
                }
                continue;
            }
            const current = stateRef.current;
            if (current.saveState === 'conflict') {
                return false;
            }
            const savedContent = savedContentRef.current;
            if (
                savedContent?.postId === postIdRef.current &&
                savedContent.signature ===
                    contentSignature(current, accountIdsRef.current)
            ) {
                return true;
            }
            if (current.saveState === 'saved' || current.saveState === 'idle') {
                return true;
            }
            // Destination changes alone must not create a blank draft.
            if (postIdRef.current === null && !composerHasContent(current)) {
                dispatch({ type: 'saveSkippedEmpty' });

                return true;
            }

            if (!(await enqueueSave(persist))) {
                return false;
            }
        }
    }

    // Debounce while dirty. `save` is intentionally re-created each render so the
    // timer fires with the latest state; deps list the fields that should reset
    // the timer. If oxlint flags react-hooks deps here, add an
    // `// oxlint-disable-next-line react-hooks/exhaustive-deps` directive — the
    // omission is deliberate.
    useEffect(() => {
        if (state.saveState !== 'dirty') {
            return;
        }
        if (timer.current) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => void save(), AUTOSAVE_DEBOUNCE_MS);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [
        state.saveState,
        state.segments,
        state.mentions,
        state.destination,
        state.overrideByAccount,
        state.autoSplitByAccount,
        state.formatByAccount,
        state.media,
        state.autoRepost,
        state.placements,
        state.placementsByAccount,
        state.segmentBreaks,
    ]);

    // Keep the version ref in step with externally-driven baseline changes
    // (initial hydrate, conflict resolution) that don't flow through a save here.
    useEffect(() => {
        baselineRef.current = state.baselineUpdatedAt;
    }, [state.baselineUpdatedAt]);

    useEffect(() => {
        postIdRef.current = state.postId;
    }, [state.postId]);

    useEffect(() => {
        if (initialPost) {
            serverPostRef.current = initialPost;
        }
    }, [initialPost]);

    // Flush on tab-hide.
    useEffect(() => {
        function onHide() {
            if (document.visibilityState === 'hidden') {
                void flush();
            }
        }
        document.addEventListener('visibilitychange', onHide);

        return () => document.removeEventListener('visibilitychange', onHide);
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [state]);

    return {
        flush,
        ensurePost,
        getServerPost: () => serverPostRef.current,
        adoptServerPost,
        processing: http.processing,
    };
}

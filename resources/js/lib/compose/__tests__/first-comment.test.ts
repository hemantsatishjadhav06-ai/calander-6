import { describe, expect, it } from 'vitest';

import type { PostView } from '@/types/compose';

import {
    buildPutBody,
    composerReducer,
    contentMatchesServer,
    initialComposerState,
} from '../composer-state';
import { shouldPollPostStatus } from '../publish-status';

function post(): PostView {
    return {
        id: 'post',
        base_text: 'Hello',
        segments: ['Hello'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-09-30T00:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'account', id: 'account' },
        media: [],
        targets: [
            {
                id: 'target',
                connected_account_id: 'account',
                platform: 'instagram',
                handle: '@brand',
                display_name: null,
                avatar_url: null,
                sections: ['Hello'],
                content_override: null,
                auto_split: true,
                format: 'feed',
                issues: [],
                status: 'pending',
                error_kind: null,
                error_message: null,
                attempts: 0,
                remote_id: null,
            },
        ],
    };
}

describe('first-comment configuration', () => {
    it('requires explicit opt-in while preserving suggestion text', () => {
        const fixture = post();
        fixture.first_comment = '#brand';
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: fixture,
        });
        expect(state.firstCommentEnabled).toBe(false);
        expect(buildPutBody(state, ['account']).first_comment).toBe('#brand');
    });

    it('saves per-account opt-outs and custom text separately from thread sections', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: post(),
        });
        state = composerReducer(state, {
            type: 'setFirstComment',
            enabled: true,
            text: '#default',
        });
        state = composerReducer(state, {
            type: 'setTargetFirstComment',
            accountId: 'account',
            enabled: false,
            text: '#custom',
        });
        const body = buildPutBody(state, ['account']);
        expect(body.first_comment_enabled).toBe(true);
        expect(body.targets[0].first_comment_enabled).toBe(false);
        expect(body.targets[0].first_comment).toBe('#custom');
        expect(body.segments).toEqual(['Hello']);
    });

    it('clears an account override back to inheritance on the wire', () => {
        let state = composerReducer(initialComposerState(), {
            type: 'setTargetFirstComment',
            accountId: 'account',
            enabled: true,
            text: '#custom',
        });
        state = composerReducer(state, {
            type: 'setTargetFirstComment',
            accountId: 'account',
            enabled: null,
            text: null,
        });
        const body = buildPutBody(state, ['account']);
        expect(body.targets[0].first_comment_enabled).toBeNull();
        expect(body.targets[0].first_comment).toBeNull();
    });

    it('recognizes first-comment divergence during optimistic write conflicts', () => {
        const fixture = post();
        const state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: fixture,
        });
        expect(contentMatchesServer(state, fixture)).toBe(true);
        expect(
            contentMatchesServer(
                { ...state, firstComment: 'Changed' },
                fixture,
            ),
        ).toBe(false);
        expect(
            contentMatchesServer(
                { ...state, firstCommentEnabled: true },
                fixture,
            ),
        ).toBe(false);
        expect(
            contentMatchesServer(
                {
                    ...state,
                    firstCommentByAccount: {
                        account: { enabled: false, text: null },
                    },
                },
                fixture,
            ),
        ).toBe(false);
    });

    it('preserves local first-comment changes when a save response arrives', () => {
        const fixture = post();
        let state = composerReducer(initialComposerState(), {
            type: 'hydrate',
            post: fixture,
        });
        state = composerReducer(state, { type: 'saveStarted' });
        state = composerReducer(state, {
            type: 'setFirstComment',
            enabled: true,
            text: 'Typed during save',
        });
        state = composerReducer(state, {
            type: 'saveSucceeded',
            post: fixture,
        });
        expect(state.firstComment).toBe('Typed during save');
        expect(state.saveState).toBe('dirty');
    });

    it('polls published targets only while a first-comment attempt is still pending', () => {
        const fixture = post();
        fixture.status = 'published';
        fixture.targets[0].status = 'published';
        fixture.targets[0].first_comment_delivery = {
            enabled: true,
            supported: true,
            text: 'Details',
            reason: null,
            max_length: 2200,
            status: 'pending',
            attempts: 0,
            error_message: null,
            remote_id: null,
            sent_at: null,
            next_attempt_at: null,
            retry_url: '/test-retry',
        };
        expect(shouldPollPostStatus(fixture)).toBe(true);
        fixture.targets[0].first_comment_delivery.status = 'uncertain';
        expect(shouldPollPostStatus(fixture)).toBe(false);
        fixture.targets[0].first_comment_delivery.status = 'sent';
        expect(shouldPollPostStatus(fixture)).toBe(false);
    });
});

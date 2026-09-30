import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { initialComposerState } from '@/lib/compose/composer-state';
import type { Account } from '@/types/compose';

import { FirstCommentEditor } from '../first-comment-editor';

function account(platform: Account['platform']): Account {
    return {
        id: platform,
        platform,
        handle: `@${platform}`,
        display_name: null,
        avatar_url: null,
        max_text_length: 2200,
        x_premium: false,
    };
}

describe('first-comment editor', () => {
    it('starts opted out and explicitly labels separate comment semantics', () => {
        const dispatch = vi.fn();
        render(
            <FirstCommentEditor
                state={initialComposerState()}
                dispatch={dispatch}
                accounts={[account('instagram')]}
            />,
        );
        expect(
            screen.getByRole('checkbox', { hidden: true }),
        ).not.toBeChecked();
        expect(
            screen.getByText(/Comment order is not guaranteed/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('checkbox', { hidden: true }));
        expect(dispatch).toHaveBeenCalledWith({
            type: 'setFirstComment',
            enabled: true,
            text: '',
        });
    });

    it('disables unsupported platforms and Story formats', () => {
        const state = initialComposerState();
        state.formatByAccount.instagram = 'story';
        render(
            <FirstCommentEditor
                state={state}
                dispatch={vi.fn()}
                accounts={[
                    account('linkedin'),
                    account('bluesky'),
                    account('discord'),
                    account('instagram'),
                ]}
            />,
        );
        for (const platform of [
            'linkedin',
            'bluesky',
            'discord',
            'instagram',
        ]) {
            expect(
                screen.getByLabelText(`First comment for @${platform}`),
            ).toBeDisabled();
        }
    });

    it('supports account-specific opt-out without changing the post default', () => {
        const dispatch = vi.fn();
        render(
            <FirstCommentEditor
                state={{
                    ...initialComposerState(),
                    firstCommentEnabled: true,
                    firstComment: 'Default',
                }}
                dispatch={dispatch}
                accounts={[account('instagram')]}
            />,
        );
        fireEvent.change(
            screen.getByLabelText('First comment for @instagram'),
            { target: { value: 'off' } },
        );
        expect(dispatch).toHaveBeenCalledWith({
            type: 'setTargetFirstComment',
            accountId: 'instagram',
            enabled: false,
            text: null,
        });
    });
});

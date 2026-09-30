import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ReviewFirstComment } from '../review-first-comment';

describe('review first-comment snapshot', () => {
    it('shows the exact effective text being approved', () => {
        render(
            <ReviewFirstComment
                comment={{
                    enabled: true,
                    supported: true,
                    text: 'Account-specific details #brand',
                    reason: null,
                }}
            />,
        );
        expect(
            screen.getByText('First comment: enabled after publication'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Account-specific details #brand'),
        ).toBeInTheDocument();
    });
    it('distinguishes a disabled suggestion from content that will be sent', () => {
        render(
            <ReviewFirstComment
                comment={{
                    enabled: false,
                    supported: true,
                    text: 'Suggested details',
                    reason: null,
                }}
            />,
        );
        expect(
            screen.getByText('First comment: off (will not be sent)'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Saved suggestion: Suggested details'),
        ).toBeInTheDocument();
    });
    it('shows unsupported delivery before approving the revision', () => {
        render(
            <ReviewFirstComment
                comment={{
                    enabled: true,
                    supported: false,
                    text: 'Details',
                    reason: 'Stories are unsupported.',
                }}
            />,
        );
        expect(
            screen.getByText('First comment: unavailable (will be skipped)'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Stories are unsupported.'),
        ).toBeInTheDocument();
    });
});

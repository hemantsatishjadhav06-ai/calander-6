import { describe, expect, it } from 'vitest';

import { formatSaveLabel } from '@/components/compose/save-indicator';
import {
    composerReducer,
    initialComposerState,
} from '@/lib/compose/composer-state';

describe('stale company draft feedback', () => {
    it('keeps the unsaved text and reports that it was not saved', () => {
        const state = {
            ...initialComposerState(),
            segments: ['Original company copy'],
        };
        const failed = composerReducer(state, { type: 'saveFailedWorkspace' });
        expect(failed.segments).toEqual(['Original company copy']);
        expect(failed.postId).toBeNull();
        expect(formatSaveLabel(failed.saveState, null)).toBe(
            'Company changed — not saved',
        );
    });
});

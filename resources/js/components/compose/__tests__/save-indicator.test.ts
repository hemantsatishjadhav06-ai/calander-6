import { describe, expect, it } from 'vitest';

import { formatSaveLabel } from '../save-indicator';

describe('save failure labels', () => {
    it('clearly identifies unsaved offline edits without claiming a persistent local copy', () => {
        expect(formatSaveLabel('offline', null)).toBe(
            'Offline — unsaved changes',
        );
    });

    it('makes server save failures actionable', () => {
        expect(formatSaveLabel('error', null)).toBe('Save failed — retry');
    });
});

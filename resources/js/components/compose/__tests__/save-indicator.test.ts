/** @vitest-environment jsdom */

import { act } from '@testing-library/react';
import { createElement } from 'react';
import { hydrateRoot, type Root } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import SaveIndicator, { formatSaveLabel } from '../save-indicator';

let root: Root | null = null;
let container: HTMLDivElement | null = null;

afterEach(() => {
    act(() => root?.unmount());
    container?.remove();
    root = null;
    container = null;
    vi.restoreAllMocks();
});

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

describe('saved status hydration', () => {
    it.each([
        { age: 4_000, delay: 2_000, label: 'Saved 6s ago' },
        { age: 59_000, delay: 2_000, label: 'Saved 1m ago' },
        { age: 120_000, delay: 60_000, label: 'Saved 3m ago' },
    ])(
        'hydrates across a clock change from $age ms without replacing the server markup',
        async ({ age, delay, label }) => {
            const serverNow = 1_800_000_000_000;
            const clock = vi.spyOn(Date, 'now').mockReturnValue(serverNow);
            const indicator = createElement(SaveIndicator, {
                state: 'saved',
                lastSavedAt: serverNow - age,
            });

            container = document.createElement('div');
            container.innerHTML = renderToString(indicator);
            document.body.append(container);
            const serverLabel = container.textContent;
            const serverStatus = container.firstElementChild;
            const onRecoverableError = vi.fn();

            clock.mockReturnValue(serverNow + delay);
            await act(async () => {
                root = hydrateRoot(container!, indicator, {
                    onRecoverableError,
                });
            });

            expect(onRecoverableError).not.toHaveBeenCalled();
            expect(serverLabel).toBe('Saved');
            expect(container.firstElementChild).toBe(serverStatus);
            expect(container.textContent).toBe(label);
        },
    );
});

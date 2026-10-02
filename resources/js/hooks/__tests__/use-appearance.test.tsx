import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeTheme, useAppearance } from '../use-appearance';

const getItem = vi.fn();
const setItem = vi.fn();

beforeEach(() => {
    getItem.mockReset().mockReturnValue(null);
    setItem.mockReset();
    vi.stubGlobal('localStorage', { getItem, setItem });
    vi.stubGlobal('matchMedia', () => ({
        matches: false,
        addEventListener: vi.fn(),
    }));
    document.documentElement.classList.remove('dark');
});

afterEach(() => vi.unstubAllGlobals());

describe('appearance when browser storage is unavailable', () => {
    it('initializes without blocking the app when storage access is denied', () => {
        getItem.mockImplementation(() => {
            throw new DOMException('Blocked', 'SecurityError');
        });
        setItem.mockImplementation(() => {
            throw new DOMException('Blocked', 'SecurityError');
        });

        expect(() => initializeTheme()).not.toThrow();
        expect(document.documentElement.style.colorScheme).toBe('light');
    });

    it('still updates the visible theme and hook state when persistence fails', () => {
        initializeTheme();
        setItem.mockImplementation(() => {
            throw new DOMException('Quota exceeded', 'QuotaExceededError');
        });
        const { result } = renderHook(() => useAppearance());

        act(() => result.current.updateAppearance('dark'));

        expect(result.current.appearance).toBe('dark');
        expect(document.documentElement).toHaveClass('dark');
        expect(document.cookie).toContain('appearance=dark');
    });

    it('uses system appearance for an invalid saved preference', () => {
        getItem.mockReturnValue('unexpected');
        initializeTheme();
        const { result } = renderHook(() => useAppearance());

        expect(result.current.appearance).toBe('system');
        expect(setItem).toHaveBeenCalledWith('appearance', 'system');
    });
});

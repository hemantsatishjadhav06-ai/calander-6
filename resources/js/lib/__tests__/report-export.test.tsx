import { afterEach, expect, it, vi } from 'vitest';

import { downloadReport } from '@/lib/report-export';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

it.each([
    [false, 'text/csv'],
    [true, 'text/html'],
    [true, null],
])(
    'does not save failed or login responses as a csv (%s, %s)',
    async (ok, contentType) => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue({ ok, headers: { get: () => contentType } }),
        );
        await expect(downloadReport('/report', 'report.csv')).rejects.toThrow(
            'could not be downloaded',
        );
        expect(document.querySelector('a[download]')).toBeNull();
    },
);

it('downloads a successful csv and cleans up the object URL and temporary link', async () => {
    const blob = new Blob(['header\nvalue'], { type: 'text/csv' });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            headers: { get: () => 'text/csv; charset=UTF-8' },
            blob: () => Promise.resolve(blob),
        }),
    );
    const create = vi.fn().mockReturnValue('blob:csv');
    const revoke = vi.fn();
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
    const click = vi
        .spyOn(HTMLAnchorElement.prototype, 'click')
        .mockImplementation(() => {});
    await downloadReport('/report?platform=x', 'report.csv');
    expect(fetch).toHaveBeenCalledWith('/report?platform=x', {
        credentials: 'same-origin',
        headers: { Accept: 'text/csv' },
    });
    expect(create).toHaveBeenCalledWith(blob);
    expect(click).toHaveBeenCalledTimes(1);
    expect(revoke).toHaveBeenCalledWith('blob:csv');
    expect(document.querySelector('a[download]')).toBeNull();
});

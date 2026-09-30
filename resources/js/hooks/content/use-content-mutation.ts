import { useEffect, useRef, useState } from 'react';

import { xsrfHeader } from '@/lib/csrf';

export function useContentMutation() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const controller = useRef<AbortController | null>(null);
    useEffect(() => () => controller.current?.abort(), []);

    async function run<T>(
        url: string,
        method: 'POST' | 'PUT',
        data: Record<string, unknown> | FormData,
    ): Promise<T | undefined> {
        if (pending.current) return undefined;
        pending.current = true;
        setBusy(true);
        setError('');
        const current = new AbortController();
        controller.current = current;
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                signal: current.signal,
                headers: {
                    Accept: 'application/json',
                    ...xsrfHeader(),
                    ...(data instanceof FormData
                        ? {}
                        : { 'Content-Type': 'application/json' }),
                },
                body: data instanceof FormData ? data : JSON.stringify(data),
            });
            if (
                response.status === 401 ||
                response.status === 419 ||
                response.redirected
            ) {
                throw new Error(
                    'Your session expired. Reload this page and sign in again before saving.',
                );
            }
            const result = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!response.ok) {
                throw new Error(
                    Object.values(result.errors ?? {})
                        .flat()
                        .join(' ') ||
                        result.message ||
                        'This change could not be saved. Please try again.',
                );
            }
            if (current.signal.aborted) return undefined;
            return result as T;
        } catch (cause) {
            if (!current.signal.aborted)
                setError(
                    cause instanceof SyntaxError
                        ? 'The server returned an unexpected response. Reload this page and try again.'
                        : cause instanceof Error
                          ? cause.message
                          : 'The request failed. Check your connection and try again.',
                );
            return undefined;
        } finally {
            pending.current = false;
            if (!current.signal.aborted) setBusy(false);
        }
    }
    return { busy, error, run };
}

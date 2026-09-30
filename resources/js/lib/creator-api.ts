import { xsrfHeader } from '@/lib/csrf';

export class CreatorApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'CreatorApiError';
    }
}

/** Same-origin requests use the application's existing session and CSRF cookie. */
export async function creatorRequest<T>(
    url: string,
    options: {
        method?: 'GET' | 'POST' | 'PUT';
        body?: unknown;
        signal?: AbortSignal;
    } = {},
): Promise<T> {
    const target = new URL(url, window.location.origin);
    if (target.origin !== window.location.origin)
        throw new CreatorApiError(
            'Creator requests must stay in this application.',
            400,
        );
    const multipart = options.body instanceof FormData;
    const response = await fetch(target, {
        method: options.method ?? 'GET',
        credentials: 'same-origin',
        signal: options.signal,
        headers: {
            Accept: 'application/json',
            ...xsrfHeader(),
            ...(!multipart && options.body !== undefined
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
        body:
            options.body === undefined
                ? undefined
                : multipart
                  ? (options.body as FormData)
                  : JSON.stringify(options.body),
    });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok || payload === null) {
        const body = payload as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        const validation = body?.errors
            ? Object.values(body.errors).flat().slice(0, 3).join(' ')
            : '';
        const message =
            validation ||
            body?.message ||
            ([401, 419].includes(response.status) || payload === null
                ? 'Your session expired. Save any local work, then sign in again.'
                : 'Creator could not complete this request. Try again.');
        throw new CreatorApiError(message, response.status);
    }
    return payload as T;
}

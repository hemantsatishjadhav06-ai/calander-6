import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * Every page needs exactly one <h1>. Most of these pages had none: the shared
 * Heading component renders an <h2>, so screen-reader heading navigation had no
 * page title to land on and started inside the always-mounted command palette
 * dialog instead. The project's convention for pages whose visual design has no
 * room for a title is a visually hidden h1 (see settings/connections).
 */
const pagesNeedingH1 = [
    'posts/calendar/index.tsx',
    'posts/index.tsx',
    'accounts/index.tsx',
    'engagement/index.tsx',
    'messages/index.tsx',
    'settings/workspace/overview.tsx',
    'settings/workspace/members.tsx',
    'settings/workspace/api-keys.tsx',
    'settings/instance-polling.tsx',
];

const read = (file: string) =>
    readFileSync(resolve(process.cwd(), 'resources/js/pages', file), 'utf8');

describe('page headings', () => {
    it.each(pagesNeedingH1)('%s renders exactly one h1', (file) => {
        const source = read(file);
        const matches = source.match(/<h1[\s>]/g) ?? [];

        expect(matches).toHaveLength(1);
    });
});

describe('form control labelling', () => {
    it('names both notification channel checkboxes', () => {
        const source = read('settings/notifications.tsx');

        expect(source).toContain('In-app notifications for ${event.label}');
        expect(source).toContain('Email notifications for ${event.label}');
    });

    // The visible Label on this row is bound to the enable checkbox, so the
    // interval field needs its own accessible name.
    it('names the polling interval inputs', () => {
        const source = read('settings/instance-polling.tsx');

        expect(source).toContain('polling interval in minutes');
    });

    it('names the posts search field', () => {
        expect(read('posts/index.tsx')).toContain('aria-label="Search posts"');
    });
});

/**
 * The public product pages title themselves either inline or through
 * PageHero, which renders the page's h1 (and is the only shared component
 * that does), so each page must use exactly one of the two, once.
 */
const publicProductPages = [
    'public/home.tsx',
    'public/features.tsx',
    'public/how-it-works.tsx',
    'public/platforms.tsx',
    'public/developers.tsx',
    'public/security.tsx',
];

describe('public product page headings', () => {
    it('PageHero renders exactly one h1', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/public/marketing.tsx',
            ),
            'utf8',
        );

        expect(source.match(/<h1[\s>]/g) ?? []).toHaveLength(1);
    });

    it.each(publicProductPages)('%s renders exactly one h1', (file) => {
        const source = read(file);
        const inline = source.match(/<h1[\s>]/g) ?? [];
        const viaHero = source.match(/<PageHero[\s>]/g) ?? [];

        expect(inline.length + viaHero.length).toBe(1);
    });
});

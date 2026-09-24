import { describe, expect, it } from 'vitest';

import { appVersion, githubRepo, githubReleaseUrl } from '@/lib/version';

describe('app version badge', () => {
    it('exposes the app version injected at build time', () => {
        // Injected via Vite `define` from the release tag (or `git describe`
        // locally). Always a string; may be empty in build contexts with no
        // tag and no git.
        expect(typeof appVersion).toBe('string');
    });

    it('links the displayed version to the matching GitHub release', () => {
        expect(githubReleaseUrl).toBe(
            `https://github.com/${githubRepo}/releases/tag/${appVersion}`,
        );
    });

    // Regression: the badge used to hardcode the upstream project this was
    // forked from, so the version link sent our users to someone else's repo.
    it('does not link to the upstream project', () => {
        expect(githubReleaseUrl).not.toContain('coollabsio');
    });
});

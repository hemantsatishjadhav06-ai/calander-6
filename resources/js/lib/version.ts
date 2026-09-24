// Injected by Vite `define` at build time from the release git tag.
// See resolve-app-version.ts and vite.config.ts.
export const appVersion = __APP_VERSION__;

// Also injected at build time. Kept out of a hardcoded literal so the version
// badge links to this product's releases rather than the upstream project it
// was forked from.
export const githubRepo = __GITHUB_REPO__;
export const githubReleaseUrl = `https://github.com/${githubRepo}/releases/tag/${appVersion}`;

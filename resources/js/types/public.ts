/**
 * Operator details every public page receives from PublicPageController's
 * `siteProps()`. Empty strings mean "not configured" and are rendered as such,
 * never replaced with a plausible-looking default.
 */
export type PublicSiteProps = {
    appName: string;
    company: string;
    contactEmail: string;
    address: string;
    jurisdiction: string;
    effectiveDate: string;
    registrationsEnabled: boolean;
    repoUrl: string;
};

export type PlatformOption = { value: string; label: string };

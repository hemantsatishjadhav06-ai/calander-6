import LegalPage, {
    Bullets,
    ContactBlock,
    operatorName,
    Section,
    type LegalPageProps,
} from '@/components/public/legal-page';

/**
 * Describes what this codebase actually stores and calls out. Every item below
 * corresponds to a real table, a real third-party request, or a real cookie —
 * a policy that describes a different product is not a policy.
 *
 * It is a starting point written from the code, not legal advice: an operator
 * should have it reviewed before relying on it.
 */
export default function Privacy({
    appName,
    company,
    contactEmail,
    address,
    jurisdiction,
    effectiveDate,
    registrationsEnabled,
}: LegalPageProps) {
    return (
        <LegalPage
            appName={appName}
            company={company}
            effectiveDate={effectiveDate}
            registrationsEnabled={registrationsEnabled}
            title="Privacy Policy"
            summary={`This policy explains what ${appName} collects when you use it, why, who it is shared with, and how to get it deleted.`}
        >
            <Section title="Who is responsible">
                <p>
                    This instance of {appName} is operated by{' '}
                    {operatorName(company)}
                    {jurisdiction ? `, based in ${jurisdiction}` : ''}. They
                    decide what is collected here and are the people to contact
                    about it.
                </p>
                <ContactBlock
                    company={company}
                    contactEmail={contactEmail}
                    address={address}
                />
            </Section>

            <Section title="What we collect">
                <p>
                    <strong className="text-foreground">Your account.</strong>{' '}
                    Your name, email address, and a hash of your password (never
                    the password itself). If you sign in with Google, X or
                    LinkedIn we also store the provider&apos;s account
                    identifier and the display name, nickname and avatar URL it
                    returns. If you enable two-factor authentication or a
                    passkey, we store the secret or credential needed to verify
                    it.
                </p>
                <p>
                    <strong className="text-foreground">
                        Connected social accounts.
                    </strong>{' '}
                    For every account you connect, we store the access and
                    refresh tokens the platform issues, encrypted at rest, along
                    with the handle, display name, avatar and account
                    identifier. Tokens are used only to act on your instruction
                    and are refreshed before they expire.
                </p>
                <p>
                    <strong className="text-foreground">
                        What you create.
                    </strong>{' '}
                    Posts and drafts, their scheduled times, any images or video
                    you upload, account sets, workspaces and their members, and
                    API keys you generate (stored hashed — we show the full key
                    once and cannot recover it).
                </p>
                <p>
                    <strong className="text-foreground">
                        What comes back from platforms.
                    </strong>{' '}
                    Replies to your posts and direct messages sent to your
                    connected accounts, including the sender&apos;s handle and
                    message text; and post and account metrics such as likes,
                    comments, reposts, impressions and follower counts.
                </p>
                <p>
                    <strong className="text-foreground">Technical data.</strong>{' '}
                    Server logs, error reports, and — where the operator has
                    enabled it — counts of how often the instance calls each
                    platform&apos;s API.
                </p>
            </Section>

            <Section title="Why we collect it">
                <Bullets
                    items={[
                        'To run your account, keep you signed in, and let your workspace colleagues find you.',
                        'To publish the posts you schedule, on the accounts you connected, at the times you chose.',
                        'To show you replies, messages and metrics for what you published.',
                        'To notify you when something you scheduled fails, or an account needs reconnecting.',
                        'To keep the service working and secure — diagnosing errors, enforcing rate limits, and preventing abuse.',
                    ]}
                />
                <p>
                    We do not sell your data, use it to build advertising
                    profiles, or train models on it.
                </p>
            </Section>

            <Section title="Who it is shared with">
                <p>
                    Your content goes to the platforms you connect, because that
                    is the point of the product. When you publish, the post text
                    and any media are sent to X, Bluesky, LinkedIn, Instagram,
                    Facebook, Threads or Discord as you directed, and their own
                    privacy policies govern it from that moment.
                </p>
                <p>
                    Beyond that, this instance may use service providers chosen
                    by its operator: a hosting and database provider, an email
                    provider for verification, password-reset and notification
                    mail, an object-storage provider for uploaded media, an
                    error-monitoring service, a GIF catalogue if the GIF picker
                    is enabled, and a payment processor if the operator charges
                    for the service. These providers process data on the
                    operator&apos;s behalf, not for their own purposes.
                </p>
                <p>
                    We disclose data to anyone else only where the law requires
                    it.
                </p>
            </Section>

            <Section title="Cookies">
                <p>
                    {appName} sets a session cookie to keep you signed in, a
                    CSRF-token cookie to protect form submissions, and a small
                    preference cookie remembering whether your sidebar is
                    collapsed. If you tick &ldquo;remember me&rdquo;, a
                    long-lived cookie keeps you signed in between visits. There
                    are no advertising or cross-site tracking cookies.
                </p>
            </Section>

            <Section title="How long it is kept">
                <p>
                    Your account data is kept while your account exists. Posts,
                    media, replies, messages and metrics are kept until you
                    delete them or delete the workspace holding them. Deleting a
                    connected account removes its stored tokens immediately.
                    Server logs are kept for a limited period set by the
                    operator. Deleting your account removes your personal data
                    and the workspaces you own — see{' '}
                    <strong className="text-foreground">Your rights</strong>{' '}
                    below.
                </p>
            </Section>

            <Section title="Your rights">
                <p>
                    You can access and correct your account details from the
                    settings page at any time, disconnect any social account,
                    delete individual posts and workspaces, and delete your
                    account entirely. Depending on where you live you may also
                    have the right to a copy of your data, to restrict or object
                    to its processing, and to complain to a data-protection
                    authority.
                </p>
                <p>
                    The steps for deleting your data are set out on the{' '}
                    <a
                        href="/data-deletion"
                        className="text-primary underline underline-offset-4"
                    >
                        data deletion
                    </a>{' '}
                    page.
                </p>
            </Section>

            <Section title="Children">
                <p>
                    {appName} is not intended for anyone under 16, and we do not
                    knowingly collect their data. If you believe a child has an
                    account here, contact the operator and it will be removed.
                </p>
            </Section>

            <Section title="Changes to this policy">
                <p>
                    If this policy changes materially, the effective date at the
                    top will change and the operator will notify account holders
                    by email where that is possible.
                </p>
            </Section>
        </LegalPage>
    );
}

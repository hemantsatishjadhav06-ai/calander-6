import LegalPage, {
    Bullets,
    ContactBlock,
    operatorName,
    Section,
    type LegalPageProps,
} from '@/components/public/legal-page';

/**
 * Meta requires a reachable data-deletion URL before approving an app, and
 * Google and X expect the same route to exist. It describes what the account
 * deletion in settings actually does, including the case where it refuses.
 */
export default function DataDeletion({
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
            title="Delete your data"
            summary={`You can remove your data from ${appName} yourself, at any time, without asking anyone. This page explains how, and exactly what each step removes.`}
        >
            <Section title="Disconnect a single social account">
                <p>
                    Go to <strong className="text-foreground">Accounts</strong>,
                    open the account you want to remove, and disconnect it. This
                    deletes the access and refresh tokens we hold for it
                    immediately, so {appName} can no longer read or publish
                    anything on that account.
                </p>
                <p>
                    Posts you already published stay on the platform —
                    disconnecting here does not delete anything from X, Bluesky,
                    LinkedIn, Instagram, Facebook, Threads or Discord. To remove
                    those, delete them on the platform itself.
                </p>
            </Section>

            <Section title="Delete a workspace">
                <p>
                    A workspace holds its own posts, media, connected accounts,
                    replies, messages, metrics and API keys. Deleting it from{' '}
                    <strong className="text-foreground">
                        Settings → Workspace
                    </strong>{' '}
                    removes all of it. Other workspaces are unaffected.
                </p>
            </Section>

            <Section title="Delete your whole account">
                <p>
                    Go to{' '}
                    <strong className="text-foreground">
                        Settings → Profile
                    </strong>
                    , scroll to the deletion section, and confirm with your
                    password. This removes:
                </p>
                <Bullets
                    items={[
                        'Your account: name, email, password hash, avatar, two-factor secrets and passkeys.',
                        'Any Google, X or LinkedIn sign-in identities linked to it.',
                        'Every workspace you solely own, and everything inside them — posts, drafts, uploaded media, connected accounts and their tokens, replies, direct messages, metrics, account sets and API keys.',
                        'Your membership of workspaces owned by other people.',
                    ]}
                />
                <p>
                    Deletion is immediate and cannot be undone. Export anything
                    you want to keep first.
                </p>
            </Section>

            <Section title="If deletion is refused">
                <p>
                    We will not delete a workspace out from under the people
                    working in it. If you are the only owner of a workspace that
                    still has other members, deletion stops and says so. Either
                    make someone else an owner of that workspace, or remove its
                    other members and delete the workspace, then delete your
                    account.
                </p>
                <p>
                    Content in workspaces owned by <em>other</em> people is not
                    yours to delete and stays with them. Ask that
                    workspace&apos;s owner to remove it.
                </p>
            </Section>

            <Section title="Asking us to do it for you">
                <p>
                    If you cannot sign in, or you would rather we handled it,
                    email the operator from the address on the account and ask
                    for deletion. They will confirm the request and act on it,
                    and will tell you when it is done.
                </p>
                <ContactBlock
                    company={company}
                    contactEmail={contactEmail}
                    address={address}
                />
                {jurisdiction && (
                    <p>
                        {operatorName(company)} is based in {jurisdiction}, and
                        local data-protection rules may give you further rights
                        and set the deadline for a response.
                    </p>
                )}
            </Section>

            <Section title="What may survive deletion, and for how long">
                <p>
                    Backups are rotated on a schedule, so a copy of deleted data
                    can persist in backup storage for a short period before it
                    ages out. Server and error logs may retain technical records
                    such as timestamps and request identifiers for a limited
                    period. Where the law requires records to be kept — billing
                    records, for example — those are retained for as long as it
                    requires and for nothing else.
                </p>
            </Section>
        </LegalPage>
    );
}

import LegalPage, {
    Bullets,
    ContactBlock,
    operatorName,
    Section,
    type LegalPageProps,
} from '@/components/public/legal-page';

/**
 * A starting point written from what this codebase does, not legal advice: an
 * operator should have it reviewed before relying on it.
 */
export default function Terms({
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
            title="Terms of Service"
            summary={`These terms cover your use of this instance of ${appName}. By creating an account or using the service, you agree to them.`}
        >
            <Section title="Who you are agreeing with">
                <p>
                    This instance is operated by {operatorName(company)}
                    {jurisdiction ? `, based in ${jurisdiction}` : ''}.
                    &ldquo;We&rdquo; and &ldquo;us&rdquo; below mean that
                    operator; &ldquo;you&rdquo; means the person or organisation
                    using the account.
                </p>
                <ContactBlock
                    company={company}
                    contactEmail={contactEmail}
                    address={address}
                />
            </Section>

            <Section title="Your account">
                <p>
                    You must be at least 16 and give accurate details. You are
                    responsible for what happens under your account, including
                    anything done by people you invite to your workspaces, so
                    keep your credentials safe and tell us promptly if you think
                    they have been compromised.
                </p>
                <p>
                    An instance owner can disable registration, remove accounts
                    and change instance-wide settings. If this instance is run
                    by your employer or client, their internal rules apply to
                    you as well as these terms.
                </p>
            </Section>

            <Section title="What the service does">
                <p>
                    {appName} publishes content to social platforms on your
                    instruction and reads back replies, messages and metrics
                    from them. It acts only when you tell it to — by publishing,
                    scheduling, or enabling a feature such as auto-reposting.
                </p>
            </Section>

            <Section title="Your content">
                <p>
                    You keep every right you already had in what you post. You
                    grant us only the permission needed to run the service:
                    storing your content, transmitting it to the platforms you
                    selected, and displaying it back to you and to the workspace
                    members you invited.
                </p>
                <p>
                    You are responsible for having the rights to what you
                    publish, including any images, video and third-party
                    material in it.
                </p>
            </Section>

            <Section title="Acceptable use">
                <p>You agree not to use {appName} to:</p>
                <Bullets
                    items={[
                        'Publish unlawful content, or content that infringes someone else’s rights.',
                        'Send spam, run coordinated inauthentic activity, or operate accounts you are not entitled to operate.',
                        'Break the terms of any connected platform — their rules apply to everything published through them.',
                        'Attack, overload, probe or reverse-engineer the service, or circumvent its rate limits and access controls.',
                        'Access another tenant’s workspaces, accounts or data.',
                    ]}
                />
                <p>
                    We may suspend or remove an account that breaks these rules,
                    and will say why where we can.
                </p>
            </Section>

            <Section title="Connected platforms">
                <p>
                    The social platforms you connect are independent services
                    with their own terms, rate limits and review processes. They
                    can change their APIs, restrict access, or suspend your
                    account there at any time, and a publish can fail for
                    reasons entirely outside our control. We are not responsible
                    for their decisions or their availability.
                </p>
            </Section>

            <Section title="Availability">
                <p>
                    We work to keep the service running, but it is provided as
                    it is, without a guarantee of uninterrupted or error-free
                    operation. Scheduled maintenance, upstream outages and
                    platform rate limits can delay or prevent a publish.
                </p>
            </Section>

            <Section title="Fees">
                <p>
                    Where this instance charges for the service, the price,
                    billing period and refund rules shown at checkout apply.
                    Unless stated otherwise there, a subscription renews until
                    you cancel, and cancelling stops future charges rather than
                    refunding past ones. Where the operator makes the instance
                    available free of charge, no fees apply.
                </p>
            </Section>

            <Section title="Ending it">
                <p>
                    You can delete your account at any time from your settings;
                    see the{' '}
                    <a
                        href="/data-deletion"
                        className="text-primary underline underline-offset-4"
                    >
                        data deletion
                    </a>{' '}
                    page for what that removes. We may close an account that
                    breaks these terms, or on reasonable notice if we stop
                    running this instance — in which case we will give you a
                    fair chance to get your content out first.
                </p>
            </Section>

            <Section title="Liability">
                <p>
                    To the extent the law allows, we are not liable for indirect
                    or consequential loss, lost profits, or lost data arising
                    from your use of the service, and our total liability is
                    limited to what you paid for it in the twelve months before
                    the claim. Nothing here limits liability that cannot legally
                    be limited.
                </p>
            </Section>

            <Section title="Changes">
                <p>
                    If these terms change materially, the effective date above
                    changes and we will notify account holders by email where
                    that is possible. Continuing to use the service after that
                    means you accept the new terms.
                </p>
            </Section>

            <Section title="Governing law">
                <p>
                    {jurisdiction
                        ? `These terms are governed by the laws of ${jurisdiction}, and its courts have jurisdiction over any dispute.`
                        : 'These terms are governed by the laws of the place where the operator of this instance is established, and its courts have jurisdiction over any dispute.'}
                </p>
            </Section>
        </LegalPage>
    );
}

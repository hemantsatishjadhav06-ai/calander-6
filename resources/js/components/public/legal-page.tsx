import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import PublicShell from '@/components/public/public-shell';

export type LegalPageProps = {
    appName: string;
    company: string;
    contactEmail: string;
    address: string;
    jurisdiction: string;
    effectiveDate: string;
    registrationsEnabled: boolean;
};

/**
 * How to name the operator in prose when none is configured. An unset
 * `INSTANCE_COMPANY_NAME` must not turn into "operated by SM Manager", which
 * says nothing and reads like a mistake.
 */
export function operatorName(company: string): string {
    return company || 'the operator of this instance';
}

export function Section({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="mt-10">
            <h2 className="font-[family-name:var(--font-display)] text-xl font-semibold tracking-tight text-foreground">
                {title}
            </h2>
            <div className="mt-3 space-y-3 text-sm leading-relaxed text-muted-foreground">
                {children}
            </div>
        </section>
    );
}

export function Bullets({ items }: { items: ReactNode[] }) {
    return (
        <ul className="ml-5 list-disc space-y-1.5 marker:text-muted-foreground/60">
            {items.map((item, index) => (
                <li key={index}>{item}</li>
            ))}
        </ul>
    );
}

/**
 * How to reach the operator of this instance.
 *
 * When no contact address is configured the page says so rather than printing
 * a placeholder: a policy that names an address nobody reads is worse than one
 * that admits the operator has not published one yet.
 */
export function ContactBlock({
    company,
    contactEmail,
    address,
}: {
    company: string;
    contactEmail: string;
    address: string;
}) {
    return (
        <div className="mt-3 rounded-lg border border-border/70 bg-card/60 p-4 text-sm text-muted-foreground">
            {company && (
                <p className="font-medium text-foreground">{company}</p>
            )}
            {contactEmail ? (
                <p className="mt-1">
                    <a
                        href={`mailto:${contactEmail}`}
                        className="text-primary underline underline-offset-4"
                    >
                        {contactEmail}
                    </a>
                </p>
            ) : (
                <p className="mt-1">
                    The operator of this instance has not published a contact
                    address yet.
                </p>
            )}
            {address && <p className="mt-1 whitespace-pre-line">{address}</p>}
        </div>
    );
}

export default function LegalPage({
    appName,
    company,
    effectiveDate,
    registrationsEnabled,
    title,
    summary,
    children,
}: {
    appName: string;
    company: string;
    effectiveDate: string;
    registrationsEnabled: boolean;
    title: string;
    summary: string;
    children: ReactNode;
}) {
    return (
        <PublicShell
            appName={appName}
            company={company}
            showRegister={registrationsEnabled}
        >
            <Head title={title} />

            <article className="mx-auto max-w-3xl px-5 py-14 sm:px-8 sm:py-20">
                <h1 className="font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                    {title}
                </h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Effective {effectiveDate}
                </p>
                <p className="mt-5 text-base leading-relaxed text-pretty text-muted-foreground">
                    {summary}
                </p>

                {children}
            </article>
        </PublicShell>
    );
}

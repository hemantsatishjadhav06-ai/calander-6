import { Form, Head, Link } from '@inertiajs/react';

import BrandController from '@/actions/App/Http/Controllers/Brands/BrandController';
import InputError from '@/components/common/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as accounts } from '@/routes/accounts';
import { index as analytics } from '@/routes/analytics';
import { index as blogs } from '@/routes/blogs';
import { index as engagement } from '@/routes/engagement';
import { index as messages } from '@/routes/messages';
import { show as showPost } from '@/routes/posts';

type Profile = {
    website_url: string;
    instagram_username: string | null;
    facebook_page_id: string | null;
    facebook_page_url: string | null;
    x_username: string | null;
    netlify_site_id: string | null;
    repository_url: string | null;
};

type Connection = {
    platform: string;
    expected: string | null;
    status:
        | 'connected'
        | 'needs_attention'
        | 'mismatch'
        | 'not_connected'
        | 'not_configured';
    handle: string | null;
    remote_account_id: string | null;
    metrics_captured_at: string | null;
};

const statusLabels: Record<Connection['status'], string> = {
    connected: 'Connected',
    needs_attention: 'Needs attention',
    mismatch: 'Matching account not connected',
    not_connected: 'Not connected',
    not_configured: 'Account details needed',
};

const fields: { name: keyof Profile; label: string; placeholder: string }[] = [
    {
        name: 'website_url',
        label: 'Website URL',
        placeholder: 'https://your-brand.com',
    },
    {
        name: 'instagram_username',
        label: 'Instagram username',
        placeholder: 'brand_name',
    },
    {
        name: 'facebook_page_id',
        label: 'Facebook Page ID',
        placeholder: '123456789',
    },
    {
        name: 'facebook_page_url',
        label: 'Facebook Page URL',
        placeholder: 'https://www.facebook.com/your-page',
    },
    { name: 'x_username', label: 'X username', placeholder: 'brand_name' },
    {
        name: 'netlify_site_id',
        label: 'Netlify Site ID',
        placeholder: 'Verified site identifier',
    },
    {
        name: 'repository_url',
        label: 'Website repository URL',
        placeholder: 'https://github.com/owner/website',
    },
];

export default function BrandSetup({
    profile,
    connections,
    canManage,
    approvalRequired,
    draftPlan,
}: {
    profile: Profile | null;
    connections: Connection[];
    canManage: boolean;
    approvalRequired: boolean;
    draftPlan: { id: string; text: string; planned_at: string }[];
}) {
    return (
        <>
            <Head title="Brand setup" />
            <div className="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-6">
                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold">Brand setup</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage this brand’s website and account connections,
                        then review its drafts before publication.
                    </p>
                    <Badge variant="outline">
                        {approvalRequired
                            ? 'Owner approval required'
                            : 'Approval enabled when brand details are saved'}
                    </Badge>
                </div>

                {canManage && (
                    <div className="space-y-3 rounded-xl border p-5">
                        <h2 className="font-medium">Neopolis and More Space</h2>
                        <p className="text-sm text-muted-foreground">
                            Prepare separate workspaces with your confirmed
                            websites, Instagram usernames and Facebook Page IDs.
                            More Space’s X account still needs creation. Each
                            workspace requires your approval before publishing.
                            Private starter posts and a blog draft are included,
                            with proposed posting dates for the next seven days.
                        </p>
                        <Form {...BrandController.bootstrap.form()}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Preparing…'
                                        : 'Prepare both brands and drafts'}
                                </Button>
                            )}
                        </Form>
                    </div>
                )}

                <section
                    className="space-y-3"
                    aria-labelledby="connections-heading"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="connections-heading"
                            className="text-lg font-semibold"
                        >
                            Social connections
                        </h2>
                        <Link
                            href={accounts()}
                            className="text-sm underline underline-offset-4"
                        >
                            Manage account connections
                        </Link>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {connections.map((connection) => (
                            <div
                                key={connection.platform}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <h3 className="font-medium">
                                    {connection.platform === 'x'
                                        ? 'X / Twitter'
                                        : connection.platform === 'facebook'
                                          ? 'Facebook'
                                          : 'Instagram'}
                                </h3>
                                <p className="text-sm break-all">
                                    {connection.expected
                                        ? connection.platform === 'facebook'
                                            ? `Page ${connection.expected}`
                                            : `@${connection.expected}`
                                        : 'Add the account after creating it.'}
                                </p>
                                <Badge
                                    variant={
                                        connection.status === 'connected'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {statusLabels[connection.status]}
                                </Badge>
                                {connection.remote_account_id && (
                                    <p className="text-xs break-all text-muted-foreground">
                                        Connected account: {connection.handle} ·{' '}
                                        {connection.remote_account_id}
                                    </p>
                                )}
                                {connection.metrics_captured_at && (
                                    <p className="text-xs text-muted-foreground">
                                        Metrics last updated:{' '}
                                        {new Date(
                                            connection.metrics_captured_at,
                                        ).toLocaleString('en-IN', {
                                            timeZone: 'Asia/Kolkata',
                                        })}{' '}
                                        IST
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </section>

                {draftPlan.length > 0 && (
                    <section
                        className="space-y-4"
                        aria-labelledby="draft-plan-heading"
                    >
                        <h2
                            id="draft-plan-heading"
                            className="text-lg font-semibold"
                        >
                            Proposed posting plan
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            These are private drafts. Connect the destination
                            accounts, review the content and approve the
                            intended time before scheduling.
                        </p>
                        <div className="grid gap-3">
                            {draftPlan.map((draft) => (
                                <Link
                                    key={draft.id}
                                    href={showPost(draft.id)}
                                    className="grid gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/50 sm:grid-cols-[12rem_1fr]"
                                >
                                    <span className="text-sm font-medium">
                                        {new Date(
                                            draft.planned_at,
                                        ).toLocaleString('en-IN', {
                                            timeZone: 'Asia/Kolkata',
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        })}{' '}
                                        IST
                                    </span>
                                    <span className="line-clamp-2 min-w-0 text-sm break-words text-muted-foreground">
                                        {draft.text}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}

                <section
                    className="space-y-4 rounded-xl border p-5"
                    aria-labelledby="details-heading"
                >
                    <h2 id="details-heading" className="text-lg font-semibold">
                        Brand details
                    </h2>
                    <Form
                        {...BrandController.update.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <fieldset
                                disabled={!canManage || processing}
                                className="grid gap-5 md:grid-cols-2"
                            >
                                {fields.map((field) => (
                                    <div
                                        key={field.name}
                                        className="grid gap-2"
                                    >
                                        <Label htmlFor={field.name}>
                                            {field.label}
                                        </Label>
                                        <Input
                                            id={field.name}
                                            name={field.name}
                                            defaultValue={
                                                profile?.[field.name] ?? ''
                                            }
                                            placeholder={field.placeholder}
                                            required={
                                                field.name === 'website_url'
                                            }
                                        />
                                        <InputError
                                            message={errors[field.name]}
                                        />
                                    </div>
                                ))}
                                {canManage && (
                                    <div className="md:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Saving…'
                                                : 'Save brand details'}
                                        </Button>
                                    </div>
                                )}
                            </fieldset>
                        )}
                    </Form>
                </section>

                <div className="space-y-3 rounded-xl border p-5">
                    <h2 className="font-medium">Website drafts and results</h2>
                    <p className="text-sm text-muted-foreground">
                        Blog drafts stay private while you review them. Website
                        publication requires a verified site connection.
                    </p>
                    <div className="flex flex-wrap gap-x-5 gap-y-3 text-sm">
                        <Link
                            href={blogs()}
                            className="underline underline-offset-4"
                        >
                            Blog drafts
                        </Link>
                        <Link
                            href={engagement()}
                            className="underline underline-offset-4"
                        >
                            Comments
                        </Link>
                        <Link
                            href={messages()}
                            className="underline underline-offset-4"
                        >
                            Messages
                        </Link>
                        <Link
                            href={analytics()}
                            className="underline underline-offset-4"
                        >
                            Analytics
                        </Link>
                        {profile?.website_url && (
                            <a
                                href={profile.website_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline underline-offset-4"
                            >
                                Visit website
                            </a>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

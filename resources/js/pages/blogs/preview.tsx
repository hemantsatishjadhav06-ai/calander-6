import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

import {
    approve,
    publish,
    reject,
    requestReview,
} from '@/actions/App/Http/Controllers/Blogs/BlogDraftController';
import InputError from '@/components/common/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit, index } from '@/routes/blogs';
import { BLOG_REVIEW_LABELS } from '@/types/blogs';
import type { BlogContext, BlogDraft } from '@/types/blogs';

type Props = BlogContext & { blog: BlogDraft };

export default function BlogPreview({ blog, brand, publication }: Props) {
    const publicationActive =
        blog.publication_status === 'queued' ||
        blog.publication_status === 'publishing';
    const publishedCurrentVersion = blog.published_revision === blog.revision;
    const canPublish =
        publication.available &&
        blog.can_review &&
        blog.status === 'approved' &&
        !publicationActive &&
        !publishedCurrentVersion;
    const poll = usePoll(
        3000,
        { only: ['blog', 'publication'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (!publicationActive) {
            return;
        }

        poll.start();

        return () => poll.stop();
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- poll identity is stable per Inertia; publicationActive owns the polling lifecycle
    }, [publicationActive]);

    let publishedUrl: string | null = null;
    if (blog.published_url && brand.website_url) {
        try {
            const url = new URL(blog.published_url);
            const website = new URL(brand.website_url);
            if (
                url.protocol === 'https:' &&
                url.origin === website.origin &&
                !url.username &&
                !url.password
            ) {
                publishedUrl = url.href;
            }
        } catch {
            publishedUrl = null;
        }
    }

    return (
        <>
            <Head title={`Preview ${blog.title}`}>
                <meta name="robots" content="noindex,nofollow" />
            </Head>
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Private blog preview
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {brand.name} · Version {blog.content_revision}
                        </p>
                    </div>
                    {blog.publication_status !== 'publishing' && (
                        <Button
                            variant="outline"
                            nativeButton={false}
                            render={<Link href={edit(blog.id).url} />}
                        >
                            Edit draft
                        </Button>
                    )}
                </div>
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
                    <div className="min-w-0 space-y-6">
                        <article className="space-y-5 rounded-xl border bg-card p-5 sm:p-8">
                            <h2 className="text-2xl font-semibold break-words sm:text-3xl">
                                {blog.title}
                            </h2>
                            {blog.excerpt && (
                                <p className="text-lg break-words text-muted-foreground">
                                    {blog.excerpt}
                                </p>
                            )}
                            <div className="leading-relaxed break-words whitespace-pre-wrap">
                                {blog.body}
                            </div>
                            {blog.featured_image_url && (
                                <div className="space-y-1 border-t pt-4 text-sm">
                                    <h3 className="font-medium">
                                        Featured image
                                    </h3>
                                    <p className="break-all text-muted-foreground">
                                        {blog.featured_image_url}
                                    </p>
                                    {blog.featured_image_alt && (
                                        <p className="text-muted-foreground">
                                            {blog.featured_image_alt}
                                        </p>
                                    )}
                                </div>
                            )}
                        </article>
                        <section className="space-y-3 rounded-xl border p-5">
                            <h2 className="font-medium">Search preview</h2>
                            <p className="text-sm break-all text-muted-foreground">
                                {blog.canonical_url ??
                                    brand.website_url ??
                                    'Website not configured'}{' '}
                                · {blog.slug}
                            </p>
                            <p className="text-lg font-medium break-words">
                                {blog.seo_title || blog.title}
                            </p>
                            <p className="text-sm break-words text-muted-foreground">
                                {blog.seo_description ||
                                    blog.excerpt ||
                                    'Add a search description to this draft.'}
                            </p>
                        </section>
                    </div>
                    <aside className="space-y-5 rounded-xl border p-5">
                        <div className="space-y-3">
                            <h2 className="font-medium">Review</h2>
                            <Badge
                                variant={
                                    blog.status === 'approved'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {BLOG_REVIEW_LABELS[blog.status]}
                            </Badge>
                            <p className="text-sm text-muted-foreground">
                                Approval applies to this version and website
                                destination. Changes require another review.
                            </p>
                            {blog.approved_at && (
                                <p className="text-sm">
                                    Approved by the workspace owner.
                                </p>
                            )}
                            {blog.rejection_reason && (
                                <p className="rounded-lg bg-muted p-3 text-sm break-words">
                                    {blog.rejection_reason}
                                </p>
                            )}
                        </div>
                        {blog.can_review ? (
                            blog.status === 'awaiting_approval' ? (
                                <div className="space-y-4">
                                    <Form
                                        key={`approve-${blog.revision}`}
                                        {...approve.form(blog.id)}
                                    >
                                        {({ processing, errors }) => (
                                            <div className="space-y-2">
                                                <input
                                                    type="hidden"
                                                    name="revision"
                                                    value={blog.revision}
                                                />
                                                <InputError
                                                    message={errors.revision}
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="w-full"
                                                >
                                                    {processing
                                                        ? 'Approving…'
                                                        : 'Approve this version'}
                                                </Button>
                                            </div>
                                        )}
                                    </Form>
                                    <Form
                                        key={`reject-${blog.revision}`}
                                        {...reject.form(blog.id)}
                                    >
                                        {({ processing, errors }) => (
                                            <div className="space-y-2">
                                                <input
                                                    type="hidden"
                                                    name="revision"
                                                    value={blog.revision}
                                                />
                                                <Label htmlFor="blog-rejection">
                                                    Requested changes
                                                </Label>
                                                <Textarea
                                                    id="blog-rejection"
                                                    name="reason"
                                                    maxLength={2000}
                                                    rows={3}
                                                    disabled={processing}
                                                />
                                                <InputError
                                                    message={
                                                        errors.reason ??
                                                        errors.revision
                                                    }
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                    className="w-full"
                                                >
                                                    {processing
                                                        ? 'Saving…'
                                                        : 'Request changes'}
                                                </Button>
                                            </div>
                                        )}
                                    </Form>
                                </div>
                            ) : blog.status !== 'approved' ? (
                                <Form
                                    key={`request-${blog.revision}`}
                                    {...requestReview.form(blog.id)}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-2">
                                            <input
                                                type="hidden"
                                                name="revision"
                                                value={blog.revision}
                                            />
                                            <InputError
                                                message={errors.revision}
                                            />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="w-full"
                                            >
                                                {processing
                                                    ? 'Requesting…'
                                                    : 'Request review'}
                                            </Button>
                                        </div>
                                    )}
                                </Form>
                            ) : null
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                The workspace owner reviews and approves blog
                                drafts.
                            </p>
                        )}
                        <div className="space-y-3 border-t pt-4 text-sm text-muted-foreground">
                            <h3 className="font-medium text-foreground">
                                Website publishing
                            </h3>
                            <p>{publication.reason}</p>
                            {publicationActive ? (
                                <p role="status" className="text-foreground">
                                    {blog.publication_status === 'queued'
                                        ? 'Publishing queued. Waiting for the approved version to deploy.'
                                        : 'Publishing the approved version to your website…'}
                                </p>
                            ) : publishedCurrentVersion ? (
                                <p role="status" className="text-foreground">
                                    This approved version is live on your
                                    website.
                                </p>
                            ) : blog.published_revision ? (
                                <p>
                                    The previously published version remains
                                    live. This draft stays private until you
                                    approve and publish it.
                                </p>
                            ) : (
                                <p>
                                    Approval keeps this draft private. Only the
                                    workspace owner can publish an approved
                                    version.
                                </p>
                            )}
                            {(blog.publication_error ||
                                blog.publication_status === 'failed') && (
                                <p
                                    role="alert"
                                    className="rounded-lg bg-muted p-3 break-words text-foreground"
                                >
                                    {blog.publication_error ||
                                        'Website publishing failed. Review the connection and try again.'}
                                </p>
                            )}
                            {publishedUrl && (
                                <a
                                    href={publishedUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="block break-words text-foreground underline underline-offset-4"
                                >
                                    {publishedCurrentVersion
                                        ? 'View published article'
                                        : 'View previously published article'}
                                </a>
                            )}
                            {canPublish && (
                                <Form
                                    key={`publish-${blog.revision}`}
                                    {...publish.form(blog.id)}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-2">
                                            <input
                                                type="hidden"
                                                name="revision"
                                                value={blog.revision}
                                            />
                                            <InputError
                                                message={
                                                    errors.publication ??
                                                    errors.revision
                                                }
                                            />
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="w-full"
                                            >
                                                {processing
                                                    ? 'Queuing publication…'
                                                    : 'Publish approved version'}
                                            </Button>
                                        </div>
                                    )}
                                </Form>
                            )}
                        </div>
                        <Link
                            href={index().url}
                            className="block text-sm underline underline-offset-4"
                        >
                            Back to drafts
                        </Link>
                    </aside>
                </div>
            </div>
        </>
    );
}

BlogPreview.layout = {
    breadcrumbs: [{ title: 'Blog drafts', href: index().url }],
};

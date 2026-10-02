import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FileText, Plus } from '@/components/ui/icons';
import { create, edit, index, preview } from '@/routes/blogs';
import { BLOG_REVIEW_LABELS } from '@/types/blogs';
import type { BlogContext, BlogSummary } from '@/types/blogs';

type Props = BlogContext & {
    blogs: {
        data: BlogSummary[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

export default function BlogsIndex({ blogs, brand, publication }: Props) {
    return (
        <>
            <Head title="Blog drafts" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Blog drafts</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Private articles for {brand.name}. Review each
                            version before publication.
                        </p>
                    </div>
                    <Button
                        nativeButton={false}
                        render={<Link href={create().url} />}
                    >
                        <Plus className="size-4" /> New blog draft
                    </Button>
                </div>
                <div className="rounded-xl border bg-muted/30 p-4 text-sm text-muted-foreground">
                    {publication.reason}
                </div>
                {blogs.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <FileText className="size-8 text-muted-foreground" />
                        <h2 className="font-medium">
                            Prepare your first article
                        </h2>
                        <p className="max-w-md text-sm text-muted-foreground">
                            Save a title, article, and search preview. Drafts
                            stay private to this workspace.
                        </p>
                        <Link
                            className="text-sm font-medium underline underline-offset-4"
                            href={create().url}
                        >
                            Create a blog draft
                        </Link>
                    </div>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {blogs.data.map((blog) => (
                            <article
                                key={blog.id}
                                className="flex flex-wrap items-center justify-between gap-4 p-4"
                            >
                                <div className="min-w-0 flex-1 space-y-2">
                                    <Link
                                        href={edit(blog.id).url}
                                        className="block font-medium break-words hover:underline"
                                    >
                                        {blog.title}
                                    </Link>
                                    {blog.excerpt && (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                            {blog.excerpt}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <Badge
                                            variant={
                                                blog.status === 'approved'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {BLOG_REVIEW_LABELS[blog.status]}
                                        </Badge>
                                        <span>
                                            Version {blog.content_revision}
                                        </span>
                                        <span>Private draft</span>
                                    </div>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    nativeButton={false}
                                    render={
                                        <Link href={preview(blog.id).url} />
                                    }
                                >
                                    Preview and review
                                </Button>
                            </article>
                        ))}
                    </div>
                )}
                {blogs.last_page > 1 && (
                    <nav
                        aria-label="Blog pages"
                        className="flex items-center justify-between gap-3 text-sm"
                    >
                        {blogs.prev_page_url ? (
                            <Link
                                href={blogs.prev_page_url}
                                className="underline"
                            >
                                Previous
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span>
                            Page {blogs.current_page} of {blogs.last_page}
                        </span>
                        {blogs.next_page_url ? (
                            <Link
                                href={blogs.next_page_url}
                                className="underline"
                            >
                                Next
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

BlogsIndex.layout = {
    breadcrumbs: [{ title: 'Blog drafts', href: index().url }],
};

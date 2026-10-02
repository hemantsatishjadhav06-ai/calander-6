import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import InputError from '@/components/common/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { index, preview, store, update } from '@/routes/blogs';
import { BLOG_REVIEW_LABELS } from '@/types/blogs';
import type { BlogContext, BlogDraft } from '@/types/blogs';

type Props = BlogContext & { blog: BlogDraft | null };

function formData(blog: BlogDraft | null) {
    return {
        title: blog?.title ?? '',
        slug: blog?.slug ?? '',
        body: blog?.body ?? '',
        excerpt: blog?.excerpt ?? '',
        featured_image_url: blog?.featured_image_url ?? '',
        featured_image_alt: blog?.featured_image_alt ?? '',
        seo_title: blog?.seo_title ?? '',
        seo_description: blog?.seo_description ?? '',
        canonical_url: blog?.canonical_url ?? '',
        revision: blog?.revision ?? '',
    };
}

export default function BlogEdit({ blog, brand, publication }: Props) {
    const form = useForm(formData(blog));
    const { processing, errors, isDirty } = form;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const saved = page.props.blog as BlogDraft;
                const latest = formData(saved);
                form.setData(latest);
                form.setDefaults(latest);
            },
        };
        if (blog) {
            form.patch(update(blog.id).url, options);
        } else {
            form.post(store().url, options);
        }
    }

    function loadLatest() {
        const latest = formData(blog);
        form.setData(latest);
        form.setDefaults(latest);
        form.clearErrors();
    }

    return (
        <>
            <Head title={blog ? `Edit ${blog.title}` : 'New blog draft'} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {blog ? 'Edit blog draft' : 'New blog draft'}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {brand.name} · Private to this workspace
                        </p>
                    </div>
                    {blog && (
                        <Badge
                            variant={
                                blog.status === 'approved'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {BLOG_REVIEW_LABELS[blog.status]}
                        </Badge>
                    )}
                </div>
                <form onSubmit={submit} className="space-y-6">
                    {blog && (
                        <input
                            type="hidden"
                            name="revision"
                            value={form.data.revision}
                        />
                    )}
                    <InputError message={errors.revision} />
                    {errors.revision && (
                        <div className="space-y-2 rounded-lg border p-3">
                            <p className="text-sm text-muted-foreground">
                                Your unsaved text is preserved. Loading the
                                latest saved version replaces these edits.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={loadLatest}
                            >
                                Load latest saved version
                            </Button>
                        </div>
                    )}
                    <fieldset disabled={processing} className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="blog-title">Title</Label>
                            <Input
                                id="blog-title"
                                name="title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                maxLength={200}
                                required
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="blog-slug">URL slug</Label>
                            <Input
                                id="blog-slug"
                                name="slug"
                                value={form.data.slug}
                                onChange={(event) =>
                                    form.setData('slug', event.target.value)
                                }
                                placeholder="your-article-title"
                                maxLength={190}
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Lowercase letters, numbers, and hyphens.
                            </p>
                            <InputError message={errors.slug} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="blog-excerpt">Excerpt</Label>
                            <Textarea
                                id="blog-excerpt"
                                name="excerpt"
                                value={form.data.excerpt}
                                onChange={(event) =>
                                    form.setData('excerpt', event.target.value)
                                }
                                maxLength={2000}
                                rows={3}
                            />
                            <InputError message={errors.excerpt} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="blog-body">Article body</Label>
                            <Textarea
                                id="blog-body"
                                name="body"
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                maxLength={100000}
                                required
                                className="min-h-80"
                                rows={16}
                            />
                            <p className="text-xs text-muted-foreground">
                                Write the article in plain text, with blank
                                lines between paragraphs.
                            </p>
                            <InputError message={errors.body} />
                        </div>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="blog-image">
                                    Featured image URL
                                </Label>
                                <Input
                                    id="blog-image"
                                    name="featured_image_url"
                                    type="url"
                                    value={form.data.featured_image_url}
                                    onChange={(event) =>
                                        form.setData(
                                            'featured_image_url',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2048}
                                    placeholder="https://…"
                                />
                                <InputError
                                    message={errors.featured_image_url}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="blog-image-alt">
                                    Featured image description
                                </Label>
                                <Input
                                    id="blog-image-alt"
                                    name="featured_image_alt"
                                    value={form.data.featured_image_alt}
                                    onChange={(event) =>
                                        form.setData(
                                            'featured_image_alt',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={300}
                                />
                                <InputError
                                    message={errors.featured_image_alt}
                                />
                            </div>
                        </div>
                        <div className="grid gap-4 rounded-xl border p-4">
                            <h2 className="font-medium">Search preview</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="blog-seo-title">
                                    Search title
                                </Label>
                                <Input
                                    id="blog-seo-title"
                                    name="seo_title"
                                    value={form.data.seo_title}
                                    onChange={(event) =>
                                        form.setData(
                                            'seo_title',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={200}
                                />
                                <InputError message={errors.seo_title} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="blog-seo-description">
                                    Search description
                                </Label>
                                <Textarea
                                    id="blog-seo-description"
                                    name="seo_description"
                                    value={form.data.seo_description}
                                    onChange={(event) =>
                                        form.setData(
                                            'seo_description',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={500}
                                    rows={3}
                                />
                                <InputError message={errors.seo_description} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="blog-canonical">
                                    Canonical URL
                                </Label>
                                <Input
                                    id="blog-canonical"
                                    name="canonical_url"
                                    type="url"
                                    value={form.data.canonical_url}
                                    onChange={(event) =>
                                        form.setData(
                                            'canonical_url',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2048}
                                    placeholder="https://…"
                                />
                                <InputError message={errors.canonical_url} />
                            </div>
                        </div>
                    </fieldset>
                    <p className="text-sm text-muted-foreground">
                        Editing an approved article requires a new review.{' '}
                        {publication.reason}
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save private draft'}
                        </Button>
                        {blog && (
                            <Button
                                variant="outline"
                                nativeButton={false}
                                disabled={processing || isDirty}
                                render={<Link href={preview(blog.id).url} />}
                            >
                                Preview and review
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            nativeButton={false}
                            disabled={processing}
                            render={<Link href={index().url} />}
                        >
                            Back to drafts
                        </Button>
                    </div>
                    {isDirty && blog && (
                        <p className="text-xs text-muted-foreground">
                            Save your changes before opening the review preview.
                        </p>
                    )}
                </form>
            </div>
        </>
    );
}

BlogEdit.layout = {
    breadcrumbs: [{ title: 'Blog drafts', href: index().url }],
};

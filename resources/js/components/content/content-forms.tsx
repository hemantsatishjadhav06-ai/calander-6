import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useContentMutation } from '@/hooks/content/use-content-mutation';
import { update as updateBrand } from '@/routes/content/brand';
import {
    store as storeIdea,
    update as updateIdea,
} from '@/routes/content/ideas';
import {
    store as storeTemplate,
    update as updateTemplate,
} from '@/routes/content/templates';
import { store as storeAsset } from '@/routes/creator/assets';
import type {
    BrandProfile,
    ContentAsset,
    ContentIdea,
    ContentProject,
    ContentTemplate,
} from '@/types/content';

export function splitList(value: string): string[] {
    return [
        ...new Set(
            value
                .split(/[\s,]+/)
                .map((part) => part.trim())
                .filter(Boolean),
        ),
    ];
}
export function Field({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <Label className="grid gap-2 text-sm">
            {label}
            {children}
        </Label>
    );
}
export function Feedback({ error }: { error: string }) {
    return error ? (
        <p
            role="alert"
            className="rounded-xl bg-destructive/10 p-3 text-sm text-destructive"
        >
            {error}
        </p>
    ) : null;
}
export const selectStyle =
    'h-9 w-full rounded-xl border bg-background px-3 text-sm';

export function BrandForm({
    brand,
    logos,
    canManage,
    workspaceId,
}: {
    brand: BrandProfile;
    logos: ContentAsset[];
    canManage: boolean;
    workspaceId: string;
}) {
    const [form, setForm] = useState({
        ...brand,
        palette: brand.palette.join(', '),
        default_hashtags: brand.default_hashtags.join(' '),
    });
    const mutation = useContentMutation();
    const upload = useContentMutation();
    const patch = (field: string, value: string | boolean) =>
        setForm((previous) => ({ ...previous, [field]: value }));
    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
            <form
                className="grid gap-5 rounded-2xl border bg-card p-5"
                onSubmit={async (event) => {
                    event.preventDefault();
                    const result = await mutation.run(
                        updateBrand.url(),
                        'PUT',
                        {
                            ...form,
                            expected_revision: brand.revision,
                            expected_workspace_id: workspaceId,
                            palette: splitList(form.palette),
                            default_hashtags: splitList(form.default_hashtags),
                        },
                    );
                    if (result) {
                        toast.success('Changes saved');
                        router.reload();
                    }
                }}
            >
                <fieldset
                    disabled={!canManage || mutation.busy}
                    className="grid gap-5"
                >
                    <Field label="Tagline">
                        <Input
                            maxLength={300}
                            value={form.tagline ?? ''}
                            onChange={(event) =>
                                patch('tagline', event.target.value)
                            }
                        />
                    </Field>
                    <div className="grid gap-5 md:grid-cols-2">
                        <Field label="Audience">
                            <Textarea
                                rows={4}
                                maxLength={5000}
                                placeholder="Who do you help? What matters to them?"
                                value={form.audience ?? ''}
                                onChange={(event) =>
                                    patch('audience', event.target.value)
                                }
                            />
                        </Field>
                        <Field label="Brand voice">
                            <Textarea
                                rows={4}
                                maxLength={5000}
                                placeholder="Warm, assured, specific. Use short sentences."
                                value={form.voice ?? ''}
                                onChange={(event) =>
                                    patch('voice', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Guidelines and claims to avoid">
                        <Textarea
                            rows={5}
                            maxLength={10000}
                            value={form.guidelines ?? ''}
                            onChange={(event) =>
                                patch('guidelines', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Palette · comma-separated six-digit hex colors">
                        <Input
                            placeholder="#2563eb, #0f172a, #ffffff"
                            value={form.palette}
                            onChange={(event) =>
                                patch('palette', event.target.value)
                            }
                        />
                    </Field>
                    <div
                        className="flex flex-wrap gap-2"
                        aria-label="Palette preview"
                    >
                        {splitList(form.palette)
                            .filter((color) => /^#[a-f0-9]{6}$/i.test(color))
                            .map((color) => (
                                <span
                                    key={color}
                                    className="size-9 rounded-xl border"
                                    style={{ backgroundColor: color }}
                                    title={color}
                                />
                            ))}
                    </div>
                    <Field label="Saved brand logo">
                        <select
                            className={selectStyle}
                            value={form.logo_asset_id ?? ''}
                            onChange={(event) =>
                                patch('logo_asset_id', event.target.value)
                            }
                        >
                            <option value="">No logo selected</option>
                            {logos.map((logo) => (
                                <option key={logo.id} value={logo.id}>
                                    {logo.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Default hashtags">
                        <Input
                            placeholder="#YourBrand #YourTopic"
                            value={form.default_hashtags}
                            onChange={(event) =>
                                patch('default_hashtags', event.target.value)
                            }
                        />
                    </Field>
                    <Label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.first_comment_enabled}
                            onChange={(event) =>
                                patch(
                                    'first_comment_enabled',
                                    event.target.checked,
                                )
                            }
                        />
                        Prepare first-comment text
                    </Label>
                    <Field label="First-comment suggestion">
                        <Textarea
                            rows={3}
                            maxLength={5000}
                            value={form.first_comment ?? ''}
                            onChange={(event) =>
                                patch('first_comment', event.target.value)
                            }
                        />
                    </Field>
                    <p className="text-xs text-muted-foreground">
                        First-comment text is a preparation preference. It is
                        not automatically posted to social networks.
                    </p>
                    <Button
                        type="submit"
                        disabled={mutation.busy}
                        className="justify-self-start"
                    >
                        {mutation.busy ? 'Saving…' : 'Save brand profile'}
                    </Button>
                </fieldset>
                {!canManage && (
                    <p className="text-sm text-muted-foreground">
                        A workspace owner or admin can update this brand
                        profile.
                    </p>
                )}
                <Feedback error={mutation.error} />
            </form>
            <aside className="grid content-start gap-4 rounded-2xl border bg-card p-5">
                <h2 className="font-semibold">Brand assets</h2>
                {brand.logo && (
                    <img
                        src={brand.logo.content_url}
                        alt={brand.logo.name}
                        className="max-h-48 w-full rounded-xl border object-contain p-4"
                    />
                )}
                <p className="text-sm text-muted-foreground">
                    Logos stay in this workspace. Saved designs and templates
                    keep their original asset references.
                </p>
                {canManage && (
                    <Field
                        label={
                            upload.busy ? 'Uploading logo…' : 'Upload a logo'
                        }
                    >
                        <Input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            disabled={upload.busy}
                            onChange={async (event) => {
                                const file = event.target.files?.[0];
                                if (!file) return;
                                const data = new FormData();
                                data.set('file', file);
                                data.set('name', file.name);
                                data.set('kind', 'logo');
                                data.set('expected_workspace_id', workspaceId);
                                const result = await upload.run<{
                                    asset: ContentAsset;
                                }>(storeAsset.url(), 'POST', data);
                                if (result) {
                                    toast.success('Changes saved');
                                    router.reload();
                                }
                                event.target.value = '';
                            }}
                        />
                    </Field>
                )}
                <Feedback error={upload.error} />
            </aside>
        </div>
    );
}

export function TemplateForm({
    template,
    projects,
    workspaceId,
}: {
    template?: ContentTemplate;
    projects: ContentProject[];
    workspaceId: string;
}) {
    const [form, setForm] = useState({
        name: template?.name ?? '',
        description: template?.description ?? '',
        brief: template?.brief ?? '',
        caption: template?.caption ?? '',
        hashtags: template?.hashtags.join(' ') ?? '',
        first_comment: template?.first_comment ?? '',
        project: '__keep__',
        archived: Boolean(template?.archived_at),
    });
    const mutation = useContentMutation();
    const patch = (field: string, value: string | boolean) =>
        setForm((previous) => ({ ...previous, [field]: value }));
    return (
        <form
            className="grid gap-4"
            onSubmit={async (event) => {
                event.preventDefault();
                const selected = projects.find(
                    (project) => project.id === form.project,
                );
                const data = {
                    ...form,
                    hashtags: splitList(form.hashtags),
                    expected_revision: template?.revision,
                    expected_workspace_id: workspaceId,
                    ...(form.project === '__keep__'
                        ? {}
                        : {
                              source_project_id: selected?.id ?? null,
                              source_project_revision:
                                  selected?.revision ?? null,
                          }),
                };
                const result = await mutation.run(
                    template
                        ? updateTemplate.url(template.id)
                        : storeTemplate.url(),
                    template ? 'PUT' : 'POST',
                    data,
                );
                if (result) {
                    toast.success('Changes saved');
                    router.reload();
                }
            }}
        >
            <fieldset disabled={mutation.busy} className="grid gap-4">
                <Field label="Template name">
                    <Input
                        required
                        maxLength={200}
                        value={form.name}
                        onChange={(event) => patch('name', event.target.value)}
                    />
                </Field>
                <Field label="Description">
                    <Input
                        maxLength={5000}
                        value={form.description}
                        onChange={(event) =>
                            patch('description', event.target.value)
                        }
                    />
                </Field>
                <Field label="Reusable creative brief">
                    <Textarea
                        rows={4}
                        maxLength={10000}
                        value={form.brief}
                        onChange={(event) => patch('brief', event.target.value)}
                    />
                </Field>
                <Field label="Starter caption">
                    <Textarea
                        rows={4}
                        maxLength={20000}
                        value={form.caption}
                        onChange={(event) =>
                            patch('caption', event.target.value)
                        }
                    />
                </Field>
                <Field label="Hashtags">
                    <Input
                        value={form.hashtags}
                        onChange={(event) =>
                            patch('hashtags', event.target.value)
                        }
                    />
                </Field>
                <Field label="First-comment suggestion">
                    <Textarea
                        maxLength={5000}
                        value={form.first_comment}
                        onChange={(event) =>
                            patch('first_comment', event.target.value)
                        }
                    />
                </Field>
                <Field label="Design snapshot">
                    <select
                        className={selectStyle}
                        value={form.project}
                        onChange={(event) =>
                            patch('project', event.target.value)
                        }
                    >
                        <option value="__keep__">
                            {template?.has_design
                                ? 'Keep saved snapshot'
                                : 'Brief and caption only'}
                        </option>
                        <option value="">No design snapshot</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name} · revision {project.revision}
                            </option>
                        ))}
                    </select>
                </Field>
                <p className="text-xs text-muted-foreground">
                    Using a template makes an independent design copy. Editing
                    the original design will not change this snapshot.
                </p>
                {template && (
                    <Label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.archived}
                            onChange={(event) =>
                                patch('archived', event.target.checked)
                            }
                        />
                        Archive this template
                    </Label>
                )}
                <Button
                    type="submit"
                    className="justify-self-start"
                    disabled={mutation.busy}
                >
                    {mutation.busy
                        ? 'Saving…'
                        : template
                          ? 'Save template'
                          : 'Create template'}
                </Button>
            </fieldset>
            <Feedback error={mutation.error} />
        </form>
    );
}

export function IdeaForm({
    idea,
    templates,
    workspaceId,
}: {
    idea?: ContentIdea;
    templates: { id: string; name: string }[];
    workspaceId: string;
}) {
    const [form, setForm] = useState({
        title: idea?.title ?? '',
        brief: idea?.brief ?? '',
        caption: idea?.caption ?? '',
        category: idea?.category ?? '',
        tags: idea?.tags.join(', ') ?? '',
        status:
            idea?.status === 'drafted' ? 'inbox' : (idea?.status ?? 'inbox'),
        due_on: idea?.due_on ?? '',
        template_id: idea?.template_id ?? '',
    });
    const mutation = useContentMutation();
    const patch = (field: string, value: string) =>
        setForm((previous) => ({ ...previous, [field]: value }));
    return (
        <form
            className="grid gap-4"
            onSubmit={async (event) => {
                event.preventDefault();
                const result = await mutation.run(
                    idea ? updateIdea.url(idea.id) : storeIdea.url(),
                    idea ? 'PUT' : 'POST',
                    {
                        ...form,
                        expected_revision: idea?.revision,
                        expected_workspace_id: workspaceId,
                        tags: form.tags
                            .split(',')
                            .map((tag) => tag.trim())
                            .filter(Boolean),
                        template_id: form.template_id || null,
                        due_on: form.due_on || null,
                    },
                );
                if (result) {
                    toast.success('Changes saved');
                    router.reload();
                }
            }}
        >
            <fieldset disabled={mutation.busy} className="grid gap-4">
                <Field label="Idea title">
                    <Input
                        required
                        maxLength={200}
                        value={form.title}
                        onChange={(event) => patch('title', event.target.value)}
                    />
                </Field>
                <Field label="Creative brief · internal direction">
                    <Textarea
                        rows={4}
                        maxLength={10000}
                        value={form.brief}
                        onChange={(event) => patch('brief', event.target.value)}
                    />
                </Field>
                <Field label="Draft caption · public-facing copy">
                    <Textarea
                        rows={4}
                        maxLength={20000}
                        value={form.caption}
                        onChange={(event) =>
                            patch('caption', event.target.value)
                        }
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Category">
                        <Input
                            maxLength={100}
                            value={form.category}
                            onChange={(event) =>
                                patch('category', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Tags · comma-separated">
                        <Input
                            value={form.tags}
                            onChange={(event) =>
                                patch('tags', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Status">
                        <select
                            className={selectStyle}
                            value={form.status}
                            onChange={(event) =>
                                patch('status', event.target.value)
                            }
                        >
                            <option value="inbox">Inbox</option>
                            <option value="planned">Planned</option>
                            <option value="archived">Archived</option>
                        </select>
                    </Field>
                    <Field label="Planning date · does not schedule publication">
                        <Input
                            type="date"
                            value={form.due_on}
                            onChange={(event) =>
                                patch('due_on', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <Field label="Reusable template">
                    <select
                        className={selectStyle}
                        value={form.template_id}
                        onChange={(event) =>
                            patch('template_id', event.target.value)
                        }
                    >
                        <option value="">No template</option>
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Button
                    type="submit"
                    className="justify-self-start"
                    disabled={mutation.busy}
                >
                    {mutation.busy
                        ? 'Saving…'
                        : idea
                          ? 'Save idea'
                          : 'Capture idea'}
                </Button>
            </fieldset>
            <Feedback error={mutation.error} />
        </form>
    );
}

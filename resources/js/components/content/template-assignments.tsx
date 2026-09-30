import { router } from '@inertiajs/react';
import { toast } from 'sonner';

import { draftTemplate } from '@/actions/App/Http/Controllers/Content/ContentWorkspaceController';
import {
    Feedback,
    Field,
    selectStyle,
} from '@/components/content/content-forms';
import { Button } from '@/components/ui/button';
import { useContentMutation } from '@/hooks/content/use-content-mutation';
import type {
    ContentAccount,
    ContentTemplate,
    LibraryAsset,
    TemplateDestination,
} from '@/types/content';

export function TemplateAssignments({
    accounts,
    assets,
    destination,
    mediaIds,
    onDestination,
    onMedia,
}: {
    accounts: ContentAccount[];
    assets: LibraryAsset[];
    destination: TemplateDestination;
    mediaIds: string[];
    onDestination: (value: TemplateDestination) => void;
    onMedia: (value: string[]) => void;
}) {
    return (
        <div className="grid gap-4 rounded-xl border p-4">
            <Field label="Default destinations">
                <select
                    className={selectStyle}
                    value={destination.kind}
                    onChange={(event) =>
                        onDestination({
                            kind: event.target
                                .value as TemplateDestination['kind'],
                            ids: destination.ids ?? [],
                        })
                    }
                >
                    <option value="none">
                        Choose destinations in each draft
                    </option>
                    <option value="default">
                        Use workspace default account or set
                    </option>
                    <option value="accounts">Selected accounts</option>
                </select>
            </Field>
            {destination.kind === 'accounts' && (
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Destination accounts
                    </legend>
                    {accounts.map((account) => (
                        <label
                            key={account.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={(destination.ids ?? []).includes(
                                    account.id,
                                )}
                                onChange={(event) =>
                                    onDestination({
                                        ...destination,
                                        ids: event.target.checked
                                            ? [
                                                  ...(destination.ids ?? []),
                                                  account.id,
                                              ]
                                            : (destination.ids ?? []).filter(
                                                  (id) => id !== account.id,
                                              ),
                                    })
                                }
                            />
                            {account.name} · {account.platform}
                        </label>
                    ))}
                    {(destination.ids ?? [])
                        .filter(
                            (id) =>
                                !accounts.some((account) => account.id === id),
                        )
                        .map((id) => (
                            <label
                                key={id}
                                className="flex items-center gap-2 text-sm text-destructive"
                            >
                                <input
                                    type="checkbox"
                                    checked
                                    onChange={() =>
                                        onDestination({
                                            ...destination,
                                            ids: (destination.ids ?? []).filter(
                                                (value) => value !== id,
                                            ),
                                        })
                                    }
                                />
                                Unavailable account · remove before saving
                            </label>
                        ))}
                    {accounts.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Connect an account in this workspace to select it
                            here.
                        </p>
                    )}
                </fieldset>
            )}
            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">
                    Reusable media · select in posting order
                </legend>
                <div className="grid max-h-64 gap-2 overflow-y-auto">
                    {assets.map((asset) => (
                        <label
                            key={asset.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                checked={mediaIds.includes(asset.id)}
                                onChange={(event) =>
                                    onMedia(
                                        event.target.checked
                                            ? [...mediaIds, asset.id]
                                            : mediaIds.filter(
                                                  (id) => id !== asset.id,
                                              ),
                                    )
                                }
                            />
                            <img
                                src={asset.content_url}
                                alt=""
                                className="size-9 rounded border object-cover"
                            />
                            <span>
                                {asset.name} · v{asset.version}
                                {asset.archived_at ? ' · archived' : ''}
                                {mediaIds.includes(asset.id)
                                    ? ` · #${mediaIds.indexOf(asset.id) + 1}`
                                    : ''}
                            </span>
                        </label>
                    ))}
                </div>
                {assets.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Upload images in the asset library to reuse them here.
                    </p>
                )}
            </fieldset>
            {mediaIds.length > 1 && (
                <ol className="grid gap-2">
                    {mediaIds.map((id, position) => (
                        <li
                            key={id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="flex-1">
                                {position + 1}.{' '}
                                {assets.find((asset) => asset.id === id)
                                    ?.name ?? 'Unavailable asset'}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={position === 0}
                                aria-label={`Move media ${position + 1} up`}
                                onClick={() => {
                                    const next = [...mediaIds];
                                    [next[position - 1], next[position]] = [
                                        next[position],
                                        next[position - 1],
                                    ];
                                    onMedia(next);
                                }}
                            >
                                ↑
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onMedia(
                                        mediaIds.filter(
                                            (value) => value !== id,
                                        ),
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
            <p className="text-xs text-muted-foreground">
                Each draft gets independent image copies. Defaults are resolved
                when a draft is created, and every draft still requires review.
            </p>
        </div>
    );
}

export function TemplateDraftButton({
    template,
    workspaceId,
}: {
    template: ContentTemplate;
    workspaceId: string;
}) {
    const mutation = useContentMutation();
    return (
        <div className="grid gap-2">
            <Button
                className="justify-self-start"
                disabled={mutation.busy}
                onClick={async () => {
                    const result = await mutation.run<{
                        post_url: string;
                        creator_project_id: string | null;
                    }>(draftTemplate.url(template.id), 'POST', {
                        expected_workspace_id: workspaceId,
                        expected_revision: template.revision,
                    });
                    if (result) {
                        if (result.creator_project_id)
                            toast.success(
                                'Draft created. Its independent design is available in the creator project library.',
                            );
                        router.visit(result.post_url);
                    }
                }}
            >
                {mutation.busy
                    ? 'Creating draft…'
                    : 'Create draft from template'}
            </Button>
            <Feedback error={mutation.error} />
        </div>
    );
}

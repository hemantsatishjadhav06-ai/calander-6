import type {
    ComposerAction,
    ComposerState,
} from '@/lib/compose/composer-state';
import type { Account, PlatformName } from '@/types/compose';

const SUPPORTED = new Set<PlatformName>([
    'facebook',
    'instagram',
    'x',
    'threads',
]);

export function FirstCommentEditor({
    state,
    dispatch,
    accounts,
}: {
    state: ComposerState;
    dispatch: (action: ComposerAction) => void;
    accounts: Account[];
}) {
    return (
        <details className="border-t border-border px-4 py-3">
            <summary className="cursor-pointer text-sm font-medium">
                First comment
            </summary>
            <p className="my-2 text-xs text-muted-foreground">
                Send a separate comment after the post publishes. On X and
                Threads this replies to the first post, separate from your
                authored thread. Comment order is not guaranteed.
            </p>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={state.firstCommentEnabled}
                    onChange={(event) =>
                        dispatch({
                            type: 'setFirstComment',
                            enabled: event.target.checked,
                            text: state.firstComment,
                        })
                    }
                />
                Send a first comment to supported destinations
            </label>
            <label
                className="mt-3 block text-xs font-medium"
                htmlFor="first-comment-default"
            >
                Default first comment
            </label>
            <textarea
                id="first-comment-default"
                className="mt-1 min-h-20 w-full rounded-md border border-border bg-background p-2 text-sm"
                value={state.firstComment}
                maxLength={5000}
                onChange={(event) =>
                    dispatch({
                        type: 'setFirstComment',
                        enabled: state.firstCommentEnabled,
                        text: event.target.value,
                    })
                }
            />
            <p className="mt-1 text-xs text-muted-foreground">
                Supported text limits: X 280, Threads 500, Instagram 2,200,
                Facebook 5,000 characters. Brand and template suggestions are
                sent only when enabled here.
            </p>
            <div className="mt-3 space-y-3">
                {accounts.map((account) => {
                    const settings = state.firstCommentByAccount[
                        account.id
                    ] ?? { enabled: null, text: null };
                    const supported =
                        SUPPORTED.has(account.platform) &&
                        state.formatByAccount[account.id] !== 'story';
                    return (
                        <div
                            key={account.id}
                            className="rounded-md border border-border p-2"
                        >
                            <label className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                {account.handle} ({account.platform})
                                <select
                                    aria-label={`First comment for ${account.handle}`}
                                    className="rounded border border-border bg-background px-2 py-1"
                                    disabled={!supported}
                                    value={
                                        settings.enabled === null
                                            ? 'inherit'
                                            : settings.enabled
                                              ? 'on'
                                              : 'off'
                                    }
                                    onChange={(event) =>
                                        dispatch({
                                            type: 'setTargetFirstComment',
                                            accountId: account.id,
                                            enabled:
                                                event.target.value === 'inherit'
                                                    ? null
                                                    : event.target.value ===
                                                      'on',
                                            text:
                                                event.target.value === 'inherit'
                                                    ? null
                                                    : settings.text,
                                        })
                                    }
                                >
                                    <option value="inherit">
                                        Use post default
                                    </option>
                                    <option value="on">
                                        On for this account
                                    </option>
                                    <option value="off">
                                        Off for this account
                                    </option>
                                </select>
                            </label>
                            {!supported ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    First-comment delivery is unavailable for
                                    this platform or format. It will be skipped.
                                </p>
                            ) : (
                                settings.enabled === true && (
                                    <label className="mt-2 block text-xs">
                                        Account-specific comment
                                        <textarea
                                            aria-label={`First comment text for ${account.handle}`}
                                            className="mt-1 min-h-16 w-full rounded-md border border-border bg-background p-2 text-sm"
                                            maxLength={5000}
                                            value={
                                                settings.text ??
                                                state.firstComment
                                            }
                                            onChange={(event) =>
                                                dispatch({
                                                    type: 'setTargetFirstComment',
                                                    accountId: account.id,
                                                    enabled: true,
                                                    text: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                )
                            )}
                        </div>
                    );
                })}
            </div>
        </details>
    );
}

import { router, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import type { PostView } from '@/types/compose';

const LABELS: Record<string, string> = {
    not_started: 'Waiting for publication',
    pending: 'Queued',
    sending: 'Sending',
    sent: 'Sent',
    retryable: 'Not sent',
    blocked: 'Needs attention',
    uncertain: 'Delivery unconfirmed',
    skipped: 'Skipped',
};

export function FirstCommentStatus({ post }: { post: PostView }) {
    const http = useHttp<Record<string, never>, { post: PostView }>({});
    const [retrying, setRetrying] = useState<string | null>(null);
    const targets = post.targets.filter(
        (target) =>
            target.first_comment_delivery?.enabled ||
            (target.first_comment_delivery &&
                target.first_comment_delivery.status !== 'not_started'),
    );
    if (targets.length === 0) return null;
    return (
        <section
            className="mt-4 rounded-xl border border-border p-4"
            aria-label="First-comment delivery"
        >
            <h2 className="text-sm font-medium">First-comment delivery</h2>
            {targets.map((target) => {
                const delivery = target.first_comment_delivery!;
                const canRetry =
                    target.status === 'published' &&
                    ['retryable', 'blocked'].includes(delivery.status) &&
                    delivery.attempts < 3;
                return (
                    <div key={target.id} className="mt-3 text-sm">
                        <p>
                            {target.handle ?? target.platform}:{' '}
                            {LABELS[delivery.status] ?? delivery.status}
                        </p>
                        <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                            {delivery.error_message ?? delivery.reason}
                        </p>
                        {delivery.status === 'sent' && (
                            <p className="mt-1 text-xs whitespace-pre-wrap">
                                {delivery.text}
                            </p>
                        )}
                        {delivery.next_attempt_at && (
                            <p className="text-xs text-muted-foreground">
                                A safe retry is scheduled after the rate limit.
                            </p>
                        )}
                        {canRetry && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="mt-2"
                                disabled={retrying !== null}
                                onClick={async () => {
                                    setRetrying(target.id);
                                    try {
                                        await http.post(delivery.retry_url);
                                        router.reload({ only: ['post'] });
                                    } catch {
                                        toast.error(
                                            'Could not retry the first comment. Refresh for the latest status.',
                                        );
                                    } finally {
                                        setRetrying(null);
                                    }
                                }}
                            >
                                {retrying === target.id
                                    ? 'Queuing…'
                                    : 'Retry first comment'}
                            </Button>
                        )}
                    </div>
                );
            })}
        </section>
    );
}

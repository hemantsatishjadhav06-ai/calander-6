import type { FirstCommentDelivery } from '@/types/compose';

export function ReviewFirstComment({
    comment,
}: {
    comment?: Pick<
        FirstCommentDelivery,
        'enabled' | 'text' | 'supported' | 'reason'
    >;
}) {
    if (!comment) return null;
    return (
        <div className="mt-3 rounded-md border border-border bg-muted/30 p-2 text-xs">
            <p className="font-medium">
                First comment:{' '}
                {!comment.enabled
                    ? 'off (will not be sent)'
                    : comment.supported
                      ? 'enabled after publication'
                      : 'unavailable (will be skipped)'}
            </p>
            {!comment.supported && (
                <p className="mt-1 text-muted-foreground">{comment.reason}</p>
            )}
            {comment.text && (
                <p className="mt-1 whitespace-pre-wrap">
                    {!comment.enabled && 'Saved suggestion: '}
                    {comment.text}
                </p>
            )}
            {comment.enabled && comment.supported && !comment.text && (
                <p className="mt-1 text-destructive">
                    Add comment text before publishing
                </p>
            )}
        </div>
    );
}

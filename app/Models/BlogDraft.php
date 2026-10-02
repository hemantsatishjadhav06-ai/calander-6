<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\BlogDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $author_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property string|null $excerpt
 * @property string|null $featured_image_url
 * @property string|null $featured_image_alt
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $canonical_url
 * @property int $content_revision
 * @property string|null $review_requested_revision
 * @property CarbonImmutable|null $review_requested_at
 * @property string|null $approved_revision
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejected_revision
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable $updated_at
 * @property string $publication_status
 * @property string|null $publication_attempt_id
 * @property string|null $publication_revision
 * @property string|null $publication_error
 * @property string|null $publication_deploy_id
 * @property string|null $publication_deploy_attempt_id
 * @property string|null $publication_url
 * @property string|null $publication_base_deploy_id
 * @property bool|null $publication_base_was_locked
 * @property string|null $published_revision
 * @property string|null $published_url
 * @property CarbonImmutable|null $published_at
 */
#[Fillable([
    'workspace_id', 'author_id', 'title', 'slug', 'body', 'excerpt',
    'featured_image_url', 'featured_image_alt', 'seo_title', 'seo_description', 'canonical_url',
])]
class BlogDraft extends Model
{
    /** @use HasFactory<BlogDraftFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected $attributes = ['content_revision' => 1, 'publication_status' => 'idle'];

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'content_revision' => 'integer',
            'review_requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'publication_base_was_locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

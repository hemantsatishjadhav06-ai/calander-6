<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\BrandProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Override;

#[Fillable(['workspace_id', 'website_url', 'instagram_username', 'facebook_page_id', 'facebook_page_url', 'x_username', 'netlify_site_id', 'repository_url'])]
class BrandProfile extends Model
{
    /** @use HasFactory<BrandProfileFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    #[Override]
    protected static function booted(): void
    {
        static::updated(function (BrandProfile $profile): void {
            if ($profile->wasChanged(['website_url', 'netlify_site_id', 'repository_url'])) {
                BlogDraft::withoutGlobalScope('workspace')->where('workspace_id', $profile->workspace_id)->update([
                    'content_revision' => DB::raw('content_revision + 1'),
                    'review_requested_revision' => null,
                    'review_requested_at' => null,
                    'approved_revision' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'rejected_revision' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

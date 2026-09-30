<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Database\Factories\WorkspaceBrandProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int $revision
 * @property string|null $tagline
 * @property string|null $voice
 * @property string|null $audience
 * @property string|null $guidelines
 * @property list<string> $palette
 * @property string|null $logo_asset_id
 * @property list<string> $default_hashtags
 * @property bool $first_comment_enabled
 * @property string|null $first_comment
 */
#[Fillable(['workspace_id', 'revision', 'tagline', 'voice', 'audience', 'guidelines', 'palette', 'logo_asset_id', 'default_hashtags', 'first_comment_enabled', 'first_comment'])]
class WorkspaceBrandProfile extends Model
{
    /** @use HasFactory<WorkspaceBrandProfileFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['revision' => 'integer', 'palette' => 'array', 'default_hashtags' => 'array', 'first_comment_enabled' => 'boolean'];
    }
}

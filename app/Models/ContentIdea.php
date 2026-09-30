<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\ContentIdeaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int $revision
 * @property string $title
 * @property string|null $brief
 * @property string|null $caption
 * @property string|null $category
 * @property list<string> $tags
 * @property string $status
 * @property CarbonImmutable|null $due_on
 * @property string|null $template_id
 * @property string|null $draft_post_id
 * @property string|null $creator_project_id
 */
#[Fillable(['workspace_id', 'created_by_id', 'title', 'brief', 'caption', 'category', 'tags', 'status', 'due_on', 'template_id', 'draft_post_id', 'creator_project_id', 'revision'])]
class ContentIdea extends Model
{
    /** @use HasFactory<ContentIdeaFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['revision' => 'integer', 'tags' => 'array', 'due_on' => 'immutable_date'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonImmutable;
use Database\Factories\CreatorProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $created_by_id
 * @property CarbonImmutable|null $updated_at
 * @property string $name
 * @property int $revision
 * @property string $document_hash
 * @property array<string, mixed> $document
 */
#[Fillable(['workspace_id', 'created_by_id', 'name', 'revision', 'document_hash', 'document'])]
class CreatorProject extends Model
{
    /** @use HasFactory<CreatorProjectFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @var array<string, mixed> */
    protected $attributes = ['revision' => 1];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'document' => 'array'];
    }

    /** @return array<string, mixed> */
    public function toView(): array
    {
        return ['id' => $this->id, 'workspace_id' => $this->workspace_id, 'name' => $this->name, 'revision' => $this->revision,
            'document' => $this->document, 'updated_at' => $this->updated_at?->toISOString()];
    }
}

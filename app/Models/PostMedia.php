<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use App\Services\Media\DerivedMedia;
use App\Services\Posts\PostApprovalService;
use App\Support\FileStorage;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $post_id
 * @property string|null $direct_message_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 * @property string $kind
 * @property int|null $duration_seconds
 * @property string|null $source_disk
 * @property string|null $source_path
 * @property array<string, mixed>|null $edit_settings
 */
#[Fillable([
    'workspace_id',
    'post_id',
    'direct_message_id',
    'disk',
    'path',
    'mime',
    'size_bytes',
    'width',
    'height',
    'alt_text',
    'position',
    'kind',
    'duration_seconds',
    'source_disk',
    'source_path',
    'edit_settings',
])]
#[Table(name: 'post_media')]
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /**
     * In-memory default so a row freshly created without an explicit kind still
     * serializes as an image (Eloquent does not hydrate DB defaults after create).
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = ['kind' => 'image'];

    /**
     * Delete the backing file(s) when the row is removed, so storage doesn't
     * accumulate orphans. Covers both the composed file and a retained source.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saving(function (PostMedia $media): void {
            if ($media->isDirty(['post_id', 'path', 'disk', 'mime', 'kind', 'size_bytes', 'alt_text', 'position', 'edit_settings', 'width', 'height', 'duration_seconds'])) {
                $postIds = array_filter([$media->post_id, $media->getOriginal('post_id')]);
                foreach (array_unique($postIds) as $postId) {
                    if (($post = Post::withoutGlobalScopes()->whereKey((string) $postId)->first()) !== null) {
                        app(PostApprovalService::class)->invalidate($post);
                    }
                }
            }
        });

        static::deleting(function (PostMedia $media): void {
            if ($media->post_id !== null && ($post = Post::withoutGlobalScopes()->find($media->post_id)) !== null) {
                app(PostApprovalService::class)->invalidate($post);
            }
            FileStorage::disk($media->disk)->delete($media->path);

            // Publish-time format conversions (JPEG for Meta, MP4 for GIFs) live
            // beside the original and would otherwise be left behind.
            FileStorage::disk($media->disk)->delete(DerivedMedia::pathsFor($media));

            if ($media->source_path !== null) {
                FileStorage::disk($media->source_disk ?? $media->disk)->delete($media->source_path);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['edit_settings' => 'array'];
    }

    public function url(): string
    {
        return $this->resolveUrl($this->disk, $this->path);
    }

    public function source_url(): ?string
    {
        if ($this->source_path === null) {
            return null;
        }

        return $this->resolveUrl($this->source_disk ?? $this->disk, $this->source_path);
    }

    /**
     * Resolve a browser-loadable URL for a stored file. Public-visibility disks
     * (images) get a permanent public URL; private disks (videos live on the
     * default disk — local-serve in dev, a private S3 bucket in production) need
     * a signed, expiring URL, otherwise the serve route / bucket rejects a
     * direct GET with 403.
     */
    private function resolveUrl(string $disk, string $path): string
    {
        return FileStorage::url($path, $disk);
    }

    /**
     * `edit_url` / `source_edit_url` are same-origin proxy URLs the canvas
     * editors fetch instead of the display URLs, whose storage origin may omit
     * CORS headers. `source_edit_url` is null when no pre-edit source is kept.
     *
     * @return array{id: string, url: string, mime: string, kind: string, duration_seconds: int|null, alt_text: string|null, position: int, edit_settings: array<string, mixed>|null, source_url: string|null, edit_url: string, source_edit_url: string|null}
     */
    public function toView(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime' => $this->mime,
            'kind' => $this->kind,
            'duration_seconds' => $this->duration_seconds,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
            'edit_settings' => $this->edit_settings,
            'source_url' => $this->source_url(),
            'edit_url' => route('media.raw', $this),
            'source_edit_url' => $this->source_path === null
                ? null
                : route('media.raw', ['media' => $this, 'variant' => 'source']),
        ];
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    /**
     * Whether this image is attach-only rather than editable. The raster
     * beautifier would flatten an animation to a single frame, so animated media
     * must never open the editor. Animation isn't visible from the mime alone —
     * the GIF browser stores a "GIF" as the larger `image/webp` variant, the same
     * mime the beautifier emits — so a WebP is treated as editable only when it is
     * a beautifier output (it carries edit_settings). GIFs are always animated.
     * Mirrors the client's isAttachOnlyImage() (resources/js/lib/compose/media-rules.ts).
     */
    public function isAttachOnlyImage(): bool
    {
        if ($this->mime === 'image/gif') {
            return true;
        }

        return $this->mime === 'image/webp' && $this->edit_settings === null;
    }
}

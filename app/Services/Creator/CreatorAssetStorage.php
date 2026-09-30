<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Models\CreatorAsset;
use App\Support\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreatorAssetStorage
{
    public function store(string $workspaceId, UploadedFile $file, string $name, string $kind, ?string $actorId): CreatorAsset
    {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new RuntimeException('The uploaded image could not be read.');
        }

        return $this->storeBytes($workspaceId, $bytes, (string) $file->getMimeType(), $name, $kind, $actorId);
    }

    public function storeBytes(string $workspaceId, string $bytes, string $mime, string $name, string $kind, ?string $actorId): CreatorAsset
    {
        $metadata = self::inspect($bytes, $mime);
        $extension = match ($metadata['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        };
        abort_unless(in_array($kind, ['image', 'logo'], true), 422, 'Unsupported asset kind.');
        $disk = FileStorage::diskName();
        $path = 'creator/'.$workspaceId.'/assets/'.Str::uuid().'.'.$extension;
        if (! FileStorage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('The image could not be stored.');
        }
        try {
            return CreatorAsset::create(['workspace_id' => $workspaceId, 'created_by_id' => $actorId,
                'name' => mb_substr($name, 0, 200), 'kind' => $kind, 'disk' => $disk, 'path' => $path,
                ...$metadata, 'sha256' => hash('sha256', $bytes)]);
        } catch (Throwable $exception) {
            FileStorage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    /** @return array{mime: string, size_bytes: int, width: int, height: int} */
    public static function inspect(string $bytes, string $mime): array
    {
        $size = strlen($bytes);
        $info = $size <= 8_388_608 ? @getimagesizefromstring($bytes) : false;
        if (! is_array($info) || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1
            || (int) config('media.max_image_pixels', 16_000_000) < $info[0] * $info[1]) {
            throw ValidationException::withMessages(['file' => 'Use a valid JPEG, PNG or static WebP within 8 MiB and the image-resolution limit.']);
        }
        if ($mime === 'image/webp') {
            for ($offset = 12; $offset + 8 <= $size;) {
                $chunk = substr($bytes, $offset, 4);
                if (in_array($chunk, ['ANIM', 'ANMF'], true)) {
                    throw ValidationException::withMessages(['file' => 'Animated WebP cannot be used as an editable image layer.']);
                }
                $length = unpack('Vlength', substr($bytes, $offset + 4, 4));
                $chunkLength = (int) ($length['length'] ?? 0);
                $offset += 8 + $chunkLength + ($chunkLength % 2);
            }
        }

        return ['mime' => $info['mime'], 'size_bytes' => $size, 'width' => $info[0], 'height' => $info[1]];
    }
}

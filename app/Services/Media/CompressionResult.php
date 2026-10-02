<?php

declare(strict_types=1);

namespace App\Services\Media;

final readonly class CompressionResult
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public bool $wasCompressed,
    ) {}

    public static function untouched(string $bytes, string $mime): self
    {
        return new self($bytes, $mime, false);
    }

    public static function compressed(string $bytes, string $mime): self
    {
        return new self($bytes, $mime, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Creator;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class CreatorProviderException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502,
        public readonly bool $outcomeUnknown = false,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->httpStatus);
    }
}

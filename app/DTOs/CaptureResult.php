<?php

namespace App\DTOs;

readonly class CaptureResult
{
    public function __construct(
        public bool $success,
        public string $mediaType,    // 'image' | 'video'
        public ?string $rawContent,   // binary payload (used by mock/sync drivers)
        public ?string $contentType,  // MIME type from device
        public ?string $errorMessage,
        public array $meta = [],
    ) {}
}

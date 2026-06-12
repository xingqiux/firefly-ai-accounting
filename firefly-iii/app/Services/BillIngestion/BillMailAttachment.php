<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

final readonly class BillMailAttachment
{
    public function __construct(
        public string $filename,
        public ?string $path = null,
        public ?string $checksum = null,
        public ?int $size = null,
    ) {}
}

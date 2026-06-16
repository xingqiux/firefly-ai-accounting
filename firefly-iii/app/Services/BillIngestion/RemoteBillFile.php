<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

final readonly class RemoteBillFile
{
    public function __construct(
        public string $content,
        public string $filename,
        public string $contentType,
    ) {}
}

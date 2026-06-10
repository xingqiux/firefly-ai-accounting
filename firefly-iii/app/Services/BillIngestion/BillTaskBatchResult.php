<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

final readonly class BillTaskBatchResult
{
    public function __construct(
        public int $processed,
        public int $failed,
    ) {}
}

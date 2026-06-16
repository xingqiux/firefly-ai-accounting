<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

final class BillMailboxSyncResult
{
    /** @var array<int, string> */
    public array $errors = [];

    public function __construct(
        public int $scanned = 0,
        public int $created = 0,
        public int $ignored = 0,
        public int $duplicates = 0,
        public int $failed = 0,
    ) {}

    public function addError(string $message): void
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    public function merge(self $result): void
    {
        $this->scanned    += $result->scanned;
        $this->created    += $result->created;
        $this->ignored    += $result->ignored;
        $this->duplicates += $result->duplicates;
        $this->failed     += $result->failed;
        foreach ($result->errors as $error) {
            $this->addError($error);
        }
    }
}

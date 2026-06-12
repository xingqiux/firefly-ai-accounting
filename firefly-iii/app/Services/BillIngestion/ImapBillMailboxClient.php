<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

interface ImapBillMailboxClient
{
    public function close(): void;

    public function connect(BillMailboxConfig $config): void;

    public function fetchRawMessage(string $uid): string;

    public function markSeen(string $uid): void;

    /**
     * @return array<int, string>
     */
    public function search(string $criteria, int $limit): array;

    public function selectFolder(string $folder): void;
}

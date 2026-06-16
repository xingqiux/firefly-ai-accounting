<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillTask;

class BillMailIngestionService
{
    public function __construct(private readonly BillSourceChannelRegistry $channelRegistry) {}

    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    public function ingest(BillMailMessage $mail, array $attachments): ?BillTask
    {
        $channel = $this->channelRegistry->matchMail($mail, $attachments);
        if (null === $channel) {
            return null;
        }

        return $channel->ingest($mail, $attachments);
    }
}

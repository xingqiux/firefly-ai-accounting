<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use FireflyIII\Models\BillMailMessage;

class BillSourceChannelRegistry
{
    /**
     * @param array<int, BillSourceChannel> $channels
     */
    public function __construct(private readonly array $channels) {}

    /**
     * @return array<int, string>
     */
    public function mailboxSearchCriteria(): array
    {
        $criteria = [];
        foreach ($this->channels as $channel) {
            foreach ($channel->mailboxSearchCriteria() as $criterion) {
                if ('' !== $criterion && !in_array($criterion, $criteria, true)) {
                    $criteria[] = $criterion;
                }
            }
        }

        return $criteria;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function settingsChannels(): array
    {
        return array_map(static fn (BillSourceChannel $channel): array => [
            'source'      => $channel->source(),
            'name'        => $channel->displayName(),
            'description' => $channel->settingsDescription(),
        ], $this->channels);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function processingRules(): array
    {
        return array_map(static fn (BillSourceChannel $channel): array => $channel->processingRule(), $this->channels);
    }

    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    public function matchMail(BillMailMessage $mail, array $attachments): ?BillSourceChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->matches($mail, $attachments)) {
                return $channel;
            }
        }

        return null;
    }

    public function find(string $source, ?string $profileId): ?BillSourceChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->source() !== $source) {
                continue;
            }
            if (null === $profileId || in_array($profileId, $channel->profileIds(), true)) {
                return $channel;
            }
        }

        return null;
    }
}

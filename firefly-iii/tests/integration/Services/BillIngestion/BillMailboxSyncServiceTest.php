<?php

declare(strict_types=1);

namespace Tests\integration\Services\BillIngestion;

use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillMailboxConfig;
use FireflyIII\Services\BillIngestion\BillMailboxSyncResult;
use FireflyIII\Services\BillIngestion\BillMailboxSyncService;
use FireflyIII\Services\BillIngestion\ImapBillMailboxClient;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Override;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Services\BillIngestion\BillMailboxConfig
 * @covers \FireflyIII\Services\BillIngestion\BillMailboxSyncResult
 * @covers \FireflyIII\Services\BillIngestion\BillMailboxSyncService
 */
final class BillMailboxSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    public function testSyncCreatesAlipayTaskFromConfiguredGmailMailbox(): void
    {
        Storage::fake('local');
        $client = new FakeImapBillMailboxClient([
            new FakeImapMailMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->configureMailbox();

        $result = app(BillMailboxSyncService::class)->syncForUser($this->user, 10);

        $this->assertInstanceOf(BillMailboxSyncResult::class, $result);
        $this->assertSame(1, $result->scanned);
        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->ignored);
        $this->assertSame(0, $result->duplicates);
        $this->assertSame(0, $result->failed);
        $this->assertSame(['INBOX'], $client->selectedFolders);
        $this->assertSame(['FROM "service@mail.alipay.com"'], $client->searches);
        $this->assertSame(['42'], $client->seenUids);

        $mail = BillMailMessage::query()->first();
        $this->assertSame('<alipay-statement-20260612@mail.alipay.com>', $mail->message_id);
        $this->assertSame('ziyufg@gmail.com', $mail->mailbox);
        $this->assertSame('service@mail.alipay.com', $mail->from_address);
        $this->assertSame('ziyufg@gmail.com', $mail->to_address);
        $this->assertSame('李昶乐的支付宝交易流水明细', $mail->subject);
        $this->assertSame('gmail:42', $mail->sync_cursor);
        $this->assertNotNull($mail->raw_path);
        Storage::disk('local')->assertExists($mail->raw_path);

        $task = BillTask::query()->first();
        $this->assertSame('alipay', $task->source);
        $this->assertSame('alipay-statement', $task->profile_id);
        $this->assertSame('received', $task->status);
        $this->assertSame($mail->id, $task->bill_mail_message_id);

        $artifact = BillArtifact::query()->first();
        $this->assertSame($task->id, $artifact->bill_task_id);
        $this->assertSame('zip', $artifact->kind);
        $this->assertSame('支付宝交易明细(20260601-20260612).zip', $artifact->filename);
        $this->assertTrue($artifact->encrypted);
        $this->assertSame('alipay_service_message', $artifact->metadata['password_source']);
        $this->assertNotNull($artifact->path);
        Storage::disk('local')->assertExists($artifact->path);
    }

    public function testSyncSkipsDuplicateMessages(): void
    {
        Storage::fake('local');
        $client = new FakeImapBillMailboxClient([
            new FakeImapMailMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->configureMailbox();

        BillMailMessage::query()->create([
            'user_id'     => $this->user->id,
            'message_id'  => '<alipay-statement-20260612@mail.alipay.com>',
            'mailbox'     => 'ziyufg@gmail.com',
            'subject'     => '李昶乐的支付宝交易流水明细',
            'sync_cursor' => 'gmail:42',
        ]);

        $result = app(BillMailboxSyncService::class)->syncForUser($this->user, 10);

        $this->assertSame(1, $result->scanned);
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->duplicates);
        $this->assertSame(1, BillMailMessage::query()->count());
        $this->assertSame(0, BillTask::query()->count());
        $this->assertSame([], $client->seenUids);
    }

    public function testSyncUsesConfiguredGmailLabelAsMailboxFolder(): void
    {
        Storage::fake('local');
        $client = new FakeImapBillMailboxClient([
            new FakeImapMailMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->configureMailbox(quickLabel: 'Bills');

        $result = app(BillMailboxSyncService::class)->syncForUser($this->user, 10);

        $this->assertSame(1, $result->created);
        $this->assertSame(['Bills'], $client->selectedFolders);
    }

    public function testSyncDoesNothingWhenMailboxIsDisabled(): void
    {
        Storage::fake('local');
        $client = new FakeImapBillMailboxClient([
            new FakeImapMailMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->configureMailbox(false);

        $result = app(BillMailboxSyncService::class)->syncForUser($this->user, 10);

        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, $result->created);
        $this->assertFalse($client->connected);
        $this->assertSame(0, BillMailMessage::query()->count());
    }

    public function testArtisanCommandRunsMailboxSync(): void
    {
        Storage::fake('local');
        $client = new FakeImapBillMailboxClient([
            new FakeImapMailMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->configureMailbox();

        $this->artisan('firefly-iii:sync-bill-mailbox', [
            '--user'  => (string) $this->user->id,
            '--limit' => '10',
        ])->expectsOutputToContain('Scanned 1 mail message')
            ->assertExitCode(0)
        ;

        $this->assertSame(1, BillTask::query()->where('source', 'alipay')->count());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();
    }

    private function configureMailbox(bool $enabled = true, string $quickLabel = ''): void
    {
        $this->actingAs($this->user);

        Preferences::set('bill_inbox_mailbox_enabled', $enabled);
        Preferences::set('bill_inbox_mailbox_provider', 'gmail');
        Preferences::set('bill_inbox_mailbox_email', 'ziyufg@gmail.com');
        Preferences::set('bill_inbox_mailbox_host', 'imap.gmail.com');
        Preferences::set('bill_inbox_mailbox_port', 993);
        Preferences::set('bill_inbox_mailbox_encryption', 'ssl');
        Preferences::set('bill_inbox_mailbox_username', 'ziyufg@gmail.com');
        Preferences::setEncrypted('bill_inbox_mailbox_password', 'gmail-app-password');
        Preferences::set('bill_inbox_mailbox_folder', 'INBOX');
        Preferences::set('bill_inbox_quick_gmail_label', $quickLabel);
        Preferences::set('bill_inbox_quick_keywords', '支付宝, 交易流水');
        Preferences::set('bill_inbox_processing_rules', [[
            'enabled'          => true,
            'name'             => '支付宝交易流水',
            'source'           => 'alipay',
            'from_contains'    => 'service@mail.alipay.com',
            'subject_contains' => '支付宝交易流水明细',
            'gmail_label'      => '',
            'keywords'         => ['支付宝', '交易流水'],
        ]]);
    }

    private function alipayRawMessage(): string
    {
        $email = (new \Symfony\Component\Mime\Email())
            ->from('支付宝提醒 <service@mail.alipay.com>')
            ->to('ziyufg@gmail.com')
            ->subject('李昶乐的支付宝交易流水明细')
            ->date(new \DateTimeImmutable('2026-06-12 18:26:00 +0800'))
            ->text('附件已加密，解压密码已通过支付宝服务消息发送。')
            ->attach('encrypted zip bytes', '支付宝交易明细(20260601-20260612).zip', 'application/zip')
        ;
        $email->getHeaders()->addIdHeader('Message-ID', 'alipay-statement-20260612@mail.alipay.com');

        return $email->toString();
    }
}

final class FakeImapMailMessage
{
    public function __construct(
        public readonly string $uid,
        public readonly string $raw,
    ) {}
}

final class FakeImapBillMailboxClient implements ImapBillMailboxClient
{
    /** @var array<int, FakeImapMailMessage> */
    private array $messages;

    /** @var array<int, string> */
    public array $selectedFolders = [];

    /** @var array<int, string> */
    public array $searches = [];

    /** @var array<int, string> */
    public array $seenUids = [];

    public bool $connected = false;

    /**
     * @param array<int, FakeImapMailMessage> $messages
     */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function connect(BillMailboxConfig $config): void
    {
        $this->connected = true;
    }

    public function selectFolder(string $folder): void
    {
        $this->selectedFolders[] = $folder;
    }

    public function search(string $criteria, int $limit): array
    {
        $this->searches[] = $criteria;

        return array_slice(array_map(static fn (FakeImapMailMessage $message): string => $message->uid, $this->messages), 0, $limit);
    }

    public function fetchRawMessage(string $uid): string
    {
        foreach ($this->messages as $message) {
            if ($message->uid === $uid) {
                return $message->raw;
            }
        }

        return '';
    }

    public function markSeen(string $uid): void
    {
        $this->seenUids[] = $uid;
    }

    public function close(): void
    {
        $this->connected = false;
    }
}

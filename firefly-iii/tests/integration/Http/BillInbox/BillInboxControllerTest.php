<?php

declare(strict_types=1);

namespace Tests\integration\Http\BillInbox;

use Carbon\Carbon;
use FireflyIII\Http\Middleware\InterestingMessage;
use FireflyIII\Http\Middleware\Range;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillSecretChallenge;
use FireflyIII\Models\BillTask;
use FireflyIII\Models\BillTaskEvent;
use FireflyIII\Services\BillIngestion\BillMailboxConfig;
use FireflyIII\Services\BillIngestion\ImapBillMailboxClient;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Support\Facades\Storage;
use Override;
use PragmaRX\Google2FALaravel\Middleware as MFAMiddleware;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Http\Controllers\BillInbox\IndexController
 * @covers \FireflyIII\Services\BillIngestion\BillMailboxSyncService
 * @covers \FireflyIII\Services\BillIngestion\BillTaskActionService
 * @covers \FireflyIII\Services\BillIngestion\BillTaskProcessor
 */
final class BillInboxControllerTest extends TestCase
{
    private User $user;
    private BillTask $task;

    public function testIndexShowsBillTasks(): void
    {
        $response = $this->actingAs($this->user)->get(route('bill-inbox.index'));

        $response->assertStatus(200);
        $response->assertSee('账单收件箱');
        $response->assertSee('招商银行信用卡电子账单');
        $response->assertSee('needs_secret');
    }

    public function testIndexShowsMailboxConfigurationStatus(): void
    {
        $this->actingAs($this->user);

        Preferences::set('bill_inbox_mailbox_enabled', false);
        Preferences::set('bill_inbox_mailbox_provider', 'gmail');
        Preferences::set('bill_inbox_mailbox_email', 'money@example.com');
        Preferences::setEncrypted('bill_inbox_mailbox_password', 'gmail-app-password');
        Preferences::set('bill_inbox_quick_gmail_label', 'buii');
        Preferences::set('bill_inbox_quick_keywords', '账单, statement');

        $response = $this->get(route('bill-inbox.index'));

        $response->assertStatus(200);
        $response->assertSee('邮箱配置状态');
        $response->assertSee('未启用');
        $response->assertSee('money@example.com');
        $response->assertSee('buii');
        $response->assertSee('账单, statement');
        $response->assertSee('应用密码已保存');
    }

    public function testShowDisplaysTaskDetailAndSecretForm(): void
    {
        $response = $this->actingAs($this->user)->get(route('bill-inbox.show', [$this->task->id]));

        $response->assertStatus(200);
        $response->assertSee('账单任务 #'.$this->task->id);
        $response->assertSee('需要密码或验证码');
        $response->assertSee('statement.zip');
        $response->assertSee('task.created');
    }

    public function testSecretSubmitConsumesChallenge(): void
    {
        $response = $this->actingAs($this->user)->post(route('bill-inbox.secret', [$this->task->id]), [
            'value' => '123456',
        ]);

        $response->assertRedirect(route('bill-inbox.show', [$this->task->id]));

        $this->task->refresh();
        $this->assertSame('ready', $this->task->status);
        $this->assertNull($this->task->current_secret_challenge_id);
        $this->assertSame('consumed', $this->task->secretChallenges()->first()->status);
    }

    public function testSettingsSaveMailboxConfiguration(): void
    {
        $response = $this->actingAs($this->user)->post(route('bill-inbox.settings.post'), [
            'enabled'    => '1',
            'provider'   => 'imap',
            'email'      => 'bills@example.com',
            'host'       => 'imap.example.com',
            'port'       => '993',
            'encryption' => 'ssl',
            'username'   => 'bills@example.com',
            'password'   => 'app-password',
            'folder'     => 'INBOX',
        ]);

        $response->assertRedirect(route('bill-inbox.settings'));
        $this->actingAs($this->user);
        $this->assertTrue(Preferences::get('bill_inbox_mailbox_enabled')->data);
        $this->assertSame('bills@example.com', Preferences::get('bill_inbox_mailbox_email')->data);
        $this->assertSame('app-password', Preferences::getEncrypted('bill_inbox_mailbox_password')->data);
    }

    public function testSettingsPageExplainsOrdinaryMailboxRules(): void
    {
        $response = $this->actingAs($this->user)->get(route('bill-inbox.settings'));

        $response->assertStatus(200);
        $response->assertSee('普通邮箱');
        $response->assertSee('Gmail 地址');
        $response->assertSee('应用密码');
        $response->assertSee('Gmail');
        $response->assertSee('只处理这些邮件');
        $response->assertSee('高级设置');
        $response->assertDontSee('来源标识');
        $response->assertDontSee('附件类型');
        $response->assertDontSee('这个邮箱只用于接收账单邮件');
    }

    public function testSettingsSaveQuickGmailConfiguration(): void
    {
        $response = $this->actingAs($this->user)->post(route('bill-inbox.settings.post'), [
            'enabled'           => '1',
            'email'             => 'money@example.com',
            'password'          => 'gmail-app-password',
            'quick_gmail_label' => 'Bills',
            'quick_keywords'    => '账单, statement',
        ]);

        $response->assertRedirect(route('bill-inbox.settings'));
        $this->actingAs($this->user);

        $this->assertSame('gmail', Preferences::get('bill_inbox_mailbox_provider')->data);
        $this->assertSame('money@example.com', Preferences::get('bill_inbox_mailbox_username')->data);
        $this->assertSame('imap.gmail.com', Preferences::get('bill_inbox_mailbox_host')->data);
        $this->assertSame(993, Preferences::get('bill_inbox_mailbox_port')->data);
        $this->assertSame('ssl', Preferences::get('bill_inbox_mailbox_encryption')->data);

        $rules = Preferences::get('bill_inbox_processing_rules')->data;
        $this->assertCount(1, $rules);
        $this->assertSame('默认账单邮件', $rules[0]['name']);
        $this->assertSame('mail-bill', $rules[0]['source']);
        $this->assertSame('Bills', $rules[0]['gmail_label']);
        $this->assertSame(['账单', 'statement'], $rules[0]['keywords']);
        $this->assertTrue($rules[0]['enabled']);
    }

    public function testPostSyncCreatesAlipayTaskAndRequestsPassword(): void
    {
        Storage::fake('local');
        $this->app->instance(ImapBillMailboxClient::class, new FakeBillInboxImapClient([
            new FakeBillInboxImapMessage('42', $this->alipayRawMessage()),
        ]));
        $this->actingAs($this->user);
        $this->configureAlipayMailbox();

        $response = $this->post(route('bill-inbox.sync'));

        $response->assertRedirect(route('bill-inbox.index'));

        $task = BillTask::query()->where('source', 'alipay')->first();
        $this->assertInstanceOf(BillTask::class, $task);
        $this->assertSame('needs_secret', $task->status);
        $this->assertSame('请输入支付宝服务消息中的账单解压密码', $task->currentSecretChallenge->prompt);
        $this->assertSame('支付宝交易流水明细', $task->summary);
        $this->assertSame('service@mail.alipay.com', $task->mailMessage->from_address);
    }

    public function testSettingsSaveGmailConfigurationAndProcessingRules(): void
    {
        $response = $this->actingAs($this->user)->post(route('bill-inbox.settings.post'), [
            'enabled'         => '1',
            'provider'        => 'gmail',
            'email'           => 'money@example.com',
            'host'            => '',
            'port'            => '',
            'encryption'      => '',
            'username'        => 'money@example.com',
            'password'        => 'gmail-app-password',
            'folder'          => '',
            'rule_enabled'    => ['1', '1'],
            'rule_name'       => ['招商信用卡', ''],
            'rule_source'     => ['cmb-credit-card', ''],
            'rule_from'       => ['cmbchina.com', ''],
            'rule_subject'    => ['电子账单', ''],
            'rule_attachment' => ['zip,pdf', ''],
            'rule_gmail_label' => ['Bank Bills', ''],
        ]);

        $response->assertRedirect(route('bill-inbox.settings'));
        $this->actingAs($this->user);

        $this->assertSame('gmail', Preferences::get('bill_inbox_mailbox_provider')->data);
        $this->assertSame('imap.gmail.com', Preferences::get('bill_inbox_mailbox_host')->data);
        $this->assertSame(993, Preferences::get('bill_inbox_mailbox_port')->data);
        $this->assertSame('ssl', Preferences::get('bill_inbox_mailbox_encryption')->data);
        $this->assertSame('INBOX', Preferences::get('bill_inbox_mailbox_folder')->data);

        $rules = Preferences::get('bill_inbox_processing_rules')->data;
        $this->assertCount(1, $rules);
        $this->assertSame('招商信用卡', $rules[0]['name']);
        $this->assertSame('cmb-credit-card', $rules[0]['source']);
        $this->assertSame('cmbchina.com', $rules[0]['from_contains']);
        $this->assertSame('电子账单', $rules[0]['subject_contains']);
        $this->assertSame(['zip', 'pdf'], $rules[0]['attachment_extensions']);
        $this->assertSame('Bank Bills', $rules[0]['gmail_label']);
        $this->assertTrue($rules[0]['enabled']);
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

    private function configureAlipayMailbox(): void
    {
        Preferences::set('bill_inbox_mailbox_enabled', true);
        Preferences::set('bill_inbox_mailbox_provider', 'gmail');
        Preferences::set('bill_inbox_mailbox_email', 'ziyufg@gmail.com');
        Preferences::set('bill_inbox_mailbox_host', 'imap.gmail.com');
        Preferences::set('bill_inbox_mailbox_port', 993);
        Preferences::set('bill_inbox_mailbox_encryption', 'ssl');
        Preferences::set('bill_inbox_mailbox_username', 'ziyufg@gmail.com');
        Preferences::setEncrypted('bill_inbox_mailbox_password', 'gmail-app-password');
        Preferences::set('bill_inbox_mailbox_folder', 'INBOX');
        Preferences::set('bill_inbox_quick_gmail_label', '');
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            MFAMiddleware::class,
            Range::class,
            InterestingMessage::class,
        ]);

        $this->user = $this->createAuthenticatedUser();

        $mail       = BillMailMessage::query()->create([
            'user_id'     => $this->user->id,
            'message_id'  => '<mail-web-1@example.com>',
            'mailbox'     => 'bills@example.com',
            'from_address' => 'bank@example.com',
            'to_address'  => 'bills@example.com',
            'subject'     => '招商银行信用卡电子账单',
            'received_at' => Carbon::parse('2026-06-10 09:30:00', 'Asia/Shanghai'),
            'raw_path'    => 'mail/raw/mail-web-1.eml',
            'checksum'    => 'mail-checksum',
            'sync_cursor' => 'cursor-1',
        ]);

        $this->task = BillTask::query()->create([
            'user_id'              => $this->user->id,
            'bill_mail_message_id' => $mail->id,
            'source'               => 'cmb',
            'profile_id'           => 'cmb-credit-card',
            'status'               => 'needs_secret',
            'received_at'          => Carbon::parse('2026-06-10 09:30:00', 'Asia/Shanghai'),
            'summary'              => '招商银行信用卡电子账单',
        ]);

        BillArtifact::query()->create([
            'bill_task_id' => $this->task->id,
            'kind'         => 'zip',
            'filename'     => 'statement.zip',
            'path'         => 'artifacts/original/task-1/statement.zip',
            'checksum'     => 'artifact-checksum',
            'encrypted'    => true,
        ]);

        $challenge = BillSecretChallenge::query()->create([
            'bill_task_id' => $this->task->id,
            'kind'         => 'password',
            'prompt'       => '请输入账单解压密码',
            'status'       => 'open',
            'attempts'     => 0,
        ]);

        $this->task->current_secret_challenge_id = $challenge->id;
        $this->task->save();

        BillTaskEvent::query()->create([
            'bill_task_id' => $this->task->id,
            'event_type'   => 'task.created',
            'message'      => '任务已创建',
        ]);
    }
}

final class FakeBillInboxImapMessage
{
    public function __construct(
        public readonly string $uid,
        public readonly string $raw,
    ) {}
}

final class FakeBillInboxImapClient implements ImapBillMailboxClient
{
    /** @var array<int, FakeBillInboxImapMessage> */
    private array $messages;

    /**
     * @param array<int, FakeBillInboxImapMessage> $messages
     */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function close(): void {}

    public function connect(BillMailboxConfig $config): void {}

    public function fetchRawMessage(string $uid): string
    {
        foreach ($this->messages as $message) {
            if ($message->uid === $uid) {
                return $message->raw;
            }
        }

        return '';
    }

    public function markSeen(string $uid): void {}

    public function search(string $criteria, int $limit): array
    {
        return array_slice(array_map(static fn (FakeBillInboxImapMessage $message): string => $message->uid, $this->messages), 0, $limit);
    }

    public function selectFolder(string $folder): void {}
}

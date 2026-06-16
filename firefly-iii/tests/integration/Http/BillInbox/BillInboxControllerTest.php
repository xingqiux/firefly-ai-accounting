<?php

declare(strict_types=1);

namespace Tests\integration\Http\BillInbox;

use Carbon\Carbon;
use FireflyIII\Http\Middleware\InterestingMessage;
use FireflyIII\Http\Middleware\Range;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillSecretChallenge;
use FireflyIII\Models\BillStatementImport;
use FireflyIII\Models\BillStatementRow;
use FireflyIII\Models\BillTask;
use FireflyIII\Models\BillTaskEvent;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Services\BillIngestion\BillMailboxConfig;
use FireflyIII\Services\BillIngestion\ImapBillMailboxClient;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Support\Facades\Storage;
use Override;
use PragmaRX\Google2FALaravel\Middleware as MFAMiddleware;
use Tests\integration\TestCase;
use ZipArchive;

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
        $response->assertSee('需要验证码');
        $response->assertSee('归档');
        $response->assertDontSeeText('needs_secret');
        $response->assertDontSee('立即同步');
        $response->assertDontSee('这里显示从邮箱识别出的账单任务');
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
        $response->assertSee('INBOX');
        $response->assertSee('支付宝交易流水');
        $response->assertSee('微信支付账单流水');
        $response->assertDontSee('buii');
        $response->assertDontSee('账单, statement');
        $response->assertSee('应用密码已保存');
    }

    public function testIndexDoesNotSyncMailboxUntilUserClicksSync(): void
    {
        $client = new FakeBillInboxImapClient([
            new FakeBillInboxImapMessage('42', $this->alipayRawMessage()),
        ]);
        $this->app->instance(ImapBillMailboxClient::class, $client);
        $this->actingAs($this->user);
        $this->configureAlipayMailbox();

        $response = $this->get(route('bill-inbox.index'));

        $response->assertStatus(200);
        $response->assertSee('立即同步');
        $response->assertDontSee('自动同步：');
        $this->assertFalse($client->connected);
        $this->assertSame([], $client->searches);
        $this->assertSame(0, BillTask::query()->where('source', 'alipay')->count());
    }

    public function testIndexHidesCleanedTasksByDefaultButKeepsStatusFilter(): void
    {
        $cleanedTask = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'cleaned',
            'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'     => '已清理支付宝交易流水',
        ]);

        $response = $this->actingAs($this->user)->get(route('bill-inbox.index'));

        $response->assertStatus(200);
        $response->assertDontSee('已清理支付宝交易流水');
        $response->assertSee('已归档');
        $response->assertDontSeeText('cleaned');

        $filtered = $this->get(route('bill-inbox.index', ['status' => 'cleaned']));

        $filtered->assertStatus(200);
        $filtered->assertSee('已清理支付宝交易流水');
        $filtered->assertSee('已归档');
        $filtered->assertSee('#'.$cleanedTask->id);
        $filtered->assertDontSeeText('cleaned');
    }

    public function testShowDisplaysTaskDetailAndSecretForm(): void
    {
        $response = $this->actingAs($this->user)->get(route('bill-inbox.show', [$this->task->id]));

        $response->assertStatus(200);
        $response->assertSee('账单任务 #'.$this->task->id);
        $response->assertSee('需要密码或验证码');
        $response->assertSee('statement.zip');
        $response->assertSee('下载');
        $response->assertSee('流水明细');
        $response->assertSee('处理记录');
        $response->assertSee('创建任务');
        $response->assertDontSee('task.created');
        $response->assertDontSee('重新排队');
        $response->assertDontSee('忽略任务');
        $response->assertDontSee('加密');
        $response->assertDontSee('Message-ID');
        $response->assertDontSee('原始邮件');
        $response->assertDontSee('提交后系统只记录挑战已处理');
        $response->assertDontSee('明文密码');
    }

    public function testShowDisplaysShortArtifactNames(): void
    {
        $task = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'wechat',
            'profile_id'  => 'wechat-pay-statement',
            'status'      => 'parsed',
            'received_at' => Carbon::parse('2026-06-15 19:14:00', 'Asia/Shanghai'),
            'summary'     => '微信支付账单流水',
        ]);
        BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '微信支付账单流水文件(20260515-20260615)——【解压密码可在微信支付公众号查看】.zip',
            'path'         => 'bill-inbox/1/remote/wechat-long.zip',
            'checksum'     => 'wechat-zip-checksum',
            'encrypted'    => true,
            'metadata'     => ['source' => 'remote_download'],
        ]);
        BillArtifact::query()->create([
            'bill_task_id'             => $task->id,
            'derived_from_artifact_id' => 1,
            'kind'                     => 'xlsx',
            'filename'                 => '微信支付账单流水文件(20260515-20260615)——【解压密码可在微信支付公众号查看】.xlsx',
            'path'                     => 'bill-inbox/1/derived/wechat-long.xlsx',
            'checksum'                 => 'wechat-xlsx-checksum',
            'encrypted'                => false,
            'metadata'                 => ['source' => 'wechat_zip_extract'],
        ]);

        $response = $this->actingAs($this->user)->get(route('bill-inbox.show', [$task->id]));

        $response->assertStatus(200);
        $response->assertSee('原始压缩包');
        $response->assertSee('账单明细');
        $response->assertDontSee('微信支付账单流水文件(20260515-20260615)——【解压密码可在微信支付公众号查看】');
    }

    public function testShowDisplaysStatementRowsAndSavesEdits(): void
    {
        $row = $this->createStatementRow($this->task);

        $response = $this->actingAs($this->user)->get(route('bill-inbox.show', [$this->task->id]));

        $response->assertStatus(200);
        $response->assertSee('流水明细');
        $response->assertSee('中国联通');
        $response->assertSee('招商银行储蓄卡(8705)');
        $response->assertSee('存入');
        $response->assertSee('筛选');
        $response->assertSee('批量存入');
        $response->assertSee('name="row_ids[]"', false);

        $filtered = $this->get(route('bill-inbox.show', [
            'billTask'    => $this->task->id,
            'row_status'  => 'pending',
            'row_from'    => '2026-06-15',
            'row_to'      => '2026-06-15',
        ]));

        $filtered->assertStatus(200);
        $filtered->assertSee('中国联通');

        $post = $this->post(route('bill-inbox.row.update', [$row->id]), [
            'counterparty'        => '中国联通线上营业厅',
            'description'         => '手机充值',
            'amount'              => '20.00',
            'firefly_amount'      => '20.00',
            'firefly_description' => '手机充值',
            'source_name'         => '招商银行',
            'destination_name'    => '中国联通',
        ]);

        $post->assertRedirect(route('bill-inbox.show', [$this->task->id]));

        $row->refresh();
        $this->assertSame('中国联通线上营业厅', $row->counterparty);
        $this->assertSame('中国联通线上营业厅', $row->editable_data['交易对方']);
        $this->assertSame('20', (string) $row->amount);
        $this->assertSame('手机充值', $row->firefly_description);
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

    public function testAlipaySecretSubmitExtractsStatementAndMarksTaskParsed(): void
    {
        Storage::fake('local');
        $mail = BillMailMessage::query()->create([
            'user_id'      => $this->user->id,
            'message_id'   => '<alipay-web-1@mail.alipay.com>',
            'mailbox'      => 'ziyufg@gmail.com',
            'from_address' => 'service@mail.alipay.com',
            'to_address'   => 'ziyufg@gmail.com',
            'subject'      => '李昶乐的支付宝交易流水明细',
            'received_at'  => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'raw_path'     => 'mail/raw/alipay-web-1.eml',
            'checksum'     => 'alipay-mail-checksum',
            'sync_cursor'  => 'gmail:42',
        ]);
        $task = BillTask::query()->create([
            'user_id'              => $this->user->id,
            'bill_mail_message_id' => $mail->id,
            'source'               => 'alipay',
            'profile_id'           => 'alipay-statement',
            'status'               => 'needs_secret',
            'received_at'          => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'              => '支付宝交易流水明细',
        ]);
        $zipPath = 'bill-inbox/1/20260613162053210/attachments/01-支付宝交易明细(20260601-20260612).zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret'));
        BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260601-20260612).zip',
            'path'         => $zipPath,
            'checksum'     => 'zip-checksum',
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'alipay_service_message'],
        ]);
        $challenge = BillSecretChallenge::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'password',
            'prompt'       => '请输入支付宝服务消息中的账单解压密码',
            'status'       => 'open',
            'attempts'     => 0,
        ]);
        $task->current_secret_challenge_id = $challenge->id;
        $task->save();

        $response = $this->actingAs($this->user)->post(route('bill-inbox.secret', [$task->id]), [
            'value' => 'zip-secret',
        ]);

        $response->assertRedirect(route('bill-inbox.show', [$task->id]));

        $task->refresh();
        $this->assertSame('parsed', $task->status);
        $this->assertNull($task->current_secret_challenge_id);
        $this->assertSame('consumed', $task->secretChallenges()->first()->status);
        $this->assertSame(1, $task->artifacts()->where('kind', 'csv')->count());
        $this->assertSame(1, $task->statementImports()->count());
        $this->assertSame(1, $task->statementRows()->count());
        $this->assertSame('alipay-202606151853-20260515_20260615.csv', $task->statementImports()->first()->archived_filename);
        $this->assertSame('task.parsed', $task->events()->latest('id')->first()->event_type);
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
        $response->assertSee('Gmail 地址');
        $response->assertSee('应用密码');
        $response->assertSee('内置渠道');
        $response->assertSee('支付宝交易流水');
        $response->assertSee('微信支付账单流水');
        $response->assertSee('Gmail');
        $response->assertSee('高级设置');
        $response->assertDontSee('只处理这些邮件');
        $response->assertDontSee('关键词');
        $response->assertDontSee('来源标识');
        $response->assertDontSee('附件类型');
        $response->assertDontSee('这个邮箱只用于接收账单邮件');
    }

    public function testSettingsSaveGmailConfigurationWithBuiltInAlipayChannel(): void
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
        $this->assertCount(3, $rules);
        $this->assertSame('支付宝交易流水', $rules[0]['name']);
        $this->assertSame('alipay', $rules[0]['source']);
        $this->assertSame('service@mail.alipay.com', $rules[0]['from_contains']);
        $this->assertSame('支付宝交易流水明细', $rules[0]['subject_contains']);
        $this->assertSame('', $rules[0]['gmail_label']);
        $this->assertSame(['支付宝', '交易流水'], $rules[0]['keywords']);
        $this->assertTrue($rules[0]['built_in']);
        $this->assertTrue($rules[0]['enabled']);
        $this->assertSame('微信支付账单流水', $rules[1]['name']);
        $this->assertSame('wechat', $rules[1]['source']);
        $this->assertSame('wechatpay@tencent.com', $rules[1]['from_contains']);
        $this->assertSame('微信支付账单流水文件', $rules[1]['subject_contains']);
        $this->assertSame(['微信支付', '账单流水'], $rules[1]['keywords']);
        $this->assertTrue($rules[1]['built_in']);
        $this->assertTrue($rules[1]['enabled']);
        $this->assertSame('招商银行交易流水', $rules[2]['name']);
        $this->assertSame('cmb', $rules[2]['source']);
        $this->assertSame('95555@message.cmbchina.com', $rules[2]['from_contains']);
        $this->assertSame('招商银行交易流水', $rules[2]['subject_contains']);
        $this->assertSame(['招商银行', '交易流水'], $rules[2]['keywords']);
        $this->assertTrue($rules[2]['built_in']);
        $this->assertTrue($rules[2]['enabled']);
        $this->assertSame('', Preferences::get('bill_inbox_quick_gmail_label')->data);
        $this->assertSame('', Preferences::get('bill_inbox_quick_keywords')->data);
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

    public function testPostSyncIgnoresConfiguredGmailLabelAndCreatesAlipayTask(): void
    {
        Storage::fake('local');
        $this->app->instance(ImapBillMailboxClient::class, new FakeBillInboxImapClient([
            new FakeBillInboxImapMessage('42', $this->alipayRawMessage()),
        ], ['buii']));
        $this->actingAs($this->user);
        $this->configureAlipayMailbox('buii');

        $response = $this->post(route('bill-inbox.sync'));

        $response->assertRedirect(route('bill-inbox.index'));
        $response->assertSessionMissing('error');
        $this->assertSame(1, BillTask::query()->where('source', 'alipay')->count());
    }

    public function testCleanupStaleTasksArchivesOnlyCurrentUsersPendingSecretTasksWithoutDeletingFiles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/original/task-1/statement.zip', 'zip bytes');
        Storage::disk('local')->put('mail/raw/mail-web-1.eml', 'raw mail');

        $parsedTask = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'parsed',
            'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'     => '已解析账单',
        ]);
        BillArtifact::query()->create([
            'bill_task_id' => $parsedTask->id,
            'kind'         => 'csv',
            'filename'     => 'parsed.csv',
            'path'         => 'artifacts/derived/task-parsed/parsed.csv',
            'checksum'     => 'parsed-checksum',
        ]);
        Storage::disk('local')->put('artifacts/derived/task-parsed/parsed.csv', 'csv bytes');

        $otherUser = $this->createUser('other-bill-inbox@example.com');
        $otherTask = BillTask::query()->create([
            'user_id'     => $otherUser->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'needs_secret',
            'received_at' => Carbon::parse('2026-06-13 08:00:00', 'Asia/Shanghai'),
            'summary'     => '其他用户待处理账单',
        ]);

        $response = $this->actingAs($this->user)->post(route('bill-inbox.cleanup-stale'));

        $response->assertRedirect(route('bill-inbox.index'));
        $response->assertSessionHas('success', '已归档 1 个过时账单任务。');

        $this->assertDatabaseHas('bill_tasks', [
            'id'       => $this->task->id,
            'status'   => 'cleaned',
        ]);
        $this->assertDatabaseHas('bill_mail_messages', [
            'message_id' => '<mail-web-1@example.com>',
            'raw_path'   => 'mail/raw/mail-web-1.eml',
        ]);
        $this->assertDatabaseHas('bill_artifacts', ['bill_task_id' => $this->task->id]);
        $this->assertDatabaseHas('bill_secret_challenges', ['bill_task_id' => $this->task->id]);
        $this->assertDatabaseHas('bill_task_events', [
            'bill_task_id' => $this->task->id,
            'event_type'   => 'task.archived',
        ]);
        $this->assertDatabaseHas('bill_tasks', ['id' => $parsedTask->id, 'status' => 'parsed']);
        $this->assertDatabaseHas('bill_tasks', ['id' => $otherTask->id, 'status' => 'needs_secret']);
        Storage::disk('local')->assertExists('artifacts/original/task-1/statement.zip');
        Storage::disk('local')->assertExists('mail/raw/mail-web-1.eml');
        Storage::disk('local')->assertExists('artifacts/derived/task-parsed/parsed.csv');
    }

    public function testSingleAndBatchArchiveHideTasksWithoutDeletingFiles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/original/task-1/statement.zip', 'zip bytes');
        Storage::disk('local')->put('mail/raw/mail-web-1.eml', 'raw mail');

        $secondTask = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'parsed',
            'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'     => '支付宝交易流水',
        ]);
        BillArtifact::query()->create([
            'bill_task_id' => $secondTask->id,
            'kind'         => 'csv',
            'filename'     => 'alipay.csv',
            'path'         => 'artifacts/derived/task-2/alipay.csv',
            'checksum'     => 'alipay-checksum',
        ]);
        Storage::disk('local')->put('artifacts/derived/task-2/alipay.csv', 'csv bytes');

        $single = $this->actingAs($this->user)->post(route('bill-inbox.archive', [$this->task->id]));

        $single->assertRedirect(route('bill-inbox.index'));
        $this->task->refresh();
        $this->assertSame('cleaned', $this->task->status);
        Storage::disk('local')->assertExists('artifacts/original/task-1/statement.zip');
        Storage::disk('local')->assertExists('mail/raw/mail-web-1.eml');

        $batch = $this->post(route('bill-inbox.archive-many'), [
            'task_ids' => [$secondTask->id],
        ]);

        $batch->assertRedirect(route('bill-inbox.index'));
        $secondTask->refresh();
        $this->assertSame('cleaned', $secondTask->status);
        Storage::disk('local')->assertExists('artifacts/derived/task-2/alipay.csv');
    }

    public function testSettingsSaveIgnoresCustomProcessingRulesForBuiltInAlipayChannel(): void
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
        $this->assertCount(3, $rules);
        $this->assertSame('支付宝交易流水', $rules[0]['name']);
        $this->assertSame('alipay', $rules[0]['source']);
        $this->assertSame('service@mail.alipay.com', $rules[0]['from_contains']);
        $this->assertSame('支付宝交易流水明细', $rules[0]['subject_contains']);
        $this->assertSame(['zip'], $rules[0]['attachment_extensions']);
        $this->assertSame('', $rules[0]['gmail_label']);
        $this->assertTrue($rules[0]['built_in']);
        $this->assertTrue($rules[0]['enabled']);
        $this->assertSame('微信支付账单流水', $rules[1]['name']);
        $this->assertSame('wechat', $rules[1]['source']);
        $this->assertSame('wechatpay@tencent.com', $rules[1]['from_contains']);
        $this->assertSame('微信支付账单流水文件', $rules[1]['subject_contains']);
        $this->assertSame(['zip'], $rules[1]['attachment_extensions']);
        $this->assertSame('', $rules[1]['gmail_label']);
        $this->assertTrue($rules[1]['built_in']);
        $this->assertTrue($rules[1]['enabled']);
        $this->assertSame('招商银行交易流水', $rules[2]['name']);
        $this->assertSame('cmb', $rules[2]['source']);
        $this->assertSame('95555@message.cmbchina.com', $rules[2]['from_contains']);
        $this->assertSame('招商银行交易流水', $rules[2]['subject_contains']);
        $this->assertSame(['zip'], $rules[2]['attachment_extensions']);
        $this->assertSame('', $rules[2]['gmail_label']);
        $this->assertTrue($rules[2]['built_in']);
        $this->assertTrue($rules[2]['enabled']);
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

    private function configureAlipayMailbox(string $quickLabel = ''): void
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

    private function encryptedZipBytes(string $password): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alipay-statement-');
        if (false === $path) {
            throw new \RuntimeException('Could not create temporary zip file.');
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open temporary zip file.');
        }

        $zip->setPassword($password);
        $zip->addFromString('alipay-records.csv', $this->alipayStatementCsv());
        $zip->setEncryptionName('alipay-records.csv', ZipArchive::EM_AES_256, $password);
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        if (false === $bytes) {
            throw new \RuntimeException('Could not read temporary zip file.');
        }

        return $bytes;
    }

    private function alipayStatementCsv(): string
    {
        return implode("\n", [
            '支付宝交易流水明细',
            '导出时间：[2026-06-15 18:53:58]',
            '起始时间：[2026-05-15 00:00:00] 终止时间：[2026-06-15 23:59:59]',
            '交易时间,交易分类,交易对方,对方账号,商品说明,收/支,金额,收/付款方式,交易状态,交易订单号,商家订单号,备注',
            '2026-06-15 17:20:33,充值缴费,中国联通,ah-***@chinaunicom.cn,为155****2328交费20.00元,支出,14.95,招商银行储蓄卡(8705),交易成功,2026061522001414871443694067,CP0232671781515214344949,',
        ]);
    }

    private function createUser(string $email): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);

        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    private function createStatementRow(BillTask $task): BillStatementRow
    {
        /** @var BillArtifact $artifact */
        $artifact = $task->artifacts()->first();
        $import   = BillStatementImport::query()->create([
            'user_id'           => $this->user->id,
            'bill_task_id'      => $task->id,
            'bill_artifact_id'  => $artifact->id,
            'source'            => 'alipay',
            'profile_id'        => 'alipay-statement',
            'original_filename' => '支付宝交易明细(20260515-20260615).csv',
            'archived_filename' => 'alipay-202606151853-20260515_20260615.csv',
            'exported_at'       => Carbon::parse('2026-06-15 18:53:58', 'Asia/Shanghai'),
            'period_start'      => Carbon::parse('2026-05-15', 'Asia/Shanghai'),
            'period_end'        => Carbon::parse('2026-06-15', 'Asia/Shanghai'),
            'row_count'         => 1,
            'status'            => 'parsed',
        ]);

        return BillStatementRow::query()->create([
            'user_id'                  => $this->user->id,
            'bill_task_id'             => $task->id,
            'bill_statement_import_id' => $import->id,
            'row_number'               => 1,
            'status'                   => 'pending',
            'occurred_at'              => Carbon::parse('2026-06-15 17:20:33', 'Asia/Shanghai'),
            'platform_category'        => '充值缴费',
            'counterparty'             => '中国联通',
            'counterparty_account'     => 'ah-***@chinaunicom.cn',
            'description'              => '为155****2328交费20.00元',
            'direction'                => '支出',
            'amount'                   => '14.95',
            'payment_method'           => '招商银行储蓄卡(8705)',
            'transaction_status'       => '交易成功',
            'platform_order_no'        => '2026061522001414871443694067',
            'merchant_order_no'        => 'CP0232671781515214344949',
            'raw_data'                 => ['交易对方' => '中国联通'],
            'editable_data'            => ['交易对方' => '中国联通', '商品说明' => '为155****2328交费20.00元'],
            'firefly_type'             => 'withdrawal',
            'firefly_date'             => Carbon::parse('2026-06-15 17:20:33', 'Asia/Shanghai'),
            'firefly_amount'           => '14.95',
            'firefly_description'      => '为155****2328交费20.00元',
            'source_name'              => '招商银行',
            'destination_name'         => '中国联通',
            'category_name'            => '充值缴费',
            'tags'                     => ['支付宝'],
        ]);
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
    public bool $connected = false;

    /** @var array<int,string> */
    public array $searches = [];

    /** @var array<int, FakeBillInboxImapMessage> */
    private array $messages;

    /** @var array<int, string> */
    private array $missingFolders;

    /**
     * @param array<int, FakeBillInboxImapMessage> $messages
     * @param array<int, string>                   $missingFolders
     */
    public function __construct(array $messages, array $missingFolders = [])
    {
        $this->messages       = $messages;
        $this->missingFolders = $missingFolders;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function connect(BillMailboxConfig $config): void
    {
        $this->connected = true;
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

    public function markSeen(string $uid): void {}

    public function search(string $criteria, int $limit): array
    {
        $this->searches[] = $criteria;

        return array_slice(array_map(static fn (FakeBillInboxImapMessage $message): string => $message->uid, $this->messages), 0, $limit);
    }

    public function selectFolder(string $folder): void
    {
        if (in_array($folder, $this->missingFolders, true)) {
            throw new \RuntimeException(sprintf('IMAP command failed: A0002 NO [NONEXISTENT] Unknown Mailbox: %s (Failure)', $folder));
        }
    }
}

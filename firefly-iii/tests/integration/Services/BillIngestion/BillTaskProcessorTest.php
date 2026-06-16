<?php

declare(strict_types=1);

namespace Tests\integration\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillStatementImport;
use FireflyIII\Models\BillStatementRow;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillTaskProcessor;
use FireflyIII\Services\BillIngestion\CmbStatementImportService;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Override;
use Tests\integration\TestCase;
use ZipArchive;

/**
 * @internal
 *
 * @covers \FireflyIII\Services\BillIngestion\BillTaskProcessor
 */
final class BillTaskProcessorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    public function testProcessBatchRoutesReceivedTasksAndCreatesSecretChallenges(): void
    {
        $encrypted = $this->createTask('received', 'alipay', 'alipay-statement');
        BillArtifact::query()->create([
            'bill_task_id' => $encrypted->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260601-20260612).zip',
            'encrypted'    => true,
        ]);

        $unknown = $this->createTask('received', 'unknown', null);

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(2, $result->processed);
        $this->assertSame(0, $result->failed);

        $encrypted->refresh();
        $this->assertSame('needs_secret', $encrypted->status);
        $this->assertNotNull($encrypted->current_secret_challenge_id);
        $this->assertSame('password', $encrypted->currentSecretChallenge->kind);
        $this->assertSame('challenge.created', $encrypted->events()->latest('id')->first()->event_type);

        $unknown->refresh();
        $this->assertSame('unknown', $unknown->status);
        $this->assertSame('task.unknown', $unknown->events()->latest('id')->first()->event_type);
    }

    public function testReadyTaskFailsWhenNoSourceProcessorIsRegistered(): void
    {
        $task = $this->createTask('ready', 'cmb', 'cmb-credit-card');

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->failed);

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame('processor_missing', $task->error_code);
        $this->assertSame('task.failed', $task->events()->latest('id')->first()->event_type);
    }

    public function testAlipayEncryptedTaskRequestsAlipayServiceMessagePassword(): void
    {
        $task = $this->createTask('received', 'alipay', 'alipay-statement');
        BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260601-20260612).zip',
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'alipay_service_message'],
        ]);

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->failed);

        $task->refresh();
        $this->assertSame('needs_secret', $task->status);
        $this->assertSame('请输入支付宝服务消息中的账单解压密码', $task->currentSecretChallenge->prompt);
    }

    public function testWechatReceivedTaskDownloadsRemoteStatementAndRequestsWechatPassword(): void
    {
        Storage::fake('local');
        Http::fake([
            'tenpay.wechatpay.cn/userroll/userbilldownload/downloadfilefromemail*' => Http::response('wechat encrypted zip bytes', 200, [
                'Content-Type'        => 'application/zip',
                'Content-Disposition' => 'attachment; filename="wechat-statement.zip"',
            ]),
        ]);

        $mail = BillMailMessage::query()->create([
            'user_id'        => $this->user->id,
            'message_id'     => '<wechat-pay-statement-20260615@tencent.com>',
            'mailbox'        => 'ziyufg@gmail.com',
            'from_address'   => 'wechatpay@tencent.com',
            'to_address'     => 'ziyufg@gmail.com',
            'subject'        => '微信支付-账单流水文件(20260515-20260615)',
            'received_at'    => Carbon::parse('2026-06-15 19:14:00', 'Asia/Shanghai'),
            'body_html_path' => 'bill-inbox/1/wechat/message.html',
        ]);
        Storage::disk('local')->put($mail->body_html_path, '<a href="https://tenpay.wechatpay.cn/userroll/userbilldownload/downloadfilefromemail?encrypted_file_data=encrypted-token-123">点击下载</a>');

        $task = BillTask::query()->create([
            'user_id'              => $this->user->id,
            'bill_mail_message_id' => $mail->id,
            'source'               => 'wechat',
            'profile_id'           => 'wechat-pay-statement',
            'status'               => 'received',
            'received_at'          => Carbon::parse('2026-06-15 19:14:00', 'Asia/Shanghai'),
            'summary'              => '微信支付账单流水',
            'metadata'             => [
                'statement_period' => ['start' => '2026-05-15', 'end' => '2026-06-15'],
                'remote_file'      => [
                    'source' => 'tenpay_download',
                    'status' => 'pending',
                ],
            ],
        ]);

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->failed);

        $task->refresh();
        $this->assertSame('needs_secret', $task->status);
        $this->assertSame('请输入微信支付公众号收到的账单解压密码', $task->currentSecretChallenge->prompt);
        $this->assertSame('downloaded', $task->metadata['remote_file']['status']);
        $this->assertArrayNotHasKey('url', $task->metadata['remote_file']);

        $artifact = $task->artifacts()->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('zip', $artifact->kind);
        $this->assertSame('wechat-statement.zip', $artifact->filename);
        $this->assertTrue($artifact->encrypted);
        $this->assertSame('remote_download', $artifact->metadata['source']);
        Storage::disk('local')->assertExists($artifact->path);
        $this->assertSame('wechat encrypted zip bytes', Storage::disk('local')->get($artifact->path));
        Http::assertSentCount(1);
    }

    public function testCmbEncryptedTaskRequestsAppStatementPassword(): void
    {
        $task = $this->createTask('received', 'cmb', 'cmb-transaction-statement');
        BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '招商银行交易流水.zip',
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'cmb_app_statement_record'],
        ]);

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->failed);

        $task->refresh();
        $this->assertSame('needs_secret', $task->status);
        $this->assertSame('请输入招商银行App“流水打印-申请记录”中的账单解压码', $task->currentSecretChallenge->prompt);
    }

    public function testCmbReadyTaskWithSecretExtractsZipWithoutImportRowsYet(): void
    {
        Storage::fake('local');
        $task    = $this->createTask('ready', 'cmb', 'cmb-transaction-statement');
        $zipPath = 'bill-inbox/1/20260616174500000/attachments/01-cmb-statement.zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret', '招商银行交易流水明细', 'cmb-statement.xlsx'));
        $zip     = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '招商银行交易流水.zip',
            'path'         => $zipPath,
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'cmb_app_statement_record'],
        ]);

        $processed = app(BillTaskProcessor::class)->process($task, 'zip-secret');

        $this->assertTrue($processed);

        $task->refresh();
        $this->assertSame('parsed', $task->status);
        $this->assertSame(1, $task->metadata['parsed_artifact_count']);

        $artifact = $task->artifacts()->where('derived_from_artifact_id', $zip->id)->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('xlsx', $artifact->kind);
        $this->assertSame('cmb-statement.xlsx', $artifact->filename);
        $this->assertSame('cmb_zip_extract', $artifact->metadata['source']);
        $this->assertFalse($artifact->encrypted);
        Storage::disk('local')->assertExists($artifact->path);

        $this->assertSame(0, BillStatementImport::query()->count());
        $this->assertSame(0, BillStatementRow::query()->count());
    }

    public function testCmbStatementPdfTextParsesIntoEditableImportRows(): void
    {
        Storage::fake('local');
        $task = $this->createTask('parsed', 'cmb', 'cmb-transaction-statement');
        $path = 'bill-inbox/1/derived/cmb-statement.pdf';
        Storage::disk('local')->put($path, $this->cmbStatementPdfText());
        $artifact = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'pdf',
            'filename'     => '招商银行交易流水.pdf',
            'path'         => $path,
            'encrypted'    => false,
            'metadata'     => ['source' => 'cmb_zip_extract'],
        ]);

        $import = app(CmbStatementImportService::class)->importExtractedText($artifact, $this->cmbStatementPdfText());

        $this->assertSame('cmb', $import->source);
        $this->assertSame('cmb-transaction-statement', $import->profile_id);
        $this->assertSame('2026-06-16 17:44:37', $import->exported_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01', $import->period_start->format('Y-m-d'));
        $this->assertSame('2026-06-14', $import->period_end->format('Y-m-d'));
        $this->assertSame(4, $import->row_count);
        $this->assertSame('cmb-transaction-202606161744-20260601_20260614.pdf', $import->archived_filename);

        $first = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->orderBy('row_number')->first();
        $this->assertInstanceOf(BillStatementRow::class, $first);
        $this->assertSame('2026-06-01 00:00:00', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('快捷支付', $first->platform_category);
        $this->assertSame('上海公共交通卡股份有限公司', $first->counterparty);
        $this->assertSame('支出', $first->direction);
        $this->assertSame('5', (string) $first->amount);
        $this->assertSame('招商银行储蓄卡(8705)', $first->source_name);
        $this->assertSame('上海公共交通卡股份有限公司', $first->destination_name);

        $split = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->orderByDesc('row_number')->first();
        $this->assertInstanceOf(BillStatementRow::class, $split);
        $this->assertSame('拉扎斯网络科技（上海）有限公司', $split->counterparty);
        $this->assertSame('支出', $split->direction);
        $this->assertSame('12.51', (string) $split->amount);

        $income = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->where('direction', '收入')->first();
        $this->assertInstanceOf(BillStatementRow::class, $income);
        $this->assertSame('李昶乐', $income->source_name);
        $this->assertSame('招商银行储蓄卡(8705)', $income->destination_name);

        $artifact->refresh();
        $this->assertSame('cmb-transaction-202606161744-20260601_20260614.pdf', $artifact->filename);
        Storage::disk('local')->assertExists('bill-inbox/'.$task->id.'/derived/cmb-transaction-202606161744-20260601_20260614.pdf');
    }

    public function testAlipayReadyTaskWithoutSecretRequestsPasswordAgain(): void
    {
        $task = $this->createTask('ready', 'alipay', 'alipay-statement');
        BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260601-20260612).zip',
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'alipay_service_message'],
        ]);

        $result = app(BillTaskProcessor::class)->processBatch(10);

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->failed);

        $task->refresh();
        $this->assertSame('needs_secret', $task->status);
        $this->assertNotNull($task->current_secret_challenge_id);
        $this->assertSame('请输入支付宝服务消息中的账单解压密码', $task->currentSecretChallenge->prompt);
    }

    public function testAlipayReadyTaskWithSecretExtractsZipAndMarksTaskParsed(): void
    {
        Storage::fake('local');
        $task    = $this->createTask('ready', 'alipay', 'alipay-statement');
        $zipPath = 'bill-inbox/1/20260613162053210/attachments/01-支付宝交易明细(20260601-20260612).zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret', $this->alipayStatementCsv()));
        $zip     = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260601-20260612).zip',
            'path'         => $zipPath,
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'alipay_service_message'],
        ]);

        $processed = app(BillTaskProcessor::class)->process($task, 'zip-secret');

        $this->assertTrue($processed);

        $task->refresh();
        $this->assertSame('parsed', $task->status);
        $this->assertSame(1, $task->metadata['parsed_artifact_count']);
        $this->assertSame('task.parsed', $task->events()->latest('id')->first()->event_type);

        $artifact = $task->artifacts()->where('derived_from_artifact_id', $zip->id)->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('csv', $artifact->kind);
        $this->assertSame('alipay-202606151853-20260515_20260615.csv', $artifact->filename);
        $this->assertFalse($artifact->encrypted);
        Storage::disk('local')->assertExists($artifact->path);
        $this->assertStringContainsString('交易时间,交易分类,交易对方', mb_convert_encoding(Storage::disk('local')->get($artifact->path), 'UTF-8', 'GB18030'));
    }

    public function testAlipayReadyTaskParsesStatementIntoEditableImportRows(): void
    {
        Storage::fake('local');
        $task    = $this->createTask('ready', 'alipay', 'alipay-statement');
        $zipPath = 'bill-inbox/1/20260615185437048/attachments/01-支付宝交易明细(20260515-20260615).zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret', $this->alipayStatementCsv()));
        $zip     = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => '支付宝交易明细(20260515-20260615).zip',
            'path'         => $zipPath,
            'encrypted'    => true,
            'metadata'     => ['password_source' => 'alipay_service_message'],
        ]);

        $processed = app(BillTaskProcessor::class)->process($task, 'zip-secret');

        $this->assertTrue($processed);

        $artifact = $task->artifacts()->where('derived_from_artifact_id', $zip->id)->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('alipay-202606151853-20260515_20260615.csv', $artifact->filename);
        $this->assertStringEndsWith('/alipay-202606151853-20260515_20260615.csv', $artifact->path);
        Storage::disk('local')->assertExists($artifact->path);

        $import = BillStatementImport::query()->where('bill_artifact_id', $artifact->id)->first();
        $this->assertInstanceOf(BillStatementImport::class, $import);
        $this->assertSame($task->id, $import->bill_task_id);
        $this->assertSame('alipay', $import->source);
        $this->assertSame('2026-06-15 18:53:58', $import->exported_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-15', $import->period_start->format('Y-m-d'));
        $this->assertSame('2026-06-15', $import->period_end->format('Y-m-d'));
        $this->assertSame(3, $import->row_count);

        $this->assertSame(3, BillStatementRow::query()->where('bill_statement_import_id', $import->id)->count());

        $first = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->orderBy('row_number')->first();
        $this->assertInstanceOf(BillStatementRow::class, $first);
        $this->assertSame('pending', $first->status);
        $this->assertSame('2026-06-15 17:20:33', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('充值缴费', $first->platform_category);
        $this->assertSame('中国联通', $first->counterparty);
        $this->assertSame('支出', $first->direction);
        $this->assertSame('14.95', (string) $first->amount);
        $this->assertSame('招商银行储蓄卡(8705)&支付宝随机立减', $first->payment_method);
        $this->assertSame('withdrawal', $first->firefly_type);
        $this->assertSame('招商银行', $first->source_name);
        $this->assertSame('中国联通', $first->destination_name);
        $this->assertSame('14.95', (string) $first->firefly_amount);
        $this->assertSame('为155****2328交费20.00元', $first->firefly_description);

        app(BillTaskProcessor::class)->process($task->refresh(), 'zip-secret');
        $this->assertSame(1, BillStatementImport::query()->where('bill_artifact_id', $artifact->id)->count());
        $this->assertSame(3, BillStatementRow::query()->where('bill_statement_import_id', $import->id)->count());
    }

    public function testWechatReadyTaskWithSecretExtractsStatementIntoEditableImportRows(): void
    {
        Storage::fake('local');
        $task    = $this->createTask('ready', 'wechat', 'wechat-pay-statement');
        $zipPath = 'bill-inbox/1/20260615191400000/remote/wechat-pay-statement.zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret', $this->wechatStatementCsv(), 'wechat-pay-records.csv'));
        $zip     = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => 'wechat-pay-statement.zip',
            'path'         => $zipPath,
            'encrypted'    => true,
            'metadata'     => [
                'source'          => 'remote_download',
                'password_source' => 'wechat_pay_official_account',
            ],
        ]);

        $processed = app(BillTaskProcessor::class)->process($task, 'zip-secret');

        $this->assertTrue($processed);

        $task->refresh();
        $this->assertSame('parsed', $task->status);
        $this->assertSame(1, $task->metadata['parsed_artifact_count']);

        $artifact = $task->artifacts()->where('derived_from_artifact_id', $zip->id)->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('csv', $artifact->kind);
        $this->assertSame('wechat-pay-202606151914-20260515_20260615.csv', $artifact->filename);
        Storage::disk('local')->assertExists($artifact->path);

        $import = BillStatementImport::query()->where('bill_artifact_id', $artifact->id)->first();
        $this->assertInstanceOf(BillStatementImport::class, $import);
        $this->assertSame('wechat', $import->source);
        $this->assertSame('wechat-pay-statement', $import->profile_id);
        $this->assertSame('2026-06-15 19:14:00', $import->exported_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-15', $import->period_start->format('Y-m-d'));
        $this->assertSame('2026-06-15', $import->period_end->format('Y-m-d'));
        $this->assertSame(2, $import->row_count);

        $first = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->orderBy('row_number')->first();
        $this->assertInstanceOf(BillStatementRow::class, $first);
        $this->assertSame('2026-06-15 18:00:00', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('餐饮美食', $first->platform_category);
        $this->assertSame('霸王茶姬', $first->counterparty);
        $this->assertSame('支出', $first->direction);
        $this->assertSame('16', (string) $first->amount);
        $this->assertSame('招商银行储蓄卡(8705)', $first->payment_method);
        $this->assertSame('withdrawal', $first->firefly_type);
        $this->assertSame('招商银行', $first->source_name);
        $this->assertSame('霸王茶姬', $first->destination_name);
        $this->assertSame('微信支付交易单号：420000000000000001', $first->notes);
    }

    public function testWechatReadyTaskWithSecretExtractsXlsxStatementIntoEditableImportRows(): void
    {
        Storage::fake('local');
        $task    = $this->createTask('ready', 'wechat', 'wechat-pay-statement');
        $zipPath = 'bill-inbox/1/20260615191400000/remote/wechat-pay-statement.zip';
        Storage::disk('local')->put($zipPath, $this->encryptedZipBytes('zip-secret', $this->wechatStatementXlsx(), 'wechat-pay-records.xlsx'));
        $zip     = BillArtifact::query()->create([
            'bill_task_id' => $task->id,
            'kind'         => 'zip',
            'filename'     => 'wechat-pay-statement.zip',
            'path'         => $zipPath,
            'encrypted'    => true,
            'metadata'     => [
                'source'          => 'remote_download',
                'password_source' => 'wechat_pay_official_account',
            ],
        ]);

        $processed = app(BillTaskProcessor::class)->process($task, 'zip-secret');

        $this->assertTrue($processed);

        $task->refresh();
        $this->assertSame('parsed', $task->status);
        $this->assertSame(1, $task->metadata['parsed_artifact_count']);

        $artifact = $task->artifacts()->where('derived_from_artifact_id', $zip->id)->first();
        $this->assertInstanceOf(BillArtifact::class, $artifact);
        $this->assertSame('xlsx', $artifact->kind);
        $this->assertSame('wechat-pay-202606151914-20260515_20260615.xlsx', $artifact->filename);

        $import = BillStatementImport::query()->where('bill_artifact_id', $artifact->id)->first();
        $this->assertInstanceOf(BillStatementImport::class, $import);
        $this->assertSame('wechat', $import->source);
        $this->assertSame('wechat-pay-statement', $import->profile_id);
        $this->assertSame('2026-06-15 19:14:00', $import->exported_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-15', $import->period_start->format('Y-m-d'));
        $this->assertSame('2026-06-15', $import->period_end->format('Y-m-d'));
        $this->assertSame(2, $import->row_count);

        $first = BillStatementRow::query()->where('bill_statement_import_id', $import->id)->orderBy('row_number')->first();
        $this->assertInstanceOf(BillStatementRow::class, $first);
        $this->assertSame('2026-06-15 18:00:00', $first->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('餐饮美食', $first->platform_category);
        $this->assertSame('霸王茶姬', $first->counterparty);
        $this->assertSame('支出', $first->direction);
        $this->assertSame('16.8', (string) $first->amount);
        $this->assertSame('招商银行储蓄卡(8705)', $first->payment_method);
        $this->assertSame('withdrawal', $first->firefly_type);
        $this->assertSame('招商银行', $first->source_name);
        $this->assertSame('霸王茶姬', $first->destination_name);
    }

    public function testArtisanCommandRunsProcessorInFireflyBackend(): void
    {
        $this->createTask('received', 'unknown', null);
        $this->createTask('received', 'unknown', null);

        $this->artisan('firefly-iii:process-bill-tasks', ['--limit' => 1])
            ->expectsOutputToContain('Processed 1 bill task')
            ->assertExitCode(0)
        ;

        $this->assertSame(1, BillTask::query()->where('status', 'unknown')->count());
        $this->assertSame(1, BillTask::query()->where('status', 'received')->count());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();
    }

    private function createTask(string $status, string $source, ?string $profileId): BillTask
    {
        return BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => $source,
            'profile_id'  => $profileId,
            'status'      => $status,
            'received_at' => Carbon::parse('2026-06-10 09:30:00', 'Asia/Shanghai'),
            'summary'     => sprintf('%s task', $source),
        ]);
    }

    private function encryptedZipBytes(string $password, ?string $csv = null, string $filename = 'alipay-records.csv'): string
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
        $zip->addFromString($filename, $csv ?? "交易时间,交易分类,交易对方,金额\n2026-06-12 10:00,餐饮,测试商户,-12.30\n");
        $zip->setEncryptionName($filename, ZipArchive::EM_AES_256, $password);
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
        return mb_convert_encoding(<<<'CSV'
------------------------------------------------------------------------------------
导出信息：
姓名：李昶乐
支付宝账户：15556952328
起始时间：[2026-05-15 00:00:00]    终止时间：[2026-06-15 23:59:59]
导出交易类型：[全部]
导出时间：[2026-06-15 18:53:58]
共3笔记录

特别提示：
1.本明细仅供个人对账使用。

------------------------支付宝支付科技有限公司  电子客户回单------------------------
交易时间,交易分类,交易对方,对方账号,商品说明,收/支,金额,收/付款方式,交易状态,交易订单号,商家订单号,备注
2026-06-15 17:20:33,充值缴费,中国联通,ah-***@chinaunicom.cn,为155****2328交费20.00元,支出,14.95,招商银行储蓄卡(8705)&支付宝随机立减,交易成功,2026061522001414871443694067,CP0232671781515214344949,
2026-06-15 10:22:14,信用借还,花呗,/,花呗主动还款-2026年07月账单,不计收支,123.00,招商银行储蓄卡(8705),还款成功,2026061529020999870179346714,,
2026-06-15 09:30:52,日用百货,安徽邻几（肥西亚坤大厦店）,209***@qq.com,11400肥西亚坤大厦店,支出,3.32,花呗&花呗青春特惠,交易成功,2026061523001414871431914548,11400A260615093044,
CSV, 'GB18030', 'UTF-8');
    }

    private function wechatStatementCsv(): string
    {
        return mb_convert_encoding(<<<'CSV'
微信支付账单明细
微信昵称：[x******ux]
起始时间：[2026-05-15 00:00:00]    终止时间：[2026-06-15 23:59:59]
导出类型：[全部]
导出时间：[2026-06-15 19:14:00]
共2笔记录

交易时间,交易类型,交易对方,商品,收/支,金额(元),支付方式,当前状态,交易单号,商户单号,备注
2026-06-15 18:00:00,餐饮美食,霸王茶姬,轻乳茶,支出,16.00,招商银行储蓄卡(8705),支付成功,420000000000000001,merchant-1,
2026-06-15 08:30:00,转账红包,李四,微信转账,收入,50.00,零钱,已收钱,420000000000000002,merchant-2,
CSV, 'UTF-8', 'UTF-8');
    }

    private function cmbStatementPdfText(): string
    {
        return <<<'TEXT'
                                                 招商银行交易流水
                                   Transaction Statement of China Merchants Bank
                                             2026-06-01 -- 2026-06-14


户      名：李昶乐                                        账号：6214********8705
Name                                                Account No
申请时间：2026-06-16 17:44:37                            验 证 码：EF92MHFC
Date                                                Verification Code

记账日期           货币         交易金额              联机余额             交易摘要                  对手信息
                          Transaction
Date           Currency                     Balance          Transaction Type      Counter Party
                          Amount
2026-06-01     CNY        -5.00             132.24           快捷支付                  上海公共交通卡股份有限公司

2026-06-02     CNY        100.00            124.34           网联收款                  李昶乐

                                                                                   拉扎斯网络科技（上海）有限公
2026-06-04     CNY        -15.59            66.75            快捷支付
                                                                                   司

                                                                   拉扎斯网络科技（上海）有限公
2026-06-04   CNY        -12.51        5.94      快捷支付
                                                                   司

TEXT;
    }

    private function wechatStatementXlsx(): string
    {
        $sharedStrings = [
            '微信支付账单明细',
            '微信昵称：[x******ux]',
            '起始时间：[2026-05-15 00:00:00] 终止时间：[2026-06-15 23:59:59]',
            '导出类型：[全部]',
            '导出时间：[2026-06-15 19:14:00]',
            '共2笔记录',
            '收入：1笔 50.00元',
            '支出：1笔 16.80元',
            '中性交易：0笔 0.00元',
            '注：',
            '1. 本明细仅供个人对账使用',
            '----------------------微信支付账单明细列表--------------------',
            '交易时间',
            '交易类型',
            '交易对方',
            '商品',
            '收/支',
            '金额(元)',
            '支付方式',
            '当前状态',
            '交易单号',
            '商户单号',
            '备注',
            '餐饮美食',
            '霸王茶姬',
            '轻乳茶',
            '支出',
            '招商银行储蓄卡(8705)',
            '支付成功',
            '420000000000000001',
            'merchant-1',
            '/',
            '转账红包',
            '李四',
            '微信转账',
            '收入',
            '零钱',
            '已收钱',
            '420000000000000002',
            'merchant-2',
        ];

        $sheetRows = [];
        for ($row = 1; $row <= 11; ++$row) {
            $sheetRows[] = sprintf('<row r="%d"><c r="A%d" t="s"><v>%d</v></c></row>', $row, $row, $row - 1);
        }
        $sheetRows[] = '<row r="13"><c r="A13" t="s"><v>11</v></c></row>';
        $sheetRows[] = '<row r="14">'
            .'<c r="A14" t="s"><v>12</v></c><c r="B14" t="s"><v>13</v></c><c r="C14" t="s"><v>14</v></c>'
            .'<c r="D14" t="s"><v>15</v></c><c r="E14" t="s"><v>16</v></c><c r="F14" t="s"><v>17</v></c>'
            .'<c r="G14" t="s"><v>18</v></c><c r="H14" t="s"><v>19</v></c><c r="I14" t="s"><v>20</v></c>'
            .'<c r="J14" t="s"><v>21</v></c><c r="K14" t="s"><v>22</v></c></row>';
        $sheetRows[] = '<row r="15">'
            .'<c r="A15"><v>46188.75</v></c><c r="B15" t="s"><v>23</v></c><c r="C15" t="s"><v>24</v></c>'
            .'<c r="D15" t="s"><v>25</v></c><c r="E15" t="s"><v>26</v></c><c r="F15"><v>16.8</v></c>'
            .'<c r="G15" t="s"><v>27</v></c><c r="H15" t="s"><v>28</v></c><c r="I15" t="s"><v>29</v></c>'
            .'<c r="J15" t="s"><v>30</v></c><c r="K15" t="s"><v>31</v></c></row>';
        $sheetRows[] = '<row r="16">'
            .'<c r="A16"><v>46188.354166666664</v></c><c r="B16" t="s"><v>32</v></c><c r="C16" t="s"><v>33</v></c>'
            .'<c r="D16" t="s"><v>34</v></c><c r="E16" t="s"><v>35</v></c><c r="F16"><v>50</v></c>'
            .'<c r="G16" t="s"><v>36</v></c><c r="H16" t="s"><v>37</v></c><c r="I16" t="s"><v>38</v></c>'
            .'<c r="J16" t="s"><v>39</v></c><c r="K16" t="s"><v>31</v></c></row>';

        $files = [
            '[Content_Types].xml'      => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
            '_rels/.rels'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/_rels/workbook.xml.rels'=> '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/workbook.xml'          => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/sharedStrings.xml'     => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">'.implode('', array_map(static fn (string $value): string => '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>', $sharedStrings)).'</sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:K16"/><sheetData>'.implode('', $sheetRows).'</sheetData></worksheet>',
        ];

        $path = tempnam(sys_get_temp_dir(), 'wechat-statement-xlsx-');
        if (false === $path) {
            throw new \RuntimeException('Could not create temporary xlsx file.');
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open temporary xlsx file.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);

        if (false === $bytes) {
            throw new \RuntimeException('Could not read temporary xlsx file.');
        }

        return $bytes;
    }
}

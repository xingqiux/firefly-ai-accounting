<?php

declare(strict_types=1);

namespace Tests\integration\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillStatementImport;
use FireflyIII\Models\BillStatementRow;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillTaskProcessor;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function encryptedZipBytes(string $password, ?string $csv = null): string
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
        $zip->addFromString('alipay-records.csv', $csv ?? "交易时间,交易分类,交易对方,金额\n2026-06-12 10:00,餐饮,测试商户,-12.30\n");
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
}

<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\BillTask;

use Carbon\Carbon;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillSecretChallenge;
use FireflyIII\Models\BillStatementImport;
use FireflyIII\Models\BillStatementRow;
use FireflyIII\Models\BillTask;
use FireflyIII\Models\BillTaskEvent;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Override;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Api\V1\Controllers\Models\BillTask\ActionController
 * @covers \FireflyIII\Api\V1\Controllers\Models\BillTask\ListController
 * @covers \FireflyIII\Api\V1\Controllers\Models\BillTask\ShowController
 */
final class BillTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BillTask $task;

    public function testIndexListsCurrentUsersBillTasks(): void
    {
        $otherUser = $this->createUser('other-bills@example.com');
        BillTask::query()->create([
            'user_id'       => $otherUser->id,
            'source'        => 'alipay',
            'status'        => 'received',
            'received_at'   => Carbon::parse('2026-06-10 11:00:00', 'Asia/Shanghai'),
            'summary'       => '其他用户账单',
        ]);

        $this->actingAs($this->user, 'api');
        $response = $this->getJson(route('api.v1.bill-tasks.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.id', (string) $this->task->id);
        $response->assertJsonPath('data.0.attributes.source', 'cmb');
        $response->assertJsonPath('data.0.attributes.status', 'needs_secret');
    }

    public function testIndexFiltersBillTasksBySourceAndStatus(): void
    {
        $alipay = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'parsed',
            'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'     => '支付宝交易流水',
        ]);
        BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'needs_secret',
            'received_at' => Carbon::parse('2026-06-11 18:26:00', 'Asia/Shanghai'),
            'summary'     => '未解析支付宝交易流水',
        ]);

        $this->actingAs($this->user, 'api');
        $response = $this->getJson(route('api.v1.bill-tasks.index', [
            'source' => 'alipay',
            'status' => 'parsed',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.id', (string) $alipay->id);
        $response->assertJsonPath('data.0.attributes.source', 'alipay');
        $response->assertJsonPath('data.0.attributes.status', 'parsed');
    }

    public function testShowIncludesMailArtifactsEventsAndCurrentChallenge(): void
    {
        $this->actingAs($this->user, 'api');
        $response = $this->getJson(route('api.v1.bill-tasks.show', ['billTask' => $this->task->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', (string) $this->task->id);
        $response->assertJsonPath('included.0.type', 'bill-mail-messages');
        $response->assertJsonPath('included.1.type', 'bill-artifacts');
        $response->assertJsonPath('included.2.type', 'bill-secret-challenges');
        $response->assertJsonPath('included.3.type', 'bill-task-events');
    }

    public function testListsArtifactsAndEvents(): void
    {
        $this->actingAs($this->user, 'api');

        $artifacts = $this->getJson(route('api.v1.bill-tasks.artifacts', ['billTask' => $this->task->id]));
        $artifacts->assertStatus(200);
        $artifacts->assertJsonPath('data.0.type', 'bill-artifacts');
        $artifacts->assertJsonPath('data.0.attributes.filename', 'statement.zip');

        $events = $this->getJson(route('api.v1.bill-tasks.events', ['billTask' => $this->task->id]));
        $events->assertStatus(200);
        $events->assertJsonPath('data.0.type', 'bill-task-events');
        $events->assertJsonPath('data.0.attributes.event_type', 'task.created');
    }

    public function testSecretSubmitConsumesChallengeAndMarksTaskReady(): void
    {
        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.secret', ['billTask' => $this->task->id]), [
            'value' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.status', 'ready');

        $this->task->refresh();
        $this->assertSame('ready', $this->task->status);
        $this->assertNull($this->task->current_secret_challenge_id);
        $this->assertSame('consumed', $this->task->secretChallenges()->first()->status);
        $this->assertSame(1, $this->task->secretChallenges()->first()->attempts);
        $this->assertSame('challenge.consumed', $this->task->events()->latest('id')->first()->event_type);
    }

    public function testIgnoreAndRetryMoveTaskState(): void
    {
        $this->actingAs($this->user, 'api');

        $ignored = $this->postJson(route('api.v1.bill-tasks.ignore', ['billTask' => $this->task->id]));
        $ignored->assertStatus(200);
        $ignored->assertJsonPath('data.attributes.status', 'ignored');

        $retried = $this->postJson(route('api.v1.bill-tasks.retry', ['billTask' => $this->task->id]));
        $retried->assertStatus(200);
        $retried->assertJsonPath('data.attributes.status', 'received');

        $this->task->refresh();
        $this->assertSame('received', $this->task->status);
        $this->assertSame('task.retry_requested', $this->task->events()->latest('id')->first()->event_type);
    }

    public function testRejectsBlankSecret(): void
    {
        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.secret', ['billTask' => $this->task->id]), [
            'value' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['value']);
    }

    public function testListsAndUpdatesStatementRows(): void
    {
        $row = $this->createStatementRow();

        $this->actingAs($this->user, 'api');
        $list = $this->getJson(route('api.v1.bill-tasks.rows', ['billTask' => $this->task->id, 'status' => 'pending']));
        $list->assertStatus(200);
        $list->assertJsonPath('data.0.type', 'bill-statement-rows');
        $list->assertJsonPath('data.0.id', (string) $row->id);
        $list->assertJsonPath('data.0.attributes.counterparty', '中国联通');

        $show = $this->getJson(route('api.v1.bill-statement-rows.show', ['billStatementRow' => $row->id]));
        $show->assertStatus(200);
        $show->assertJsonPath('data.id', (string) $row->id);
        $show->assertJsonPath('data.attributes.platform_order_no', '2026061522001414871443694067');

        $updated = $this->patchJson(route('api.v1.bill-statement-rows.update', ['billStatementRow' => $row->id]), [
            'counterparty'        => '中国联通线上营业厅',
            'firefly_description' => '手机充值',
            'source_name'         => '招商银行',
            'destination_name'    => '中国联通',
            'category_name'       => '通讯',
        ]);
        $updated->assertStatus(200);
        $updated->assertJsonPath('data.attributes.counterparty', '中国联通线上营业厅');
        $updated->assertJsonPath('data.attributes.firefly_description', '手机充值');

        $row->refresh();
        $this->assertSame('中国联通线上营业厅', $row->counterparty);
        $this->assertSame('中国联通线上营业厅', $row->editable_data['交易对方']);
        $this->assertSame('手机充值', $row->firefly_description);
    }

    public function testArchivesTaskWithoutDeletingFiles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/original/task-1/statement.zip', 'zip bytes');

        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.archive', ['billTask' => $this->task->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.attributes.status', 'cleaned');

        $this->task->refresh();
        $this->assertSame('cleaned', $this->task->status);
        $this->assertDatabaseHas('bill_artifacts', ['bill_task_id' => $this->task->id]);
        Storage::disk('local')->assertExists('artifacts/original/task-1/statement.zip');
    }

    public function testArchivesMultipleTasksWithoutDeletingFiles(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/original/task-1/statement.zip', 'zip bytes');

        $secondTask = BillTask::query()->create([
            'user_id'     => $this->user->id,
            'source'      => 'alipay',
            'profile_id'  => 'alipay-statement',
            'status'      => 'parsed',
            'received_at' => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'summary'     => '支付宝交易流水',
        ]);

        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.archive-many'), [
            'ids' => [$this->task->id, $secondTask->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.archived', 2);

        $this->task->refresh();
        $secondTask->refresh();
        $this->assertSame('cleaned', $this->task->status);
        $this->assertSame('cleaned', $secondTask->status);
        Storage::disk('local')->assertExists('artifacts/original/task-1/statement.zip');
    }

    public function testDownloadsOwnedArtifact(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/original/task-1/statement.zip', 'zip bytes');
        $artifact = $this->task->artifacts()->first();

        $this->actingAs($this->user, 'api');
        $response = $this->get(route('api.v1.bill-artifacts.download', ['billArtifact' => $artifact->id]));

        $response->assertStatus(200);
        $response->assertHeader('content-disposition');
        $this->assertSame('zip bytes', $response->streamedContent());
    }

    public function testImportsStatementRowsIntoFireflyTransactions(): void
    {
        $row = $this->createStatementRow();
        $this->createAccount('招商银行', 'Asset account');

        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.import', ['billTask' => $this->task->id]), [
            'row_ids' => [$row->id],
            'confirm' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('summary.imported', 1);
        $response->assertJsonPath('summary.skipped', 0);

        $row->refresh();
        $this->assertSame('imported', $row->status);
        $this->assertNotNull($row->transaction_group_id);
        $this->assertDatabaseHas('transaction_journals', [
            'id'          => $row->transactionGroup->transactionJournals()->first()->id,
            'description' => '为155****2328交费20.00元',
        ]);

        $again = $this->postJson(route('api.v1.bill-tasks.import', ['billTask' => $this->task->id]), [
            'row_ids' => [$row->id],
            'confirm' => true,
        ]);

        $again->assertStatus(200);
        $again->assertJsonPath('summary.imported', 0);
        $again->assertJsonPath('summary.skipped', 1);
    }

    public function testImportSkipsRowsWithoutFireflyType(): void
    {
        $row = $this->createStatementRow();
        $row->firefly_type = null;
        $row->direction    = '不计收支';
        $row->save();

        $this->actingAs($this->user, 'api');
        $response = $this->postJson(route('api.v1.bill-tasks.import', ['billTask' => $this->task->id]), [
            'row_ids' => [$row->id],
            'confirm' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('summary.imported', 0);
        $response->assertJsonPath('summary.skipped', 1);
        $response->assertJsonPath('rows.0.status', 'skipped');

        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->transaction_group_id);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();
        $this->actingAs($this->user, 'api');

        $mail       = BillMailMessage::query()->create([
            'user_id'     => $this->user->id,
            'message_id'  => '<mail-1@example.com>',
            'mailbox'     => 'bills@example.com',
            'from_address' => 'bank@example.com',
            'to_address'  => 'bills@example.com',
            'subject'     => '招商银行信用卡电子账单',
            'received_at' => Carbon::parse('2026-06-10 09:30:00', 'Asia/Shanghai'),
            'raw_path'    => 'mail/raw/mail-1.eml',
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
            'metadata'     => ['source' => 'mail_attachment'],
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
            'metadata'     => ['source' => 'mailbox'],
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

    private function createStatementRow(): BillStatementRow
    {
        /** @var BillArtifact $artifact */
        $artifact = $this->task->artifacts()->first();
        $import   = BillStatementImport::query()->create([
            'user_id'           => $this->user->id,
            'bill_task_id'      => $this->task->id,
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
            'bill_task_id'             => $this->task->id,
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

    private function createAccount(string $name, string $type): Account
    {
        $accountType = AccountType::where('type', $type)->firstOrFail();

        return Account::query()->create([
            'user_id'         => $this->user->id,
            'user_group_id'   => $this->user->user_group_id,
            'account_type_id' => $accountType->id,
            'name'            => $name,
            'active'          => true,
            'encrypted'       => false,
            'order'           => 0,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\integration\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillTaskProcessor;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Override;
use Tests\integration\TestCase;

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
        $encrypted = $this->createTask('received', 'cmb', 'cmb-credit-card');
        BillArtifact::query()->create([
            'bill_task_id' => $encrypted->id,
            'kind'         => 'zip',
            'filename'     => 'statement.zip',
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
}

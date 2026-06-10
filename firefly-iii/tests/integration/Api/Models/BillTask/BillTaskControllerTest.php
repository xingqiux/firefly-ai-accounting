<?php

declare(strict_types=1);

namespace Tests\integration\Api\Models\BillTask;

use Carbon\Carbon;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillSecretChallenge;
use FireflyIII\Models\BillTask;
use FireflyIII\Models\BillTaskEvent;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

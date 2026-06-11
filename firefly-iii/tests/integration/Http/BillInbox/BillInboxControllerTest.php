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
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Override;
use PragmaRX\Google2FALaravel\Middleware as MFAMiddleware;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Http\Controllers\BillInbox\IndexController
 * @covers \FireflyIII\Services\BillIngestion\BillTaskActionService
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

<?php

declare(strict_types=1);

namespace Tests\integration\Services\BillIngestion;

use Carbon\Carbon;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillMailAttachment;
use FireflyIII\Services\BillIngestion\BillMailIngestionService;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Override;
use Tests\integration\TestCase;

/**
 * @internal
 *
 * @covers \FireflyIII\Services\BillIngestion\BillMailIngestionService
 * @covers \FireflyIII\Services\BillIngestion\BillMailAttachment
 */
final class BillMailIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    public function testAlipayStatementMailCreatesEncryptedBillTask(): void
    {
        $mail = BillMailMessage::query()->create([
            'user_id'      => $this->user->id,
            'message_id'   => '<alipay-statement-1@mail.alipay.com>',
            'mailbox'      => 'ziyufg@gmail.com',
            'from_address' => 'service@mail.alipay.com',
            'to_address'   => 'ziyufg@gmail.com',
            'subject'      => '李昶乐的支付宝交易流水明细',
            'received_at'  => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
            'raw_path'     => 'mail/raw/alipay-statement-1.eml',
            'checksum'     => 'mail-checksum',
            'sync_cursor'  => 'gmail:1',
        ]);

        $task = app(BillMailIngestionService::class)->ingest($mail, [
            new BillMailAttachment(
                filename: '支付宝交易明细(20260601-20260612).zip',
                path: 'mail/attachments/alipay-statement.zip',
                checksum: 'zip-checksum',
                size: 4200,
            ),
        ]);

        $this->assertInstanceOf(BillTask::class, $task);
        $this->assertSame('alipay', $task->source);
        $this->assertSame('alipay-statement', $task->profile_id);
        $this->assertSame('received', $task->status);
        $this->assertSame('支付宝交易流水明细', $task->summary);
        $this->assertSame('task.created', $task->events()->latest('id')->first()->event_type);

        $artifact = $task->artifacts()->first();
        $this->assertSame('zip', $artifact->kind);
        $this->assertSame('支付宝交易明细(20260601-20260612).zip', $artifact->filename);
        $this->assertTrue($artifact->encrypted);
        $this->assertSame('alipay_service_message', $artifact->metadata['password_source']);
        $this->assertSame(4200, $artifact->metadata['size']);
    }

    public function testNonAlipayMailIsIgnored(): void
    {
        $mail = BillMailMessage::query()->create([
            'user_id'      => $this->user->id,
            'message_id'   => '<newsletter-1@example.com>',
            'mailbox'      => 'ziyufg@gmail.com',
            'from_address' => 'news@example.com',
            'to_address'   => 'ziyufg@gmail.com',
            'subject'      => '普通通知',
            'received_at'  => Carbon::parse('2026-06-12 18:26:00', 'Asia/Shanghai'),
        ]);

        $task = app(BillMailIngestionService::class)->ingest($mail, []);

        $this->assertNull($task);
        $this->assertSame(0, BillTask::query()->count());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAuthenticatedUser();
    }
}

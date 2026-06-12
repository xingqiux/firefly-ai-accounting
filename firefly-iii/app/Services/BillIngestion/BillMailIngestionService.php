<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion;

use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillTask;
use Illuminate\Support\Facades\DB;

class BillMailIngestionService
{
    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    public function ingest(BillMailMessage $mail, array $attachments): ?BillTask
    {
        if (false === $this->isAlipayStatement($mail)) {
            return null;
        }

        return DB::transaction(function () use ($mail, $attachments): BillTask {
            /** @var BillTask $task */
            $task = BillTask::query()->create([
                'user_id'              => $mail->user_id,
                'bill_mail_message_id' => $mail->id,
                'source'               => 'alipay',
                'profile_id'           => 'alipay-statement',
                'status'               => 'received',
                'received_at'          => $mail->received_at,
                'summary'              => '支付宝交易流水明细',
                'metadata'             => [
                    'mail_subject'     => $mail->subject,
                    'password_source'  => 'alipay_service_message',
                    'sender'           => $mail->from_address,
                ],
            ]);

            foreach ($attachments as $attachment) {
                $task->artifacts()->create([
                    'kind'      => $this->artifactKind($attachment->filename),
                    'filename'  => $attachment->filename,
                    'path'      => $attachment->path,
                    'checksum'  => $attachment->checksum,
                    'encrypted' => true,
                    'metadata'  => [
                        'source'          => 'mail_attachment',
                        'password_source' => 'alipay_service_message',
                        'size'            => $attachment->size,
                    ],
                ]);
            }

            $task->events()->create([
                'event_type' => 'task.created',
                'message'    => '已识别支付宝交易流水邮件，等待解压密码',
                'metadata'   => [
                    'source' => 'mailbox',
                ],
            ]);

            return $task;
        });
    }

    private function isAlipayStatement(BillMailMessage $mail): bool
    {
        $from    = strtolower((string) $mail->from_address);
        $subject = (string) $mail->subject;

        return str_contains($from, 'service@mail.alipay.com')
            && str_contains($subject, '支付宝交易流水明细');
    }

    private function artifactKind(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return '' === $extension ? 'attachment' : $extension;
    }
}

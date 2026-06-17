<?php

declare(strict_types=1);

namespace FireflyIII\Services\BillIngestion\Channels;

use Carbon\Carbon;
use FireflyIII\Models\BillMailMessage;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillMailAttachment;
use FireflyIII\Services\BillIngestion\BillSourceChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BocTransactionBillSourceChannel implements BillSourceChannel
{
    public function source(): string
    {
        return 'boc';
    }

    public function displayName(): string
    {
        return '中国银行交易流水';
    }

    public function settingsDescription(): string
    {
        return '会自动识别中国银行交易流水邮件，保存加密 PDF 附件，并等待输入中国银行 APP 申请记录中的打开密码。';
    }

    /**
     * @return array<int, string>
     */
    public function profileIds(): array
    {
        return ['boc-transaction-statement'];
    }

    /**
     * @return array<int, string>
     */
    public function mailboxSearchCriteria(): array
    {
        return ['SUBJECT "中国银行交易流水"'];
    }

    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    public function matches(BillMailMessage $mail, array $attachments): bool
    {
        $subject = (string) $mail->subject;
        $body    = $this->mailBody($mail);

        return str_contains($subject, '中国银行交易流水')
            && (str_contains($body, '中国银行APP') || str_contains($body, '交易流水打印'))
            && $this->hasPdfAttachment($attachments);
    }

    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    public function ingest(BillMailMessage $mail, array $attachments): BillTask
    {
        return DB::transaction(function () use ($mail, $attachments): BillTask {
            /** @var BillTask $task */
            $task = BillTask::query()->create([
                'user_id'              => $mail->user_id,
                'bill_mail_message_id' => $mail->id,
                'source'               => $this->source(),
                'profile_id'           => 'boc-transaction-statement',
                'status'               => 'received',
                'received_at'          => $mail->received_at,
                'summary'              => '中国银行交易流水',
                'metadata'             => [
                    'mail_subject'    => $mail->subject,
                    'password_source' => 'boc_app_statement_record',
                    'sender'          => $mail->from_address,
                ],
            ]);

            foreach ($attachments as $attachment) {
                if ('pdf' !== $this->artifactKind($attachment->filename)) {
                    continue;
                }

                $task->artifacts()->create([
                    'kind'      => 'pdf',
                    'filename'  => $this->safeFilename($attachment->filename),
                    'path'      => $attachment->path,
                    'checksum'  => $attachment->checksum,
                    'encrypted' => true,
                    'metadata'  => [
                        'source'          => 'mail_attachment',
                        'password_source' => 'boc_app_statement_record',
                        'size'            => $attachment->size,
                    ],
                ]);
            }

            $task->events()->create([
                'event_type' => 'task.created',
                'message'    => '已识别中国银行交易流水邮件，等待打开密码',
                'metadata'   => ['source' => 'mailbox'],
            ]);

            return $task;
        });
    }

    public function prepare(BillTask $task): bool
    {
        return true;
    }

    public function needsSecret(BillTask $task): bool
    {
        return $task->artifacts()
            ->where('kind', 'pdf')
            ->where('encrypted', true)
            ->exists()
        ;
    }

    public function secretPrompt(BillTask $task): string
    {
        return '请输入中国银行APP“交易流水打印”申请记录中的打开密码';
    }

    public function process(BillTask $task, ?string $secret = null): bool
    {
        if ($this->needsSecret($task) && (null === $secret || '' === trim($secret))) {
            $this->openSecretChallenge($task);

            return true;
        }

        $metadata                         = is_array($task->metadata) ? $task->metadata : [];
        $metadata['parser_status']        = 'waiting_for_pdf_mapping';
        $metadata['password_submitted_at'] = Carbon::now('Asia/Shanghai')->toAtomString();
        $task->metadata                   = $metadata;
        $task->status                     = 'parsed';
        $task->error_code                 = null;
        $task->error_message              = null;
        $task->save();
        $this->appendEvent($task, 'task.parsed', '中国银行账单密码已提交，等待 PDF 字段映射确认');

        return true;
    }

    public function shouldProcessAfterSecret(BillTask $task): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function processingRule(): array
    {
        return [
            'enabled'               => true,
            'name'                  => $this->displayName(),
            'source'                => $this->source(),
            'from_contains'         => '',
            'subject_contains'      => '中国银行交易流水',
            'attachment_extensions' => ['pdf'],
            'gmail_label'           => '',
            'keywords'              => ['中国银行', '交易流水'],
            'built_in'              => true,
        ];
    }

    private function artifactKind(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return '' === $extension ? 'attachment' : $extension;
    }

    /**
     * @param array<int, BillMailAttachment> $attachments
     */
    private function hasPdfAttachment(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if ('pdf' === $this->artifactKind($attachment->filename)) {
                return true;
            }
        }

        return false;
    }

    private function mailBody(BillMailMessage $mail): string
    {
        $parts = [];
        foreach ([$mail->body_html_path, $mail->body_text_path] as $path) {
            if (null !== $path && '' !== $path && Storage::disk('local')->exists($path)) {
                $parts[] = Storage::disk('local')->get($path);
            }
        }

        return implode("\n", $parts);
    }

    private function openSecretChallenge(BillTask $task): void
    {
        $challenge = $task->secretChallenges()->create([
            'kind'     => 'password',
            'prompt'   => $this->secretPrompt($task),
            'status'   => 'open',
            'attempts' => 0,
        ]);

        $task->status                      = 'needs_secret';
        $task->current_secret_challenge_id = $challenge->id;
        $task->save();
        $this->appendEvent($task, 'challenge.created', '任务需要密码或验证码');
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[\/\\\\]+/', '_', basename($filename)) ?: 'boc-transaction-statement.pdf';

        return '' === pathinfo($filename, PATHINFO_EXTENSION) ? $filename.'.pdf' : $filename;
    }

    private function appendEvent(BillTask $task, string $eventType, string $message): void
    {
        $task->events()->create([
            'event_type' => $eventType,
            'message'    => $message,
        ]);
    }
}

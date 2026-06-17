<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BillInbox;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\BillArtifact;
use FireflyIII\Models\BillStatementRow;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillStatementRowImportService;
use FireflyIII\Services\BillIngestion\BillMailboxSyncService;
use FireflyIII\Services\BillIngestion\BillSourceChannelRegistry;
use FireflyIII\Services\BillIngestion\BillTaskActionService;
use FireflyIII\Services\BillIngestion\BillTaskProcessor;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IndexController extends Controller
{
    public function __construct(
        private readonly BillTaskActionService $actionService,
        private readonly BillMailboxSyncService $mailboxSyncService,
        private readonly BillTaskProcessor $taskProcessor,
        private readonly BillStatementRowImportService $rowImportService,
        private readonly BillSourceChannelRegistry $channelRegistry,
    ) {
        parent::__construct();

        $this->middleware(static function ($request, $next) {
            app('view')->share('mainTitleIcon', 'fa-inbox');
            app('view')->share('title', '账单收件箱');

            return $next($request);
        });
    }

    public function index(Request $request): Factory|View
    {
        $status = (string) $request->query('status', '');
        $query  = BillTask::query()
            ->where('user_id', auth()->id())
            ->with(['mailMessage', 'currentSecretChallenge', 'statementRows'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
        ;

        if ('' !== $status) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'cleaned');
        }

        $tasks = $query->paginate(25)->withQueryString();
        $stats = BillTask::query()
            ->where('user_id', auth()->id())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray()
        ;

        Log::channel('audit')->info('User visits bill inbox index page.');

        return view('bill-inbox.index', [
            'tasks'         => $tasks,
            'stats'         => $stats,
            'currentStatus' => $status,
            'mailboxStatus' => $this->mailboxStatus(),
            'statusLabels'   => $this->statusLabels(),
            'statusClasses'  => $this->statusClasses(),
            'builtInChannels'=> $this->channelRegistry->settingsChannels(),
        ]);
    }

    public function show(Request $request, BillTask $billTask): Factory|View
    {
        $billTask->load([
            'artifacts'        => fn ($query) => $query->orderBy('id'),
            'currentSecretChallenge',
            'events'           => fn ($query) => $query->orderByDesc('id'),
            'mailMessage',
            'statementImports' => fn ($query) => $query->orderBy('id'),
        ]);
        $billTask->artifacts->each(function (BillArtifact $artifact) use ($billTask): void {
            $artifact->setAttribute('display_name', $this->artifactDisplayName($billTask, $artifact));
        });
        $rowStatus = (string) $request->query('row_status', '');
        $rowFrom   = (string) $request->query('row_from', '');
        $rowTo     = (string) $request->query('row_to', '');
        $rows      = $billTask->statementRows()
            ->orderByDesc('occurred_at')
            ->orderBy('row_number')
        ;
        if ('' !== $rowStatus) {
            $rows->where('status', $rowStatus);
        }
        if ('' !== $rowFrom) {
            $rows->where('occurred_at', '>=', $rowFrom.' 00:00:00');
        }
        if ('' !== $rowTo) {
            $rows->where('occurred_at', '<=', $rowTo.' 23:59:59');
        }

        Log::channel('audit')->info(sprintf('User visits bill inbox task #%d page.', $billTask->id));

        return view('bill-inbox.show', [
            'task'         => $billTask,
            'statementRows'=> $rows->get(),
            'rowFilters'   => [
                'status' => $rowStatus,
                'from'   => $rowFrom,
                'to'     => $rowTo,
            ],
            'subTitle'     => sprintf('任务 #%d', $billTask->id),
            'statusLabels' => $this->statusLabels(),
            'statusClasses'=> $this->statusClasses(),
            'eventLabels'  => $this->eventLabels(),
        ]);
    }

    public function settings(): Factory|View
    {
        Log::channel('audit')->info('User visits bill inbox settings page.');

        return view('bill-inbox.settings', [
            'settings'        => $this->mailboxSettings(),
            'hasPassword'     => '' !== (string) Preferences::getEncrypted('bill_inbox_mailbox_password', '')->data,
            'builtInChannels' => $this->channelRegistry->settingsChannels(),
        ]);
    }

    public function postIgnore(BillTask $billTask): RedirectResponse
    {
        $this->actionService->ignore($billTask);
        session()->flash('success', '账单任务已忽略。');

        return redirect(route('bill-inbox.show', [$billTask->id]));
    }

    public function postRetry(BillTask $billTask): RedirectResponse
    {
        $this->actionService->retry($billTask);
        session()->flash('success', '账单任务已重新排队。');

        return redirect(route('bill-inbox.show', [$billTask->id]));
    }

    public function postSecret(Request $request, BillTask $billTask): RedirectResponse
    {
        $request->validate([
            'value' => ['required', 'string', 'min:1'],
        ]);

        try {
            $processedTask = $this->actionService->submitSecret($billTask, (string) $request->string('value'));
            session()->flash('success', $this->secretSubmittedMessage($processedTask));
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }

        if ('index' === (string) $request->input('redirect_to', '')) {
            $status = (string) $request->input('status', '');

            return redirect(route('bill-inbox.index', '' === $status ? [] : ['status' => $status]));
        }

        return redirect(route('bill-inbox.show', [$billTask->id]));
    }

    public function postSync(): RedirectResponse
    {
        $result    = $this->mailboxSyncService->syncForUser(auth()->user(), 25);
        $processed = $this->taskProcessor->processBatch(25, auth()->user());

        if ([] !== $result->errors) {
            session()->flash('error', implode(' ', $result->errors));
        }

        session()->flash('success', sprintf(
            '邮箱同步完成：扫描 %d 封，新增 %d 个任务，忽略 %d 封，重复 %d 封，失败 %d 封；已推进 %d 个任务。',
            $result->scanned,
            $result->created,
            $result->ignored,
            $result->duplicates,
            $result->failed,
            $processed->processed
        ));

        return redirect(route('bill-inbox.index'));
    }

    public function postCleanupStale(): RedirectResponse
    {
        $archived = $this->actionService->cleanupStale(auth()->user());
        session()->flash('success', sprintf('已归档 %d 个过时账单任务。', $archived));

        return redirect(route('bill-inbox.index'));
    }

    public function postArchive(BillTask $billTask): RedirectResponse
    {
        $this->actionService->archive($billTask);
        session()->flash('success', '账单任务已归档。');

        return redirect(route('bill-inbox.index'));
    }

    public function postArchiveMany(Request $request): RedirectResponse
    {
        $ids      = array_map('intval', $request->input('task_ids', []));
        $archived = [] === $ids ? 0 : $this->actionService->archiveMany(auth()->user(), $ids);
        session()->flash('success', sprintf('已归档 %d 个账单任务。', $archived));

        return redirect(route('bill-inbox.index'));
    }

    public function postUpdateRow(Request $request, BillStatementRow $billStatementRow): RedirectResponse
    {
        $validated = $request->validate([
            'occurred_at'          => ['nullable', 'date'],
            'platform_category'    => ['nullable', 'string', 'max:255'],
            'counterparty'         => ['nullable', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:4096'],
            'direction'            => ['nullable', 'string', 'max:255'],
            'amount'               => ['nullable', 'numeric'],
            'payment_method'       => ['nullable', 'string', 'max:255'],
            'transaction_status'   => ['nullable', 'string', 'max:255'],
            'firefly_type'         => ['nullable', 'string', 'in:withdrawal,deposit,transfer'],
            'firefly_date'         => ['nullable', 'date'],
            'firefly_amount'       => ['nullable', 'numeric'],
            'firefly_description'  => ['nullable', 'string', 'max:1000'],
            'source_name'          => ['nullable', 'string', 'max:255'],
            'destination_name'     => ['nullable', 'string', 'max:255'],
            'category_name'        => ['nullable', 'string', 'max:255'],
            'notes'                => ['nullable', 'string', 'max:32768'],
        ]);

        $editableMap = [
            'occurred_at'        => '交易时间',
            'platform_category'  => '交易分类',
            'counterparty'       => '交易对方',
            'description'        => '商品说明',
            'direction'          => '收/支',
            'amount'             => '金额',
            'payment_method'     => '收/付款方式',
            'transaction_status' => '交易状态',
        ];
        $editable    = is_array($billStatementRow->editable_data) ? $billStatementRow->editable_data : [];
        foreach ($validated as $key => $value) {
            $billStatementRow->{$key} = $value;
            if (array_key_exists($key, $editableMap)) {
                $editable[$editableMap[$key]] = null === $value ? '' : (string) $value;
            }
        }
        $billStatementRow->editable_data = $editable;
        $billStatementRow->save();

        session()->flash('success', '流水已保存。');

        return redirect(route('bill-inbox.show', [$billStatementRow->bill_task_id]));
    }

    public function postImportRows(Request $request, BillTask $billTask): RedirectResponse
    {
        $ids    = array_map('intval', $request->input('row_ids', []));
        $result = $this->rowImportService->importTaskRows(auth()->user(), $billTask->id, $ids, true);

        session()->flash('success', sprintf('已存入 %d 条流水，跳过 %d 条，失败 %d 条。', $result['summary']['imported'], $result['summary']['skipped'], $result['summary']['failed']));

        return redirect(route('bill-inbox.show', [$billTask->id]));
    }

    public function download(BillArtifact $billArtifact): StreamedResponse
    {
        if (null === $billArtifact->path || '' === $billArtifact->path || !Storage::disk('local')->exists($billArtifact->path)) {
            throw new NotFoundHttpException();
        }

        return Storage::disk('local')->download($billArtifact->path, $billArtifact->filename ?? basename($billArtifact->path));
    }

    public function postSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'           => ['nullable', 'boolean'],
            'provider'          => ['nullable', 'string', 'in:gmail,imap'],
            'email'             => ['nullable', 'string', 'max:255'],
            'host'              => ['nullable', 'string', 'max:255'],
            'port'              => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption'        => ['nullable', 'string', 'in:none,ssl,tls,starttls'],
            'username'          => ['nullable', 'string', 'max:255'],
            'password'          => ['nullable', 'string', 'max:1024'],
            'folder'            => ['nullable', 'string', 'max:255'],
        ]);

        $provider   = (string) ($validated['provider'] ?? 'gmail');
        $email      = (string) ($validated['email'] ?? '');
        $host       = trim((string) ($validated['host'] ?? ''));
        $port       = (int) ($validated['port'] ?? 993);
        $encryption = (string) ($validated['encryption'] ?? 'ssl');
        $folder     = trim((string) ($validated['folder'] ?? ''));
        $username   = trim((string) ($validated['username'] ?? ''));

        if ('gmail' === $provider) {
            $host       = 'imap.gmail.com';
            $port       = 993;
            $encryption = 'ssl';
            $folder     = '' === $folder ? 'INBOX' : $folder;
            $username   = '' === $username ? $email : $username;
        }

        $folder     = '' === $folder ? 'INBOX' : $folder;

        Preferences::set('bill_inbox_mailbox_enabled', $request->boolean('enabled'));
        Preferences::set('bill_inbox_mailbox_provider', $provider);
        Preferences::set('bill_inbox_mailbox_email', $email);
        Preferences::set('bill_inbox_mailbox_host', $host);
        Preferences::set('bill_inbox_mailbox_port', $port);
        Preferences::set('bill_inbox_mailbox_encryption', $encryption);
        Preferences::set('bill_inbox_mailbox_username', $username);
        Preferences::set('bill_inbox_mailbox_folder', $folder);
        Preferences::set('bill_inbox_processing_rules', $this->channelRegistry->processingRules());
        Preferences::set('bill_inbox_quick_gmail_label', '');
        Preferences::set('bill_inbox_quick_keywords', '');

        if (array_key_exists('password', $validated) && '' !== (string) $validated['password']) {
            Preferences::setEncrypted('bill_inbox_mailbox_password', (string) $validated['password']);
        }

        Preferences::mark();
        session()->flash('success', '账单收件箱邮箱配置已保存。');

        return redirect(route('bill-inbox.settings'));
    }

    private function mailboxSettings(): array
    {
        return [
            'enabled'    => true === Preferences::get('bill_inbox_mailbox_enabled', false)->data,
            'provider'   => (string) Preferences::get('bill_inbox_mailbox_provider', 'gmail')->data,
            'email'      => (string) Preferences::get('bill_inbox_mailbox_email', '')->data,
            'host'       => (string) Preferences::get('bill_inbox_mailbox_host', 'imap.gmail.com')->data,
            'port'       => (int) Preferences::get('bill_inbox_mailbox_port', 993)->data,
            'encryption' => (string) Preferences::get('bill_inbox_mailbox_encryption', 'ssl')->data,
            'username'   => (string) Preferences::get('bill_inbox_mailbox_username', '')->data,
            'folder'     => (string) Preferences::get('bill_inbox_mailbox_folder', 'INBOX')->data,
        ];
    }

    private function mailboxStatus(): array
    {
        $settings = $this->mailboxSettings();

        return [
            'enabled'     => $settings['enabled'],
            'provider'    => $settings['provider'],
            'email'       => $settings['email'],
            'folder'      => $settings['folder'],
            'hasPassword' => '' !== (string) Preferences::getEncrypted('bill_inbox_mailbox_password', '')->data,
        ];
    }

    private function artifactDisplayName(BillTask $billTask, BillArtifact $artifact): string
    {
        if ('wechat' !== $billTask->source) {
            return (string) ($artifact->filename ?? $artifact->path ?? '-');
        }

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        if ('zip' === $artifact->kind || 'remote_download' === ($metadata['source'] ?? null)) {
            return '原始压缩包';
        }
        if (in_array($artifact->kind, ['csv', 'xlsx'], true) || 'wechat_zip_extract' === ($metadata['source'] ?? null)) {
            return '账单明细';
        }

        return (string) ($artifact->filename ?? $artifact->path ?? '-');
    }

    private function secretSubmittedMessage(BillTask $billTask): string
    {
        return match ($billTask->status) {
            'parsed'  => $billTask->statementRows()->exists() ? '账单已解析，已生成流水明细。' : '账单已解压，可下载附件查看。',
            'ready'   => '验证码/密码已提交，等待处理。',
            default   => '验证码/密码已提交。',
        };
    }

    private function statusLabels(): array
    {
        return [
            'received'     => '已接收',
            'ready'        => '待处理',
            'needs_secret' => '需要验证码',
            'parsed'       => '已解析',
            'imported'     => '已存入',
            'failed'       => '处理失败',
            'unknown'      => '未识别',
            'ignored'      => '已忽略',
            'cleaned'      => '已归档',
            'pending'      => '待存入',
        ];
    }

    private function statusClasses(): array
    {
        return [
            'received'     => 'label-warning',
            'ready'        => 'label-warning',
            'needs_secret' => 'label-warning',
            'parsed'       => 'label-primary',
            'imported'     => 'label-success',
            'failed'       => 'label-danger',
            'unknown'      => 'label-danger',
            'ignored'      => 'label-default',
            'cleaned'      => 'label-default',
            'pending'      => 'label-warning',
        ];
    }

    private function eventLabels(): array
    {
        return [
            'task.created'          => '创建任务',
            'challenge.created'     => '需要验证码',
            'task.ready'            => '准备处理',
            'challenge.consumed'    => '已提交验证码',
            'task.parsed'           => '已解析账单',
            'task.archived'         => '已归档',
            'task.cleaned'          => '已归档',
            'task.failed'           => '处理失败',
            'task.unknown'          => '未识别',
            'task.retry_requested'  => '重新处理',
            'task.ignored'          => '已忽略',
            'remote_file.downloaded'=> '已下载账单文件',
            'remote_file.failed'    => '下载账单文件失败',
        ];
    }
}

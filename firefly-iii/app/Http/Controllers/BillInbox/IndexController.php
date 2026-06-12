<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BillInbox;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillMailboxSyncService;
use FireflyIII\Services\BillIngestion\BillTaskActionService;
use FireflyIII\Services\BillIngestion\BillTaskProcessor;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IndexController extends Controller
{
    public function __construct(
        private readonly BillTaskActionService $actionService,
        private readonly BillMailboxSyncService $mailboxSyncService,
        private readonly BillTaskProcessor $taskProcessor,
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
            ->with(['mailMessage', 'currentSecretChallenge'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
        ;

        if ('' !== $status) {
            $query->where('status', $status);
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
        ]);
    }

    public function show(BillTask $billTask): Factory|View
    {
        $billTask->load(['artifacts', 'currentSecretChallenge', 'events', 'mailMessage']);
        Log::channel('audit')->info(sprintf('User visits bill inbox task #%d page.', $billTask->id));

        return view('bill-inbox.show', [
            'task'     => $billTask,
            'subTitle' => sprintf('任务 #%d', $billTask->id),
        ]);
    }

    public function settings(): Factory|View
    {
        Log::channel('audit')->info('User visits bill inbox settings page.');

        return view('bill-inbox.settings', [
            'settings'      => $this->mailboxSettings(),
            'hasPassword'   => '' !== (string) Preferences::getEncrypted('bill_inbox_mailbox_password', '')->data,
            'quickSettings' => $this->quickSettings(),
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
            $this->actionService->submitSecret($billTask, (string) $request->string('value'));
            session()->flash('success', '验证码/密码已提交，任务已准备处理。');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect(route('bill-inbox.show', [$billTask->id]));
    }

    public function postSync(): RedirectResponse
    {
        $result    = $this->mailboxSyncService->syncForUser(auth()->user(), 25);
        $processed = $this->taskProcessor->processBatch(25);

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
            'quick_gmail_label' => ['nullable', 'string', 'max:255'],
            'quick_keywords'    => ['nullable', 'string', 'max:255'],
            'rule_enabled'      => ['nullable', 'array'],
            'rule_enabled.*'    => ['nullable', 'boolean'],
            'rule_name'         => ['nullable', 'array'],
            'rule_name.*'       => ['nullable', 'string', 'max:120'],
            'rule_source'       => ['nullable', 'array'],
            'rule_source.*'     => ['nullable', 'string', 'max:120'],
            'rule_from'         => ['nullable', 'array'],
            'rule_from.*'       => ['nullable', 'string', 'max:255'],
            'rule_subject'      => ['nullable', 'array'],
            'rule_subject.*'    => ['nullable', 'string', 'max:255'],
            'rule_attachment'   => ['nullable', 'array'],
            'rule_attachment.*' => ['nullable', 'string', 'max:255'],
            'rule_gmail_label'  => ['nullable', 'array'],
            'rule_gmail_label.*' => ['nullable', 'string', 'max:255'],
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
        Preferences::set('bill_inbox_processing_rules', $this->processingRulesFromValidated($validated));
        Preferences::set('bill_inbox_quick_gmail_label', trim((string) ($validated['quick_gmail_label'] ?? '')));
        Preferences::set('bill_inbox_quick_keywords', trim((string) ($validated['quick_keywords'] ?? '')));

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

    private function quickSettings(): array
    {
        return [
            'gmail_label' => (string) Preferences::get('bill_inbox_quick_gmail_label', '')->data,
            'keywords'    => (string) Preferences::get('bill_inbox_quick_keywords', '账单, statement')->data,
        ];
    }

    private function mailboxStatus(): array
    {
        $settings = $this->mailboxSettings();

        return [
            'enabled'     => $settings['enabled'],
            'provider'    => $settings['provider'],
            'email'       => $settings['email'],
            'gmail_label' => (string) Preferences::get('bill_inbox_quick_gmail_label', '')->data,
            'keywords'    => (string) Preferences::get('bill_inbox_quick_keywords', '')->data,
            'hasPassword' => '' !== (string) Preferences::getEncrypted('bill_inbox_mailbox_password', '')->data,
        ];
    }

    private function processingRulesFromValidated(array $validated): array
    {
        $rules = $this->tableRulesFromValidated($validated);
        if ([] !== $rules) {
            return $rules;
        }

        $label    = trim((string) ($validated['quick_gmail_label'] ?? ''));
        $keywords = $this->keywords((string) ($validated['quick_keywords'] ?? ''));
        if ('' === $label && [] === $keywords) {
            return [];
        }

        return [[
            'enabled'               => true,
            'name'                  => '默认账单邮件',
            'source'                => 'mail-bill',
            'from_contains'         => '',
            'subject_contains'      => '',
            'attachment_extensions' => [],
            'gmail_label'           => $label,
            'keywords'              => $keywords,
        ]];
    }

    private function tableRulesFromValidated(array $validated): array
    {
        $names       = $validated['rule_name'] ?? [];
        $sources     = $validated['rule_source'] ?? [];
        $from        = $validated['rule_from'] ?? [];
        $subjects    = $validated['rule_subject'] ?? [];
        $attachments = $validated['rule_attachment'] ?? [];
        $labels      = $validated['rule_gmail_label'] ?? [];
        $enabled     = $validated['rule_enabled'] ?? [];
        $keys        = array_unique(array_merge(
            array_keys($names),
            array_keys($sources),
            array_keys($from),
            array_keys($subjects),
            array_keys($attachments),
            array_keys($labels),
            array_keys($enabled)
        ));
        sort($keys);

        $rules       = [];
        foreach ($keys as $key) {
            $name       = trim((string) ($names[$key] ?? ''));
            $source     = trim((string) ($sources[$key] ?? ''));
            $fromText   = trim((string) ($from[$key] ?? ''));
            $subject    = trim((string) ($subjects[$key] ?? ''));
            $attachment = trim((string) ($attachments[$key] ?? ''));
            $label      = trim((string) ($labels[$key] ?? ''));

            if ('' === $name && '' === $source && '' === $fromText && '' === $subject && '' === $attachment && '' === $label) {
                continue;
            }

            $rules[]    = [
                'enabled'               => filter_var($enabled[$key] ?? false, FILTER_VALIDATE_BOOL),
                'name'                  => '' === $name ? sprintf('规则 %d', count($rules) + 1) : $name,
                'source'                => '' === $source ? 'unknown' : $source,
                'from_contains'         => $fromText,
                'subject_contains'      => $subject,
                'attachment_extensions' => $this->attachmentExtensions($attachment),
                'gmail_label'           => $label,
            ];
        }

        return $rules;
    }

    private function keywords(string $value): array
    {
        if ('' === trim($value)) {
            return [];
        }

        $parts    = preg_split('/[,，\n\r]+/', $value) ?: [];
        $keywords = [];
        foreach ($parts as $part) {
            $keyword = trim((string) $part);
            if ('' !== $keyword && !in_array($keyword, $keywords, true)) {
                $keywords[] = $keyword;
            }
        }

        return $keywords;
    }

    private function attachmentExtensions(string $value): array
    {
        if ('' === $value) {
            return [];
        }

        $parts      = preg_split('/[,，\s]+/', $value) ?: [];
        $extensions = [];
        foreach ($parts as $part) {
            $extension = strtolower(trim((string) $part, " \t\n\r\0\x0B."));
            if ('' !== $extension && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }
}

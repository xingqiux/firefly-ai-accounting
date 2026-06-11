<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\BillInbox;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\BillTask;
use FireflyIII\Services\BillIngestion\BillTaskActionService;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IndexController extends Controller
{
    public function __construct(private readonly BillTaskActionService $actionService)
    {
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
            'settings'    => $this->mailboxSettings(),
            'hasPassword' => '' !== (string) Preferences::getEncrypted('bill_inbox_mailbox_password', '')->data,
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

    public function postSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'    => ['nullable', 'boolean'],
            'email'      => ['nullable', 'string', 'max:255'],
            'host'       => ['nullable', 'string', 'max:255'],
            'port'       => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'string', 'in:none,ssl,tls,starttls'],
            'username'   => ['nullable', 'string', 'max:255'],
            'password'   => ['nullable', 'string', 'max:1024'],
            'folder'     => ['nullable', 'string', 'max:255'],
        ]);

        Preferences::set('bill_inbox_mailbox_enabled', $request->boolean('enabled'));
        Preferences::set('bill_inbox_mailbox_email', (string) ($validated['email'] ?? ''));
        Preferences::set('bill_inbox_mailbox_host', (string) ($validated['host'] ?? ''));
        Preferences::set('bill_inbox_mailbox_port', (int) ($validated['port'] ?? 993));
        Preferences::set('bill_inbox_mailbox_encryption', (string) ($validated['encryption'] ?? 'ssl'));
        Preferences::set('bill_inbox_mailbox_username', (string) ($validated['username'] ?? ''));
        Preferences::set('bill_inbox_mailbox_folder', (string) ($validated['folder'] ?? 'INBOX'));

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
            'email'      => (string) Preferences::get('bill_inbox_mailbox_email', '')->data,
            'host'       => (string) Preferences::get('bill_inbox_mailbox_host', '')->data,
            'port'       => (int) Preferences::get('bill_inbox_mailbox_port', 993)->data,
            'encryption' => (string) Preferences::get('bill_inbox_mailbox_encryption', 'ssl')->data,
            'username'   => (string) Preferences::get('bill_inbox_mailbox_username', '')->data,
            'folder'     => (string) Preferences::get('bill_inbox_mailbox_folder', 'INBOX')->data,
        ];
    }
}

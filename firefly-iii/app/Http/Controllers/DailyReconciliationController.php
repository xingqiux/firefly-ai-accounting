<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DailyReconciliationController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            app('view')->share('title', '按天对账');
            app('view')->share('mainTitleIcon', 'fa-calendar-check-o');
            app('view')->share('showCategory', true);

            return $next($request);
        });
    }

    public function index(Request $request): Factory|View
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        $day      = Carbon::parse((string) $request->query('date', today(config('app.timezone'))->format('Y-m-d')), config('app.timezone'))->startOfDay();
        $end      = $day->copy()->endOfDay();
        $page     = (int) $request->get('page');
        $pageSize = (int) Preferences::get('listPageSize', 50)->data;
        $types    = [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value];

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $groups    = $collector
            ->setRange($day, $end)
            ->setTypes($types)
            ->setLimit($pageSize)
            ->setPage($page)
            ->withAccountInformation()
            ->withBudgetInformation()
            ->withCategoryInformation()
            ->withAttachmentInformation()
            ->getPaginatedGroups()
        ;
        $groups->setPath(route('daily-reconciliation.index', ['date' => $day->format('Y-m-d')]));

        /** @var GroupCollectorInterface $summaryCollector */
        $summaryCollector = app(GroupCollectorInterface::class);

        return view('daily-reconciliation.index', [
            'day'         => $day,
            'groups'      => $groups,
            'summary'     => $this->summary($summaryCollector->setRange($day, $end)->setTypes($types)->getExtractedJournals()),
            'prevDate'    => $day->copy()->subDay()->format('Y-m-d'),
            'nextDate'    => $day->copy()->addDay()->format('Y-m-d'),
            'currentDate' => $day->format('Y-m-d'),
        ]);
    }

    public function update(Request $request, TransactionJournal $tj): RedirectResponse
    {
        $data = $request->validate([
            'description'      => 'required|string|min:1|max:1024',
            'category_name'    => 'nullable|string|max:255',
            'source_name'      => 'required|string|min:1|max:255',
            'destination_name' => 'required|string|min:1|max:255',
            'amount'           => ['required', 'regex:/^-?[0-9]+(\.[0-9]{1,12})?$/'],
            'transaction_date' => 'required|date_format:Y-m-d',
            'return_date'      => 'nullable|date_format:Y-m-d',
        ]);

        $objects = TransactionGroupEventObjects::collectFromTransactionGroup($tj->transactionGroup);

        /** @var JournalUpdateService $service */
        $service = app(JournalUpdateService::class);
        $service->setTransactionGroup($tj->transactionGroup);
        $service->setTransactionJournal($tj);
        $service->setData([
            'date'             => Carbon::parse($data['transaction_date'], config('app.timezone')),
            'description'      => $data['description'],
            'source_name'      => $data['source_name'],
            'destination_name' => $data['destination_name'],
            'category_name'    => $data['category_name'] ?? '',
            'amount'           => ltrim($data['amount'], '-'),
        ]);
        $service->update();

        $updated = $service->getTransactionJournal();
        $objects->appendFromTransactionGroup($updated->transactionGroup);
        event(new UpdatedSingleTransactionGroup(new TransactionGroupEventFlags(), $objects));
        event(new WebhookMessagesRequestSending());

        session()->flash('success', '对账流水已保存。');

        return redirect()->route('daily-reconciliation.index', ['date' => $data['return_date'] ?? $tj->date->format('Y-m-d')]);
    }

    private function summary(array $transactions): array
    {
        $income = '0';
        $expense = '0';
        $count = 0;
        $currency = Amount::getPrimaryCurrency();

        foreach ($transactions as $transaction) {
            ++$count;
            $amount = (string) ($transaction['pc_amount'] ?: $transaction['amount']);
            if (TransactionTypeEnum::DEPOSIT->value === $transaction['transaction_type_type']) {
                $income = bcadd($income, bcmul($amount, '-1'));
            }
            if (TransactionTypeEnum::WITHDRAWAL->value === $transaction['transaction_type_type']) {
                $expense = bcadd($expense, $amount);
            }
        }

        $net = bcsub($income, $expense);

        return [
            'income'  => Amount::formatAnything($currency, $income, false),
            'expense' => Amount::formatAnything($currency, $expense, false),
            'net'     => Amount::formatAnything($currency, $net, false),
            'count'   => $count,
        ];
    }
}

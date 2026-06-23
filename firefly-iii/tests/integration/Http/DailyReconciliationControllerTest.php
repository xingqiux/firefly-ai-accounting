<?php

declare(strict_types=1);

namespace Tests\integration\Http;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Http\Middleware\InterestingMessage;
use FireflyIII\Http\Middleware\Range;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Override;
use PragmaRX\Google2FALaravel\Middleware as MFAMiddleware;
use Tests\integration\TestCase;

final class DailyReconciliationControllerTest extends TestCase
{
    public function testIndexShowsDailyReconciliationWorkbench(): void
    {
        $user     = $this->createAuthenticatedUser();
        $this->createWithdrawal($user);
        $response = $this->actingAs($user)->get(route('daily-reconciliation.index', ['date' => '2026-06-18']));

        $response->assertStatus(200);
        $response->assertSee('按天对账');
        $response->assertSee('2026-06-18');
        $response->assertSee('上一天');
        $response->assertSee('下一天');
        $response->assertSee('收入');
        $response->assertSee('支出');
        $response->assertSee('净额');
        $response->assertSee('交易数');
        $response->assertSee('当天交易');
        $response->assertSee('type="date"', false);
        $response->assertSee('name="description"', false);
        $response->assertSee('name="category_name"', false);
        $response->assertSee('name="source_name"', false);
        $response->assertSee('name="destination_name"', false);
        $response->assertSee('name="amount"', false);
        $response->assertSee('name="transaction_date"', false);
        $response->assertSee('日常消费');
        $response->assertSee('餐饮');
        $response->assertSee('招商银行');
        $response->assertSee('便利店');
        $response->assertSee('描述</th><th>分类</th><th>金额</th><th>来源账户</th><th>目标账户</th><th>流水余额</th><th>日期</th>', false);
        $response->assertSee(route('daily-reconciliation.index', ['date' => '2026-06-17']), false);
        $response->assertSee(route('daily-reconciliation.index', ['date' => '2026-06-19']), false);
    }

    private function createWithdrawal(User $user): void
    {
        $currency = TransactionCurrency::where('code', 'EUR')->first();
        $source   = Account::factory()->withType(AccountTypeEnum::ASSET)->create([
            'user_id'       => $user->id,
            'user_group_id' => $user->user_group_id,
            'name'          => '招商银行',
            'active'        => true,
        ]);

        /** @var TransactionGroupRepositoryInterface $repository */
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($user);
        $repository->setUserGroup($user->userGroup);
        $repository->store([
            'user'          => $user,
            'user_group'    => $user->userGroup,
            'apply_rules'   => false,
            'fire_webhooks' => false,
            'transactions'  => [[
                'type'                  => TransactionTypeEnum::WITHDRAWAL->value,
                'date'                  => Carbon::parse('2026-06-18 10:30:00', config('app.timezone')),
                'amount'                => '12.34',
                'description'           => '日常消费',
                'currency_id'           => $currency->id,
                'currency_code'         => $currency->code,
                'foreign_currency_id'   => null,
                'foreign_currency_code' => null,
                'foreign_amount'        => null,
                'source_id'             => $source->id,
                'source_name'           => $source->name,
                'source_iban'           => null,
                'source_number'         => null,
                'source_bic'            => null,
                'destination_id'        => null,
                'destination_name'      => '便利店',
                'destination_iban'      => null,
                'destination_number'    => null,
                'destination_bic'       => null,
                'budget_id'             => null,
                'budget_name'           => null,
                'category_id'           => null,
                'category_name'         => '餐饮',
                'bill_id'               => null,
                'bill_name'             => null,
                'piggy_bank_id'         => null,
                'piggy_bank_name'       => null,
                'notes'                 => null,
                'tags'                  => [],
                'reconciled'            => false,
            ]],
        ]);
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
    }
}

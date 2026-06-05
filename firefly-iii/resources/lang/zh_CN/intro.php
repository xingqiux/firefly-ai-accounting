<?php

/**
 * intro.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

return [
    'index_intro'                                             => '欢迎来到 Firefly III 首页。请花一点时间浏览这段介绍，了解 Firefly III 的基本使用方式。',
    'index_accounts-chart'                                    => '此图表显示你的资产账户当前余额。你可以在偏好设置中选择要显示的账户。',
    'index_box_out_holder'                                    => '这个小方框和旁边的方框将让你快速了解你的财务状况。',
    'index_help'                                              => '如果你需要页面或表单的帮助，请按此按钮。',
    'index_outro'                                             => 'Firefly III 的多数页面都会提供类似的新手引导。如有疑问或建议，欢迎反馈。祝你使用顺利。',
    'index_sidebar-toggle'                                    => '要创建新交易、账户或其他内容，请使用此图标下的菜单。',
    'index_cash_account'                                      => '这些是迄今为止创建的账户。你可以使用现金账户来跟踪现金支出，但这当然不是强制性的。',
    'transactions_create_basic_info'                          => '输入你交易的基本信息。来源、目的地、日期和描述。',
    'transactions_create_amount_info'                         => '输入交易金额。如有必要，这些字段会自动更新外币金额信息。',
    'transactions_create_optional_info'                       => '所有这些字段都是可选的。在这里添加元数据将使你的交易更好地组织。',
    'transactions_create_split'                               => '如果你想拆分交易，请使用此按钮添加更多拆分',
    'accounts_create_iban'                                    => '为你的账户提供有效的 IBAN。这可以使将来的数据导入变得非常容易。',
    'accounts_create_asset_opening_balance'                   => '资产账户可能有一个“期初余额”，表明该账户的历史记录从 Firefly III 开始。',
    'accounts_create_asset_currency'                          => 'Firefly III 支持多种币种。资产账户有一种主要币种，你必须在此处设置。',
    'accounts_create_asset_virtual'                           => '有时，为你的账户提供虚拟余额会有所帮助：总是在实际余额中添加或删除额外的金额。',
    'budgets_index_intro'                                     => '预算用于管理你的财务，并构成 Firefly III 的核心功能之一。',
    'budgets_index_see_expenses_bar'                          => '花钱会慢慢填满这个栏。',
    'budgets_index_navigate_periods'                          => '浏览各个时期以轻松提前设置预算。',
    'budgets_index_new_budget'                                => '根据你的需要制定新的预算。',
    'budgets_index_list_of_budgets'                           => '使用此表设置每个预算的金额并查看你的情况。',
    'budgets_index_outro'                                     => '要了解有关预算的更多信息，请查看右上角的帮助图标。',
    'reports_index_intro'                                     => '使用这些报表可以详细了解你的财务状况。',
    'reports_index_inputReportType'                           => '选择报表类型。查看帮助页面，了解每个报表向你显示的内容。',
    'reports_index_inputAccountsSelect'                       => '你可以根据需要排除或包含资产账户。',
    'reports_index_inputDateRange'                            => '选择的日期范围完全由你决定：从一天到 10 年或更长时间。',
    'reports_index_extra-options-box'                         => '根据你选择的报表，你可以在此处选择额外的过滤器和选项。当你更改报表类型时请注意此框。',
    'reports_report_default_intro'                            => '该报表将为你提供快速、全面的财务概览。如果你还想看其他内容，请随时与我联系！',
    'reports_report_audit_intro'                              => '该报表将为你提供有关资产账户的详细见解。',
    'reports_report_audit_optionsBox'                         => '使用这些复选框可以显示或隐藏你感兴趣的列。',
    'reports_report_category_intro'                           => '该报表将为你提供一个或多个分类的见解。',
    'reports_report_category_pieCharts'                       => '这些图表将使你深入了解每个分类或每个账户的支出和收入。',
    'reports_report_category_incomeAndExpensesChart'          => '此图表显示你每个分类的支出和收入。',
    'reports_report_tag_intro'                                => '该报表将让你深入了解一个或多个标签。',
    'reports_report_tag_pieCharts'                            => '这些图表将让你深入了解每个标签、账户、分类或预算的支出和收入。',
    'reports_report_tag_incomeAndExpensesChart'               => '此图表显示了每个标签的支出和收入。',
    'reports_report_budget_intro'                             => '该报表将让你深入了解一项或多项预算。',
    'reports_report_budget_pieCharts'                         => '这些图表将使你深入了解每个预算或每个账户的支出。',
    'reports_report_budget_incomeAndExpensesChart'            => '该图表显示了每个预算的支出。',
    'transactions_create_switch_box'                          => '使用这些按钮可以快速切换你想要保存的交易类型。',
    'transactions_create_ffInput_category'                    => '你可以在此字段中自由输入。将建议以前创建的分类。',
    'transactions_create_withdrawal_ffInput_budget'           => '将你的支出与预算联系起来，以实现更好的财务控制。',
    'transactions_create_withdrawal_currency_dropdown_amount' => '当你以另一种币种支出时，请使用此下拉菜单。',
    'transactions_create_deposit_currency_dropdown_amount'    => '当你的收入采用其他币种时，请使用此下拉菜单。',
    'transactions_create_transfer_ffInput_piggy_bank_id'      => '选择一个储蓄罐并将此转账与你的储蓄关联起来。',
    'piggy-banks_index_saved'                                 => '该字段显示你在每个储蓄罐中存了多少钱。',
    'piggy-banks_index_button'                                => '进度条旁边有两个按钮（+ 和 -），用于向每个储蓄罐添加或删除钱。',
    'piggy-banks_index_accountStatus'                         => '对于每个至少有一个储蓄罐的资产账户，其状态列于该表中。',
    'piggy-banks_create_name'                                 => '你的目标是什么？一张新沙发、一台相机、应急资金？',
    'piggy-banks_create_date'                                 => '你可以为储蓄罐设定目标日期或截止日期。',
    'piggy-banks_show_piggyChart'                             => '这张图表将显示这个储蓄罐的历史。',
    'piggy-banks_show_piggyDetails'                           => '有关你的储蓄罐的一些详细信息',
    'piggy-banks_show_piggyEvents'                            => '此处还列出了任何添加或删除。',
    'bills_index_rules'                                       => '在这里你可以看到哪些规则将检查此订阅是否被命中',
    'bills_index_paid_in_period'                              => '该字段指示上次支付订阅的时间。',
    'bills_index_expected_in_period'                          => '此字段指示每个订阅是否以及何时预计下一个订阅会命中。',
    'subscriptions_index_rules'                               => '在这里你可以看到哪些规则将检查此订阅是否被命中',
    'subscriptions_index_paid_in_period'                      => '该字段指示上次支付订阅的时间。',
    'subscriptions_index_expected_in_period'                  => '此字段指示每个订阅是否以及何时预计下一个订阅会命中。',
    'bills_show_billInfo'                                     => '此表显示有关此订阅的一些一般信息。',
    'bills_show_billButtons'                                  => '使用此按钮重新扫描旧交易，以便它们与此订阅相匹配。',
    'bills_show_billChart'                                    => '此图表显示与此订阅相关的交易。',
    'subscriptions_show_billInfo'                             => '此表显示有关此订阅的一些一般信息。',
    'subscriptions_show_billButtons'                          => '使用此按钮重新扫描旧交易，以便它们与此订阅相匹配。',
    'subscriptions_show_billChart'                            => '此图表显示与此订阅相关的交易。',
    'bills_create_intro'                                      => '使用订阅来跟踪你每个时期的到期金额。考虑一下租金、保险或房贷等支出。',
    'bills_create_name'                                       => '使用描述性名称，例如“租金”或“健康保险”。',
    'bills_create_amount_min_holder'                          => '选择此订阅的最小和最大金额。',
    'bills_create_repeat_freq_holder'                         => '大多数订阅每月重复一次，但你可以在此处设置其他频率。',
    'bills_create_skip_holder'                                => '如果订阅每两周重复一次，则“跳过”字段应设置为“1”以每隔一周跳过一次。',
    'rules_index_intro'                                       => 'Firefly III 允许你管理规则，这些规则将自动应用于你创建或编辑的任何交易。',
    'rules_index_new_rule_group'                              => '你可以将规则组合成组以便于管理。',
    'rules_index_new_rule'                                    => '创建任意数量的规则。',
    'rules_index_prio_buttons'                                => '以你认为合适的方式订购。',
    'rules_index_test_buttons'                                => '你可以测试你的规则或将它们应用到现有交易。',
    'rules_index_rule-triggers'                               => '规则具有“触发器”和“操作”，你可以通过拖放来排序。',
    'rules_index_outro'                                       => '请务必使用右上角的 (?) 图标查看帮助页面！',
    'rules_create_mandatory'                                  => '选择一个描述性标题，并设置何时触发规则。',
    'rules_create_ruletriggerholder'                          => '添加任意数量的触发器，但请记住，在触发任何操作之前，所有触发器都必须匹配。',
    'rules_create_test_rule_triggers'                         => '使用此按钮查看哪些交易符合你的规则。',
    'rules_create_actions'                                    => '设置任意数量的操作。',
    'preferences_index_tabs'                                  => '这些选项卡后面提供了更多选项。',
    'currencies_index_intro'                                  => 'Firefly III 支持多种币种，你可以在此页面上更改。',
    'currencies_index_default'                                => 'Firefly III 有一种默认币种。',
    'currencies_index_buttons'                                => '使用这些按钮更改默认币种或启用其他币种。',
    'currencies_create_code'                                  => '此代码应符合 ISO 标准（通过 Google 搜索你的新币种）。',
];

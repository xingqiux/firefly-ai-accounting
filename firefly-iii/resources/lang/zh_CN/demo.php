<?php

/**
 * demo.php
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
    'no_demo_text'           => '抱歉，<abbr title=":route">本页</abbr>没有额外的演示说明文本。',
    'see_help_icon'          => '右上角的 <i class="fa fa-question-circle"></i> 图标也许能提供更多信息。',
    'index'                  => '欢迎来到 <strong>Firefly III</strong>！在此页面上，你可以快速查看财务概览。更多信息可以查看账户 &rarr; <a href=":asset">资产账户</a>、<a href=":budgets">预算</a> 和 <a href=":reports">报表</a> 页面。也可以直接四处看看，熟悉各个功能。',
    'accounts-index'         => '资产账户是你的个人银行账户。支出账户是你花钱的账户，例如商户和朋友。收入账户是你收到资金的账户，例如你的工作、政府或其他收入来源。负债是你的债务和贷款，例如旧信用卡债务或学生贷款。在此页面上你可以编辑或删除它们。',
    'budgets-index'          => '此页面向你显示预算概览。顶部栏显示可用于预算的金额。通过单击右侧的金额，可以针对任何时期进行自定义。你实际花费的金额显示在下面的栏中。下面是每个预算的支出以及你为这些支出制定的预算。',
    'reports-index-start'    => 'Firefly III 支持多种类型的报表。单击右上角的 <i class="fa fa-question-circle"></i> 图标来了解它们。',
    'reports-index-examples' => '请务必查看以下示例：<a href=":one">每月财务概览</a>、<a href=":two">年度财务概览</a> 和<a href=":three">预算概览</a>。',
    'currencies-index'       => 'Firefly III 支持多种币种。你可以将默认币种设置为人民币、美元等常用币种，也可以按需添加自己的币种。更改默认币种不会影响已有交易的币种；Firefly III 支持同时使用多种币种。',
    'transactions-index'     => '这些示例支出、收入和转账是自动生成的，内容比较简单。',
    'piggy-banks-index'      => '如你所见，这里有三个储蓄罐。可以使用加号和减号按钮调整每个储蓄罐中的金额，点击储蓄罐名称可查看详情。',
    'profile-index'          => '请注意，演示站点每四小时重置一次。curl、Postman、wget 等常见脚本客户端会被拦截，你的访问权限也可能随时被撤销。这是自动处理的，不属于错误。',
];

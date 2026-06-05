<?php

/*
 * rules.php
 * Copyright (c) 2023 james@firefly-iii.org
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
    'main_message'                                => '规则“:rule”中存在的操作“:action”无法应用于交易#:group：:error',
    'find_or_create_tag_failed'                   => '无法找到或创建标签“:tag”',
    'tag_already_added'                           => '标签“:tag”已链接到此交易',
    'inspect_transaction'                         => '检查交易“:title”（Firefly III）',
    'inspect_rule'                                => '检查规则“:title”（Firefly III）',
    'journal_other_user'                          => '该交易不属于该用户',
    'no_such_journal'                             => '该交易不存在',
    'journal_already_no_budget'                   => '此交易没有预算，因此无法删除',
    'journal_already_no_category'                 => '该交易没有分类，因此无法删除',
    'journal_already_no_notes'                    => '此交易没有备注，因此无法删除',
    'journal_not_found'                           => 'Firefly III 找不到请求的交易',
    'split_group'                                 => 'Firefly III 无法对具有多个拆分的交易执行此操作',
    'is_already_withdrawal'                       => '此交易已为支出',
    'is_already_deposit'                          => '这笔交易已经是收入',
    'is_already_transfer'                         => '此交易已经是转账',
    'no_destination'                              => '无法找到或创建目标账户“:name”',
    'is_not_transfer'                             => '本次交易并非转账',
    'complex_error'                               => '出现了一些复杂的问题。对此感到抱歉。请检查 Firefly III 的日志',
    'no_valid_opposing'                           => '转换失败，因为没有名为“:account”的有效账户',
    'new_notes_empty'                             => '要设置的注释为空',
    'unsupported_transaction_type_withdrawal'     => 'Firefly III 无法将“:type”转换为支出',
    'unsupported_transaction_type_deposit'        => 'Firefly III 无法将“:type”转换为收入',
    'unsupported_transaction_type_transfer'       => 'Firefly III 无法将“:type”转换为转账',
    'already_has_source_asset'                    => '该交易已有“:name”作为源资产账户',
    'already_has_destination_asset'               => '该交易已将“:name”作为目标资产账户',
    'already_has_destination'                     => '此交易已将“:name”作为目标账户',
    'already_has_source'                          => '此交易已将“:name”作为来源账户',
    'already_linked_to_subscription'              => '该交易已链接到订阅“:name”',
    'already_linked_to_category'                  => '该交易已链接到分类“:name”',
    'already_linked_to_budget'                    => '该交易已链接到预算“:name”',
    'cannot_find_subscription'                    => 'Firefly III 找不到订阅“:name”',
    'no_notes_to_move'                            => '该交易没有注释可移至描述字段',
    'no_tags_to_remove'                           => '该交易没有要删除的标签',
    'not_withdrawal'                              => '该交易并非支出',
    'not_deposit'                                 => '该交易不是收入',
    'cannot_find_tag'                             => 'Firefly III 找不到标签“:tag”',
    'cannot_find_asset'                           => 'Firefly III 找不到资产账户“:name”',
    'cannot_find_accounts'                        => 'Firefly III 找不到来源账户或目标账户',
    'cannot_find_source_transaction'              => 'Firefly III 找不到源交易',
    'cannot_find_destination_transaction'         => 'Firefly III 找不到目标交易',
    'cannot_find_source_transaction_account'      => 'Firefly III 找不到源交易账户',
    'cannot_find_destination_transaction_account' => 'Firefly III 找不到目标交易账户',
    'cannot_find_piggy'                           => 'Firefly III 找不到名为“:name”的储蓄罐',
    'no_link_piggy'                               => '此交易的账户未链接到储蓄罐，因此不会采取任何操作',
    'both_link_piggy'                             => '此交易的账户均与储蓄罐相连，因此不会采取任何行动',
    'already_linked'                              => '此交易已链接至储蓄罐“:name”',
    'cannot_unlink_tag'                           => '标签“:tag”未链接到此交易',
    'cannot_find_budget'                          => 'Firefly III 找不到预算“:name”',
    'cannot_find_category'                        => 'Firefly III 找不到分类“:name”',
    'cannot_set_budget'                           => 'Firefly III 无法将预算“:name”设置为“:type”类型的交易',
    'journal_invalid_amount'                      => 'Firefly III 无法设置金额“:amount”，因为它不是有效数字。',
    'cannot_remove_zero_piggy'                    => '无法从储蓄罐“:name”中删除零金额',
    'cannot_remove_from_piggy'                    => '无法从储蓄罐“:name”中删除 :amount',
    'cannot_add_zero_piggy'                       => '无法向储蓄罐“:name”添加零金额',
    'cannot_add_to_piggy'                         => '无法将 :amount 添加到储蓄罐“:name”',
];

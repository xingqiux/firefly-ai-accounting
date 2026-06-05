<?php

/**
 * firefly.php
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
    '404_header'              => 'Firefly III 找不到此页面。',
    '404_status'              => '未找到页面',
    '404_page_does_not_exist' => '你请求的页面不存在。请检查 URL 是否正确，或是否输入有误。',
    '404_send_error'          => '如果你是被自动跳转到这个页面，请见谅。日志文件中记录了此错误；提交问题时请一并附上相关日志。',
    '404_github_link'         => '如果你确定此页面应该存在，请在 <strong><a href="https://github.com/firefly-iii/firefly-iii/issues">GitHub</a></strong> 提交 issue。',
    'whoops'                  => '出错了',
    'error_message'           => '错误提示',
    'internal_server_error'   => '服务器内部错误',
    'service_unavailable'     => '服务暂不可用',
    'fatal_error'             => '出现了致命错误。请检查“storage/logs”中的日志文件或使用“docker log -f [container]”来查看发生了什么。',
    'maintenance_mode'        => 'Firefly III 处于维护模式。',
    'be_right_back'           => '马上回来！',
    'check_back'              => 'Firefly III 正在进行必要维护。请稍后再试。如果你是在演示站点看到此消息，请等待几分钟，演示数据库会每隔几小时重置一次。',
    'error_occurred'          => '哎呀！发生错误。',
    'db_error_occurred'       => '哎呀！发生数据库错误。',
    'error_not_recoverable'   => '这个错误无法自动恢复。Firefly III 遇到了问题，错误信息如下：',
    'error'                   => '错误',
    'error_location'          => '此错误发生在文件 <span style="font-family: monospace;">:file</span> 的第 :line 行，代码为 :code。',
    'stacktrace'              => '堆栈跟踪',
    'more_info'               => '更多信息',
    'collect_info'            => '请在 <code>storage/logs</code> 目录中查看日志文件。如果你使用 Docker，请运行 <code>docker logs -f [container]</code>。',
    'collect_info_more'       => '也可以参考 <a href="https://docs.firefly-iii.org/how-to/general/debug/">FAQ</a> 了解如何收集错误信息。',
    'github_help'             => '在 GitHub 获取帮助',
    'github_instructions'     => '欢迎在 <strong><a href="https://github.com/firefly-iii/firefly-iii/issues">GitHub</a></strong> 上提交新问题。',
    'use_search'              => '先搜索已有问题。',
    'include_info'            => '附上<a href=":link">调试页面</a>中的信息。',
    'tell_more'               => '请说明具体操作，不要只写“页面显示出错”。',
    'include_logs'            => '包括错误日志（见上文）。',
    'what_did_you_do'         => '说明出错前你正在进行的操作。',
    'offline_header'          => '你可能离线',
    'offline_unreachable'     => 'Firefly III 无法访问。你的设备当前处于离线状态或服务器无法运行。',
    'offline_github'          => '如果你确认设备和服务器都在线，请在 <strong><a href="https://github.com/firefly-iii/firefly-iii/issues">GitHub</a></strong> 提交 issue。',
];

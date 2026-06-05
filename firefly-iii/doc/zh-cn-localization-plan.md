# Firefly III 简体中文本土化执行方案

## 目标

将 Firefly III 的界面、提示、邮件、错误信息和前端交互文案统一为大陆简体中文表达。整体语气保持清晰、克制、产品化，适合个人记账和家庭财务管理场景。

## 覆盖范围

- 后端 Laravel 语言包：`resources/lang/zh_CN/*.php`
- v1 前端 Vue 语言包：`resources/assets/v1/src/locales/zh-cn.json`
- v2 前端 i18next 运行时语言包：`public/v2/i18n/zh_CN.json`
- 地区名称与本地化列表：`resources/locales/zh_CN/locales.json`
- 登录、注册、首次引导、首页、账户、交易、预算、账单/订阅、规则、报表、设置、个人资料、错误页和邮件

## 术语规范

| English | 简体中文 |
| --- | --- |
| transaction | 交易 |
| transaction journal / journal | 交易记录 |
| account | 账户 |
| asset account | 资产账户 |
| expense account | 支出账户 |
| revenue account | 收入账户 |
| liability | 负债 |
| withdrawal | 支出 |
| deposit | 收入 |
| transfer | 转账 |
| budget | 预算 |
| bill / subscription | 账单/订阅 |
| piggy bank | 储蓄罐 |
| rule | 规则 |
| rule group | 规则组 |
| webhook | Webhook |
| administration | 财务账套 |
| reconciliation | 对账 |

保留英文专名：Firefly III、API、OAuth、Webhook、IBAN、BIC、SEPA、JSON、CSV。

## 翻译原则

- 优先使用国内用户熟悉的记账软件表达，不逐字直译。
- 按按钮语义翻译：Save 为“保存”，Submit 为“提交”，Delete 为“删除”，Close 为“关闭”。
- 对危险操作使用明确提醒，例如“删除后无法恢复”。
- 不改动翻译 key、占位符、HTML 标签、API 字段名和业务逻辑。
- 金额和默认新用户习惯倾向人民币；已有用户偏好继续优先生效。

## 实施顺序

1. 新增完整 `zh_CN` 后端语言包，保证所有英文 key 均有中文值。
2. 补齐 v2 运行时 i18next 中文 JSON，确保登录/注册和新版页面可直接加载中文。
3. 校准 v1 `zh-cn.json` 中的核心术语，使其与后端语言包一致。
4. 将默认语言切为 `zh_CN`，默认货币偏好切为 `CNY`。
5. 扫描 Blade/Twig/JS 中无法通过语言包覆盖的硬编码英文，按模块分批处理。

## 验收清单

- `resources/lang/en_US` 与 `resources/lang/zh_CN` 文件和 key 完全一致。
- 所有 PHP 语言文件通过 `php -l`。
- v2 构建通过 `npm run build --workspace resources/assets/v2`。
- v1 构建通过 `npm run production --workspace resources/assets/v1`。
- 注册、登录、首次引导、首页、账户、交易创建/编辑、预算、账单、规则、报表、设置、个人资料和错误页能正常显示中文。
- 手动创建收入、支出、转账各一笔，检查列表、详情、通知和表单提示。

## 风险点

- 本仓库存在 v1 与 v2 两套前端，部分文案来源不同，需要分别验证。
- v2 运行时读取 `public/v2/i18n/*.json`，该目录是运行时资产，改语言包后需要重新生成/同步。
- 机器翻译只能作为第一版基线，长句、邮件和财务概念需要持续人工润色。
- 已有用户的语言偏好可能仍为 `en_US`，默认语言变更只影响未设置偏好的用户或新用户。

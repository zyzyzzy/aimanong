# AGENTS.md

本文件是 **AI Agent 在本项目中工作的强制约定**。在动手前请先读 [llms.txt](./llms.txt)。

## 项目定位

**Aimanong（AI 码农）** —— 后台开发框架，基于 Laravel 12 + Vue 3。
**使用方主要是 AI Agent，人是审阅者。**

## 目录约定

| 目录 | 用途 | Agent 是否可写 |
|---|---|---|
| `packages/framework/src` | 框架核心 | ⚠️ 谨慎，需人类 review |
| `packages/region/src` | 官方扩展（省市区） | ✅ 可写 |
| `docs/` | 文档站 + Playground | ✅ 可写 |
| `internal-docs/` | 开发文档、实测记录 | ✅ 可写 |
| `tests/` | 测试（含 `e2e/`） | ✅ 可写 |
| `scripts/` | 发版检查、demo 搭建 | ✅ 可写 |

> **2026-10-11 修订**：原文列了 `packages/ui/src` 与 `demo/app/Aimanong`，
> 但前者只有 `.gitkeep`（前端是**零构建内联 Vue**，没有独立组件包），
> 后者在本仓库根本不存在（演示项目在仓库外，由 `scripts/setup-demo.sh` 生成）。
> 对外文档写不存在的路径，会让人（和 AI）白找一圈。

## 生成代码的强制流程

1. **先查能力**：`GET /__ai/capabilities.json` 或 `php artisan ai:capabilities`
2. **写 Resource**：放 `app/Aimanong/{Name}Resource.php`
3. **必须校验**：`php artisan ai:verify`
4. **跑测试**：`composer test`
5. **不得跳过第 3、4 步**

## 代码风格

- PHP：Laravel Pint 风格，PHPStan **Level 8**
- 链式调用**扁平化**，不嵌套闭包（AI 生成嵌套闭包错误率高）
- 方法不做重载，签名唯一
- 时间计算**禁止裸调 Carbon `diffIn*`**（Laravel 12 下返回负数 float），用框架时间工具封装
- TypeScript：`strict` 模式，无 `any`

## 提交规范

Conventional Commits：`feat:` `fix:` `docs:` `refactor:` `test:` `chore:`

中文或英文描述均可，但前缀必须是英文。

### 分支策略：trunk-based

> **2026-10-11 修订。** 原文写的是「❌ 直接在 `main` 上开发（走 `feat/*` + PR）」，
> 但自 v1.3.0 起 **93 个提交全部直推 main**，这条规则**从未被执行过**。
>
> 一条不被执行的规则比没有规则更糟：它会让人以为有把关，实际上没有。
> 所以改为如实描述现状，并把「把关」落在真正会执行的机制上。

**约定**：直接提交到 `main`，依赖**提交前自检清单**（下节）作为质量关卡。
破坏性变更或需要讨论的大改，仍建议开 `feat/*` 分支。

### 🔴 提交前自检清单（强制）

每次提交前逐项过一遍，**并在提交信息或回复里报告结果**。
这不是形式 —— 清单上的每一条都对应一次真实事故：

| # | 检查项 | 不做的后果（真实发生过） |
|---|---|---|
| 1 | `composer test` 全绿 | — |
| 2 | `composer phpstan`（L8）无错误 | — |
| 3 | `composer pint -- --test` 通过 | — |
| 4 | `bash scripts/pre-release.sh --with-e2e` 全绿 | 曾有 7 项能力零覆盖而检查全绿 |
| 5 | **改了前端/模板 → 真机点一遍并看控制台** | 页面整个白屏，只看 DOM 以为在加载 |
| 6 | **改了产物生成 → 重新生成并核对四份产物** | 只读资源在 OpenAPI 里仍宣告写接口 |
| 7 | **改了能力 → 三处同步**（Capabilities / Search-Docs / DocsCommand） | 有测试兜底，但仍需自查 |
| 8 | **对外表述无失实**：README/llms.txt 提到的目录、命令、方法实际存在 | 文档教了一个不存在的方法 |
| 9 | **验收标准盯着「用户可感知的结果」**，不是中间产物 | `dangerWhen()` 加了类名却没颜色，两年没发现 |

> **第 5、8、9 条最关键**：它们对应的缺陷全部逃过了
> 「单测 + PHPStan + e2e」三道关，只有真机和人工判断能发现。

## 禁止事项

- ❌ 提交私钥、Token、`.env`
- ❌ `--force` 强推到 `main`/`develop`
- ❌ 删除他人分支或 Release
- ❌ 跳过「提交前自检清单」直接提交（见下节）
- ❌ 手写前端页面（前端由 Schema 自动渲染）

## 出错时怎么办

框架异常实现 `AiReadableException`，含 `didYouMean` / `example` / `docs` 三个字段。

1. 读 `did_you_mean`，**改一次再试**
2. 仍失败，查 `GET /__ai/capabilities.json` 确认正确 API
3. 仍失败，把完整报错交给人类，**不要反复重写**

## 验证你的产出

```bash
php artisan ai:verify     # 声明合法性 + 是否已注册 + 幽灵列检测
composer test             # 单元测试
php artisan serve         # 访问 /admin 人工确认
```

### ⚠️ 语法校验通过 ≠ 需求达标

`validate_declaration` **默认只检查语法合法性**。若任务有具体要求
（某列可搜索、每页 N 条等），**必须传 `requirements` 参数**：

```json
{"resource": "App\\Aimanong\\OrderResource",
 "requirements": {"searchable": "order_no,customer_name",
                  "sortable": "amount,paid_at",
                  "required": "order_no",
                  "per_page": 30}}
```

不传 `requirements` 就交付，等于没有验证。

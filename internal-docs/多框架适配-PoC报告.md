# 多框架适配 · PoC 报告

> 目标：验证「同一套框架层，底下可以是 Laravel 也可以是 ThinkPHP」
> 是否可行，以及**真实工作量**。
>
> 范围（用户确认）：只做 PoC ｜ ThinkPHP 8.1 ｜ Repository + 模板 ｜ 默认 Laravel、可选 ThinkPHP
>
> 环境：PHP 8.2.29 ｜ ThinkPHP 8.1.4 ｜ think-orm 4.0.51 ｜ think-template 3.0.2 ｜ SQLite
> 实验场：`/tmp/tp-poc`（骨架 + 探针，不进仓库）
> 原始证据：`internal-docs/poc-evidence/`（9 份，均为真实命令输出）

---

## 一句话结论

**Repository 抽象完全可行（22/22 通过），模板可机械转换（10/10 语法合法）——
但架构假设被推翻：只要 framework 还依赖 `illuminate/*`，它和 ThinkPHP 就装不进同一个项目。**

原方案认为「抽个接口就行」。实测发现真正的障碍不在接口层，在 **Composer 依赖层**：
`composer require aimanong/framework` 装进 ThinkPHP 项目后，**ThinkPHP 立刻无法启动**，
而 Composer 报告零冲突、零警告。

---

## 一、致命发现：装进去就炸，而且没有任何提示

### 现象

```bash
$ cd /tmp/tp-poc && composer require aimanong/framework:@dev
# ... 安装 83 个包，无冲突报告 ...

$ php -r 'require "vendor/autoload.php"; (new think\App())->initialize();'
PHP Fatal error: Uncaught Error: Class "PhpOption\Option" not found
  in vendor/illuminate/support/Env.php:254
  ...
  config/database.php(5): env('DB_DRIVER', 'mysql')
  think/Config.php(85): include('.../config/database.php')
  think/App.php(477): think\App->load()
```

`composer require` 自己也在 `post-autoload-dump` 阶段炸了：

```
Script @php think service:discover handling the post-autoload-dump event returned with error code 255
```

证据：`poc-evidence/02-tp-boot-crash.txt`、`tp-installed-packages.json`（83 个包）

### 根因：两个框架抢同一个全局函数 `env()`

| | 定义位置 | 实现 |
|---|---|---|
| ThinkPHP | `topthink/framework/src/helper.php:215`，`if (!function_exists('env'))` | `think\Env::get($name, $default)` |
| Laravel | `illuminate/support/helpers.php:150`，`if (! function_exists('env'))` | `Illuminate\Support\Env::get($key, $default)` |

两边都用 `function_exists` 保护自己。**谁先定义谁赢**：

- Composer 的 `autoload_files` 会加载 `illuminate/support/helpers.php`
- ThinkPHP 的 `helper.php` 由 `think\App::initialize()` 第 540 行 `include_once`
- → **Laravel 的 `env()` 先定义，ThinkPHP 的被静默跳过**

更糟的是，Illuminate 的 `env()` 在这个项目里**本身就是个残缺函数**：

```
$ composer show illuminate/support 的 require 字段
  → 不含 vlucas/phpdotenv，也不含 phpoption/phpoption
$ ls vendor/phpoption   → ❌ 不存在
```

`PhpOption\Option` 只有 **`laravel/framework`**（完整包）才会带进来。而
`aimanong/framework` 依赖的是零散的 `illuminate/*` 组件 ——
所以它能为任何非 Laravel 项目装上，然后**用一个缺依赖的 `env()` 炸掉宿主**。

### 冲突面（实测）

全局函数同名冲突 **7 个**：

```
env  tap  throw_if  throw_unless  class_basename  class_uses_recursive  trait_uses_recursive
```

其中只有 `env` 会立刻致命；其余 6 个是**语义静默改写**（当前恰好两边语义一致，但没有任何东西保证以后一致）。

### 能不能绕？

可以，但要靠 `public/index.php` 的加载顺序：

```php
require __DIR__.'/../vendor/topthink/framework/src/helper.php';  // 抢先定义 env()
require __DIR__.'/../vendor/autoload.php';
```

实测（`poc-evidence/` 未单独存档，可复现）：ThinkPHP 能启动、ORM 可用。
但这只是把问题从「炸」改成「7 个全局函数归属由文件加载顺序决定」——
**仍然是隐式的**，而且侵入用户入口文件。

**结论：不是一个可以打的补丁，是一个必须消除的依赖。**

### 这踩中了宪法第几条

> **第三条 零隐式（本项目最重要的一条）**
> 凡在声明层出现的，运行时必须真的生效。

`composer require aimanong/framework` 在 ThinkPHP 项目里：
**装得上、不报错、不警告、然后整个应用起不来。** 这是最坏的一种隐式。

---

## 二、Repository 抽象：可行，22/22

### 做法

不改现有 `Aimanong\Contracts\Repository`（Laravel 侧零影响），**新增**框架无关契约：

```php
// 提案：Aimanong\Contracts\PageResult
interface PageResult {
    public function items(): array;
    public function total(): int;
    public function perPage(): int;
    public function currentPage(): int;
    public function lastPage(): int;
}

// 提案：Aimanong\Contracts\DataSource（现有 Repository 的框架无关版）
interface DataSource {
    public function paginate(array $params = [], ?int $defaultPerPage = null): PageResult;
    public function create(array $data): object;
    public function update(int|string $id, array $data): object;
    public function delete(int|string $id): bool;
    public function find(int|string $id): object;   // 找不到必须抛，不能返回 null
}
```

`ThinkDataSource` 逐行对照 `EloquentRepository`（343 行）实现。

### 结果

```
① 基础分页          7/7   ✅ items/total/perPage/currentPage/lastPage/per_page覆盖/defaultPerPage
② 搜索（OR 组）      4/4   ✅ 平铺列 / 数值列 / 无命中 / Stringable
③ 排序             2/2   ✅ 普通列 / 关联列 customer.name（最难移植点）
④ 筛选             2/2   ✅ 平铺列 / 关联列
⑤ ids 批量过滤       1/1   ✅
⑥ CRUD             5/5   ✅ create/find/update/delete/找不到抛 ModelNotFoundException
⑦ 预加载 with        1/1   ✅
─────────────────────────────
合计               22/22
```

证据：`poc-evidence/03-think-datasource-test.txt`、`04-tp-relation-api.txt`

### 5 处关键差异（都有解，但都不能靠猜）

| # | Eloquent | ThinkPHP | 踩坑 |
|---|---|---|---|
| 1 | `Model::query()` | `(new Model)->db()` | TP 的 Model **没有**静态 `query()`，硬调会掉进 `__callStatic` → `Query::__call` → `DbException: method not exist`（**不是**致命错误，是运行时异常，很容易误判） |
| 2 | `Illuminate\Database\Eloquent\ModelNotFoundException` | `think\db\exception\ModelNotFoundException` | 类名相同但**命名空间在 `db` 下**；`think\exception\ModelNotFoundException` 不存在 |
| 3 | `find()` 找不到自动抛异常 | `find()` 返回 `null` | **必须自己抛**。不抛就是静默返回 null —— 正是宪法禁止的失败形态 |
| 4 | `paginate()->perPage()` | 无 `perPage()`，用 `listRows()` | 分页对象是 `think\paginator\driver\Bootstrap`，不是 `LengthAwarePaginator` |
| 5 | `orderBy($subqueryBuilder)` | `order(Db::raw('(select ... limit 1)'))` | TP 的 `order()` 只吃字符串；关联排序的「子查询 Builder」口子**不存在**，必须自己拼 SQL（字段引用符还必须随驱动变） |

第 5 条是 `EloquentRepository` 里最难的一段（`relationSubquery()`），
本次已**实测等价实现并通过**。

---

## 三、模板：可机械转换，但有 3 条硬规则

### 先说好消息：风险远小于预估

实测 `think-template` 的解析器**只对三类构造动手**：

| 输入 | think-template 输出 |
|---|---|
| `{$name}` | ✅ 求值 |
| `{if $a}…{/if}` | ✅ 求值 |
| `{/* 注释 */}` | ✅ 去掉 |
| `const { createApp, ref } = Vue;` | ✅ **逐字节原样** |
| `function f() { return 1; }` | ✅ 逐字节原样 |
| `.a { color: red; } @media (max-width:600px) { … }` | ✅ 逐字节原样 |
| `<span>{{ msg }}</span>`（Vue mustache） | ✅ **逐字节原样** |
| `<div @click="open = true">` | ✅ 逐字节原样 |
| `{!! $path !!}`（Blade raw echo） | ❌ 原样输出（需转换，只有 1 处） |

**最关键的一条**：`resource.blade.php` 里有 **826 行内联 JavaScript、171 个 `{`**，
喂进去**逐字节一致通过**（`poc-evidence/` 探针输出）。

而且 Blade 需要 `@{{ }}` 来转义 Vue 的 mustache（本项目用了 **50 处**），
**think-template 根本不需要转义** —— 这 50 处反而是减负。

### 转换面（10 个模板 / 3,141 行）

| Blade 构造 | 数量 | TP 对应 |
|---|---:|---|
| `{{ expr }}` 复杂表达式 | 67 | `<?php echo expr; ?>` |
| `@{{ }}` Vue 转义 | 50 | `{{ }}`（去掉 `@`） |
| `{{-- --}}` 注释 | 45 | `{/* */}` |
| `@include(...)` | 34 | `{include file="..." /}` |
| `{{ $var }}` 简单变量 | 27 | `{$var}` |
| `@if/@elseif` | 15 | `{if}/{elseif}` |
| `@php` | 10 | `{php}` |
| `@json(...)` | 10 | `<?php echo json_encode(...); ?>` |
| `@foreach/@forelse` | 8 | `{volist}` |
| `@checked/@switch/@case` | 8 | 需展开为 `{if}` |
| `@extends` | 4 | `{extend}` |
| `@push/@stack` | 4 | **TP 无对应机制** |
| `@section` | 3 | `{block}` |
| `@csrf` | 2 | `<?php echo csrf_token(); ?>` |
| `@yield` | 1 | `{block}` |
| `{!! !!}` | 1 | `{:$x\|raw}` |

### 实测结果

写了一个机械转换器（`poc-evidence/blade2tp-poc-converter.py`），跑完 10 个模板：

```
模板                   think-template 编译产出  备注
----------------------------------------------------------------------------
partials/icon          ✅ 语法合法            6 KB
partials/topbar        ✅ 语法合法           14 KB
partials/theme-panel   ✅ 语法合法           15 KB
partials/sidebar       ✅ 语法合法           40 KB
partials/layout        ✅ 语法合法           73 KB
partials/upload        ✅ 语法合法            9 KB
index                  ✅ 语法合法           73 KB
auth/login             ✅ 语法合法           60 KB
profile                ✅ 语法合法           73 KB
resource               ✅ 语法合法           73 KB
----------------------------------------------------------------------------
编译产出语法合法: 10 / 10
```

> 注意：这是**语法层**的通过。运行时还需要框架 PHP 层（`Aimanong\Aimanong`、
> `url()`、`csrf_token()` 等），那正是适配器要提供的东西，PoC 范围外。

证据：`poc-evidence/06-template-lint.txt`、`07-template-conversion-report.txt`

### 3 条硬规则（都是实测踩出来的，不是推测）

**规则 1：partial 里不能写 `use` / 函数定义。**
think-template 的 `{include}` 是**编译期内联**（Blade 是运行期独立渲染）。
同一 partial 被 include 两次，它的 `use` 就在同一份编译产物里出现两次：

```
PHP Fatal error: Cannot use Aimanong\Ui\ThemeConfig as ThemeConfig
                 because the name is already in use ... on line 952
```

`partials/layout.blade.php:18` 和 `partials/theme-panel.blade.php:15`
**各写了一行 `use Aimanong\Ui\ThemeConfig;`** → 一次炸掉 4 个页面模板
（`layout` / `index` / `profile` / `resource`）。

修掉这一条后通过率从 **5/10 → 10/10**：其中 **4 个**由本规则修复，
另 **1 个**（`partials/upload`）是转换器自身的 `@{{ }}` 解析 bug，与本规则无关。

> 推论：partial 的变量也会泄漏进父模板作用域（`icon` 的 `$name/$size/$stroke`
> 在 `resource` 里被内联了 6 次）。**partial 必须是纯模板，不能有状态。**

**规则 2：注释里不能出现 `@include` 用法示例。**
`{include}` 展开**不认注释块**。`partials/icon.blade.php` 的文档注释里正好写了自引用示例：

```
## 用法
  @include('aimanong::partials.icon', ['name' => 'article', 'size' => 16])
```

→ 转换后变成真的自引用 → **无限递归，内存耗尽**（不是报错，是挂死）。

**规则 3：`{{ expr }}` 必须走原生 PHP，不能用 `{:...}`。**
`tpl_deny_php` **默认 `false`**，原生 PHP 可用。而 `{:...}` **只认函数调用语法**：

| 写法 | 结果 |
|---|---|
| `<?php echo "A".(1+1); ?>` | ✅ `A2` |
| `<?php echo $a ? "y" : "n"; ?>` | ✅ `y` |
| `{:date("Y")}` | ✅ `2026` |
| `{:1+1}` | ❌ **原样输出 `{:1+1}`**（静默不执行） |
| `{$a ? "y" : "n"}` | ✅ `y` |

`:...}` 静默不执行这一点很危险 —— 又一个「零隐式」问题。

### 96 条「待人工项」的真实性质

机械转换器报告 96 条待人工项，但**大部分不是机械劳动**：

| 类别 | 数量 | 真实性质 |
|---|---:|---|
| `@include` 带数据数组 | 30 | 属性语法是**文本替换** `[k]`，复杂数据要改写；语义核对 |
| Laravel helper（`url()`/`asset()`/`csrf_token()`/`old()`/`$errors`） | 20 | **需要框架抽象层**（这正是 core 要提供的） |
| `@php` 块 | 8 | partial 里的必须上提（规则 1） |
| `@push/@stack` | 3 | TP 无对应机制，**需重新设计** |
| `@extends/@section` | 4 | 继承体系需逐页核对 |
| `@checked/@switch/@case` | 2 | 需手工展开为 `{if}` |

---

## 四、完整 CRUD

- **数据层：已通** —— 22/22（含 create/update/delete/find 与关联）
- **HTTP 层：未做** —— 需要路由/中间件/认证驱动，PoC 范围外（用户确认只做 Repository + 模板）

---

## 五、结论：架构假设被推翻，必须拆包

原方案的路线是「抽个接口 → 换实现」。实测证明这条路走不通，因为
**只要 `aimanong/framework` 的 `require` 里有 `illuminate/*`，它就装不进 ThinkPHP 项目。**

所以真实架构只能是**拆包**（依据是分层实测数据）：

```
aimanong/core       框架无关内核
                    DSL 层（Grid/Form/Show/Tree）+ Schema 编译 + Contracts
                    实测：DSL 层 Illuminate 依赖 0 处；Schema 层 1304 行仅 3 处
aimanong/laravel    Laravel 12 驱动：Eloquent / Blade / ServiceProvider / Auth / Console / MCP
aimanong/thinkphp   ThinkPHP 8.1 驱动：ThinkDataSource / think-template / TP 路由·中间件·认证
aimanong/framework  元包（require core + laravel）
                    老用户 `composer require aimanong/framework` 行为不变
```

**核心收益**：ThinkPHP 项目装 `aimanong/thinkphp`，**永远不会接触到 `illuminate/*`**，
`env()` 冲突从根上不存在。

### 同时建议立刻做的一件事（止血，与多框架是否推进无关）

给 `packages/framework/composer.json` 加：

```json
"conflict": {
    "topthink/framework": "*"
}
```

**理由**：现在这个包**能被装进 ThinkPHP 项目**，而且装完是**静默致命**。
加这一行，Composer 会明确拒绝并说明原因 —— 从「零隐式」变成「显式拒绝」。
成本一行，收益是消除一个真实的、会炸掉别人项目的雷。

> #### ⚠️ 实测补充：这条守卫的边界（发布 v1.8.1 后才发现，上面这段论证不充分）
>
> 上面只验证了「pin 版本能拒绝」，**没验证默认写法会怎样**。
> 用 Packagist 上真实发布的包重测：
>
> | 命令 | 结果 |
> |---|---|
> | `composer require aimanong/framework:^1.8.1` | ✅ 明确拒绝 + 自动回滚 |
> | `composer require aimanong/framework`（默认写法） | ❌ **回退解析到 1.8.0**，照常装上 |
>
> 因为 **Packagist 上已发布的版本不可变**，1.8.0 的 composer.json 里没有
> conflict，也无法追溯补上；Composer 求解器在 1.8.1 冲突后**向下回退**。
>
> 也就是说：**默认写法仍然能把这个包装进 ThinkPHP 项目并炸掉它。**
>
> 要彻底堵住只有两条路：
> 1. 从 Packagist 删除 `v1.8.0`（迫使解析落到 1.8.1）——
>    代价是锁定了 1.8.0 的用户 `composer install` 会报包不存在
> 2. 完成**拆包**，从根上消除冲突
>
> 另外，「补 `vlucas/phpdotenv` 让 `env()` 能用」这条看似可行的路**是错的**：
> Illuminate 的 `Env` 读的是**进程环境变量**，而 ThinkPHP 从不把 `.env`
> 灌进那里 —— 结果是 `env('DB_DRIVER')` 返回默认值 `mysql`，
> 把「响亮的崩溃」换成「静默连错数据库」，更糟。

> 顺带暴露的更深问题：`aimanong/framework` 用零散 `illuminate/*` 而不是
> `laravel/framework`，导致它**能装到任何非 Laravel 项目**，然后以一个
> 缺依赖的 `env()` 炸掉宿主。`conflict` 只是止血；根治就是拆包后的 `core`
> 根本不依赖 illuminate。

---

## 六、工期修正

| 阶段 | 原估 | 实测后 | 差异原因 |
|---|---|---|---|
| PoC | 2~3 天 | **已完成**（本次） | — |
| 拆包 + 抽 core | （未列） | **3~5 天** | 原估完全没算这一步；要动 composer 结构、CI、四产物生成链路、发布脚本 |
| ThinkPHP 驱动 | 4~6 天 | **8~12 天** | 认证/Console/MCP 三域原估偏低；关联排序等 5 处差异都要真写 |
| 模板转换 + 核对 | 3~5 天 | **3~4 天** | 转换已证明可自动化（10/10），核对是大头 |
| 双框架 e2e | 2 天 | **3~5 天** | 两套 CI 矩阵 + 两套 demo 骨架 |
| **合计** | **10~15 天** | **约 4~6 周** | 主要因为必须拆包 + 模板 3 条硬规则要改现有写法 |

**上修的主要来源**：①「抽接口」→「拆包」是量级差异 ②模板 3 条硬规则要求改动现有模板的写法约定 ③三个驱动域（认证/Console/MCP）原估偏低。

---

## 七、证据索引

| 文件 | 内容 |
|---|---|
| `poc-evidence/01-tp-native-probe.txt` | ThinkPHP 原生 Query API 覆盖（22 项） |
| `poc-evidence/02-tp-boot-crash.txt` | 装完 framework 后 TP 启动致命错误的完整堆栈 |
| `poc-evidence/03-think-datasource-test.txt` | ThinkDataSource 22/22 通过明细 |
| `poc-evidence/04-tp-relation-api.txt` | TP 关联对象 API 反射结果 |
| `poc-evidence/06-template-lint.txt` | 10 个模板编译产出语法合法性 |
| `poc-evidence/07-template-conversion-report.txt` | 转换面统计 + 96 条待人工项分类 |
| `poc-evidence/blade2tp-poc-converter.py` | PoC 用机械转换器（含 3 条硬规则的注释说明） |
| `poc-evidence/tp-composer-after-require.json` | 装完 framework 后的 composer.json |
| `poc-evidence/tp-installed-packages.json` | 83 个已安装包（20 个 illuminate/* + laravel/mcp） |

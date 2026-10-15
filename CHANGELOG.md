# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 与 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

## [Unreleased]

### 新增：声明式自定义批量动作

```php
$grid->batchAction('ship', '标记为已发货')->handler(ShipOrders::class);
```

处理器实现 `Aimanong\Contracts\BatchActionHandler`（`handle(string $model, array $ids): int`）。

**为什么是「引用类名」而不是「传闭包」**：ADR-003 定的是「声明层只放数据」——
声明要被序列化、自省、被 AI 读、被跨框架执行，闭包这四件都做不到。
但「不收闭包」不等于「不能有自定义动作」：中间那条路是**引用**。

在此之前 `batchActions()` 是硬白名单 `{delete, export}`。实测中一个 LLM 为做
「批量改状态」，把 23 个可能的动作名逐个塞进去全被拒，最后自己写扩展 +
注入 JS + 自建路由 —— 而它自己在报告里标出那条路丢了**权限校验**与**字段校验**。

走框架这条路，三个缺口都不存在：

| | 自建路由 | `batchAction()` |
|---|---|---|
| 权限 | 丢（要自己写） | `{uri}.{动作名}` 节点自动生成 |
| id 校验 | 丢 | 走框架的 `ids.*` 校验 |
| 操作日志 | 丢 | 框架自动记 |
| 依赖 | 未公开的钩子 | 公开契约 |

声明了动作却没给处理器 → **编译期**报错（否则运行期表现为「点按钮 501」）。

### 新增：外键下拉做成一等公民（`$form->belongsTo()`）

```php
$form->belongsTo('author_id', '作者');                      // 关联名从字段名推断
$form->belongsTo('author_id', '作者')->labelColumn('name');  // 指定显示列
```

候选与 `exists` 规则**自动**从模型关联解析（新增 `RelationResolver` 契约，
两端各一实现）。此前要手写三件事，而三件都是易错点：

| 原先手写 | 写错的后果（实测） |
|---|---|
| `Author::pluck('name','id')->all()` | 忘了 `->all()` 编译失败（传的是 Collection） |
| `->rules('exists:authors,id')` | 忘了 → 前端能提交任意 id |
| 表名 | 写错不报错，一路错到运行时（`categorys` vs `categories`） |

`belongsTo` 在 schema 里的 **type 是 `select`**（控件语义）—— 写成
`belongsTo` 会让字段掉进 `<input>` 分支，变成人手填 id 的文本框，
与这个字段存在的意义正好相反。它「是关联」这件事由 `props.relation` 表达。


### 新增：方法名猜错时给出可自愈报错（`did_you_mean`）

PHP 原生只回一句 `Call to undefined method ...::showWhen()`，而现在：

```
Text 上不存在方法 showWhen()。
你是不是想调用: visibleWhen()
可用方法: dateFormat, dateTime, default, ..., required, ..., visibleWhen
```

覆盖 `Field` / `Column` / `Grid` / `Show` / `Tree` 五个声明层 API 类
（`Form` 的字段类型分发也接上了，保留原有的「可用字段类型」清单）。

**两条判据**，因为两类错法不一样：

| 错法 | 例子 | 靠什么认出 |
|---|---|---|
| 拼写错误 | `requried` → `required` | 编辑距离 |
| **用词不同** | `showWhen` → `visibleWhen` | **公共后缀**（`when`） |

第二条是必须的：`showWhen` 与 `visibleWhen` 编辑距离 6、字符串相似度 0.53 ——
它压根不是拼写错误，任何编辑距离阈值都够不着。

> 实测背景：一个 LLM 为确认「`visibleWhen` 到底叫什么」，**反射了 142 个框架类**。
> 差别不在模型强弱，只在框架有没有把**已经知道的信息**说出来。

### 新增：条件显示与按钮级权限（声明层，两端一致）

**条件显示** —— `$form->select('priority')->visibleWhen('type', 'bug')`
（或 `->visibleWhen('type', ['bug','feature'])`）。被条件藏起来的字段**提交时会被清空**。

在此之前框架没有条件显示的声明方式，而 `hidden()` 只能静态隐藏。
实测代价：一个 LLM 为做「选 A 显示 B」，翻遍 `llms.txt`、自省接口、反射了
142 个框架类，最后**自己写了一个扩展**（注册字段类型 + 注入 JS）。
声明一句就该解决的事，变成了每个项目各自实现一遍。

**按钮级权限** —— 无需声明。权限节点 `{uri}.{action}` 框架本来就会生成，
页面按它决定渲染哪些按钮：没有 `destroy` 权限的人，列表里
**根本没有**删除按钮（**服务端不渲染**，不是 CSS/JS 藏起来）。

### 🔴 ThinkPHP：RBAC 的四个关联全写反了（一直不生效）

ThinkPHP 的 `belongsToMany($related, $middle, $foreignKey, $localKey)` 里后两个
参数的语义与 Laravel **相反**（TP：先「指向关联模型的列」，Laravel：先「指向本模型的列」）。
四个关联都按 Laravel 的顺序写了，于是 TP 生成的 SQL 把两个键对调：

```sql
ON pivot.role_id = admin_permissions.id   WHERE pivot.permission_id = ?   -- 恒空
```

后果：**「角色有哪些权限」在 ThinkPHP 上永远查不出来**，而且不报错、只是空数组。
更阴的是它可能**碰巧查对** —— `$user->roles` 就返回了正确结果（role id 与 user id
相同），让人以为关联没问题，直到去查 `$role->permissions` 才发现恒为空。

同时补上 ThinkPHP 缺失的**权限节点同步入口**（此前根本没有
`aimanong:permission` 命令 —— 节点永远建不出来，除超管外所有人访问任何页面都是 403）。

> 这三个 bug 叠在一起，让 ThinkPHP 的 RBAC 一直是「摆设」，
> 而 e2e 的「普通角色被拒」**一直是假绿** —— 它被拒不是因为权限生效，
> 而是因为角色压根没绑上 / 关联查不出来。直到要给一个非超管账号**开**权限
> （验按钮级权限），才发现「怎么开都没用」。

**这一节的教训**：「断言了拒绝」不等于「验证了权限系统」。
只有同时验证「有权者能进」和「无权者被拒」，才证明得了。

### 顺带

`PermissionNodes`（动作定义与中文标签）与 `TreeBuilder` 一并移入 core ——
都是框架无关的声明层语义，两边各写一份必然漂移。

### 🔴 安全：树形接口的越权（请尽快升级）

`tree()` 与 `move()` 此前**没有做权限校验**，而控制器的其余动作都有。
实测（一个对 `departments` 零权限的账号）：

```
GET  /admin/api/departments        → 403  ✅ 正确拦截
GET  /admin/api/departments/tree   → 200  ❌ 返回整张表
PUT  /admin/api/departments/4/move → 200  ❌ parent_id 真的被改了
```

`authorize_()` 一次调用同时负责三件事：跨后台归属（404）、只读资源、RBAC ——
漏掉它这三样全丢。`batch` 也存在同类问题（声明检查排在授权之前，
未实现的分支整条路径无授权），一并修好。

新增**结构性守卫**：`ControllerPermissionCoverageTest` 枚举控制器的全部公开动作，
任何一个没调 `authorize_()` 就红（白名单里每项都必须写明理由）。
这样「新增动作忘了鉴权」不会再靠人工 review 兜住。

### 新增：树形资源真正能用了（两端）

之前是**半成品**：后端 `tree()` 声明 ✅、`/tree` 接口 ✅、前端 `flattenTree()`
算了 `_depth` ✅、design-system 里 `.am-tree-toggle` 连旋转动画都写好了 ✅
—— **但模板从头到尾没用它们**。于是声明了 `tree()` 的列表看起来仍是一条条
平铺的名字，而且**不报任何错**。实测确认：只声明 `tree()` 达不到
「能看出层级」这条验收标准。

现在：按 `_depth` 缩进 + 父节点展开/折叠（`displayRows` 过滤被折叠的子孙）。

- **core**：`TreeBuilder` 从 laravel 包移到 core（零依赖的纯数据结构，两端共用同一套建树/环检测）
- **thinkphp**：补 `/api/{uri}/tree` 接口（此前明确返回 501）

两端渲染结果逐项一致（缩进档位、折叠开关位置都相同）。

> ⚠️ 从源码或 path 仓库安装的，升级后需要跑一次 `composer dump-autoload`
> （`TreeBuilder` 换了包，composer 的 classmap 要重生成）。正常
> `composer update` 会自动重建。

### 包的版本

| 包 | 版本 | 内容 |
|---|---|---|
| `aimanong/core` | **1.6.0** | 外键下拉 `belongsTo()` · 声明式自定义批量动作 · `RelationResolver` 契约 |
| `aimanong/laravel` | **1.3.0** | 批量动作分发 · 外键下拉驱动 |
| `aimanong/thinkphp` | **1.4.0** | 补 **batch 端点**（此前完全未移植）· 外键下拉驱动 |

> ⚠️ 三个包都要一起升。`laravel` / `thinkphp` 本版要求 `aimanong/core: ^1.4`
> （`TreeBuilder` 换了包）。只升驱动不升 core 会解不出依赖。
>
> 从源码或 path 仓库安装的，升级后跑一次 `composer dump-autoload`
> （classmap 里 `TreeBuilder` 的旧路径要重生成）。正常 `composer update` 会自动做。

### aimanong/thinkphp 1.1.0（资源页面可用）

1.0.2 的 ThinkPHP 后台**只有控制台一个页面** —— 侧边栏每个菜单项点进去都是 404。

本版补上：

- **资源页面（HTML）路由** —— `/admin/customers` 等现在渲染列表/表单
- **静态资源路由** —— 设计系统 CSS / 主题 / Vue 运行时 / LOGO
  （此前全部 404，后台**完全没有样式**而页面返回 200，不报任何错）
- 修 **Vue 插值**被模板转换器误转成 `<?php echo total; ?>` 导致的页面 500
- 补 `favicon-32.png`（布局一直引用它，而文件从未存在 → 每次加载一次 404）
- `Aimanong::url()` 空参不再带尾斜杠（点 LOGO 回首页曾 404）

升级：

```bash
composer require aimanong/thinkphp:^1.1.0
```

**仍未移植**：个人中心页（`/admin/profile` 404）、`batch` / `export` / `move`
（返回 501）、内置 Resource（管理员/角色/日志/字典页面）。

### aimanong/laravel 1.1.0（多应用落地）

1.0.2 里多应用处于「配了但没接上」的状态（`ApplicationManager` 未注册进
`Drivers`），后果是**静默失效**：菜单不过滤、权限 slug 不加前缀、不报错。

1.1.0 补全：

- `ApplicationManager` 实现 `ApplicationResolver` 并注册进 `Drivers`
- HTTP 下当前应用按**请求路径**推断（此前恒为默认应用，非默认后台权限全拒）
- CLI 下不读进程级残留（此前跨命令串应用）
- 权限 slug 按后台加前缀（`admin.orders.index`）；**单应用零影响**
- `admin_role_user` 加 `user_type` 列（迁移自动回填既有数据）——
  修复多用户表 id 碰撞导致的**角色串台（越权）**
- 跨后台访问返回 **404**（不是 403，避免泄露资源存在）
- `aimanong:permission migrate-permissions --to=<app>`：升级迁移命令，
  旧节点保留、可回滚
- 内置 Resource（管理员/角色/日志/字典）声明 `@primary`：
  主后台改名（不叫 admin）也正确归属

升级：

```bash
composer require aimanong/laravel:^1.1.0
php artisan migrate        # user_type 迁移会自动回填
```

**从单应用升级的项目**：开启多应用后跑一次
`php artisan aimanong:permission migrate-permissions --to=admin`。

### 🔴 aimanong/thinkphp 1.0.2（建议尽快升级）

**1.0.1 的 ThinkPHP 后台页面只有布局、没有正文** —— 侧边栏与主题面板正常，
但页面主体一个字都没有，且**不报任何错**。

根因：think-template 的布局占位符**不支持自闭合形式**。
`{block name="content" /}` 不报错，但替换那一步匹配不到。
必须写 `{block name="content"}{/block}`。

影响：控制台首页不可用。**Laravel 侧不受影响**。

同时修复：`HomeController` 曾把 `roles` / `permCount` / `isSuper` 硬编码为空，
叠加后表现为**超级管理员登录后被告知「你还未被分配任何权限」**。

升级：

```bash
composer require aimanong/thinkphp:^1.0.2
```

> 若你从 Blade 迁移过模板：请确认布局里没有自闭合的 `{block name="x" /}`。
> 仓库门禁已加第 5 条硬规则专门拦这个（`scripts/check-think-templates.sh`）。

## [1.11.0] - 2026-10-14

> 主题：**许可变更为 Apache License 2.0**（此前为 MIT）。

### 为什么改

MIT **没有明文专利授权**。Apache-2.0 第 3 条明确授予贡献者与使用者的
专利许可 —— 对企业用户（尤其要过法务的项目）这是实质差别。
同时与 ThinkPHP 驱动更一致（其上游 ThinkPHP 本身即 Apache-2.0）。

### 这不是「收窄」

| | MIT | Apache-2.0 |
|---|---|---|
| 商用 / 修改 / 分发 / 闭源 | ✅ | ✅ **完全一样** |
| 专利授权 | 无明文 | **明文授予** |
| 商标 | 未提及 | 明文**不**授予（第 6 条） |

**用户的实际自由度没有减少**，只是在专利上多了一层保障。

### ⚠️ 已发布版本永远是 MIT

MIT 不可撤回。以下版本**保持 MIT**，不受本次变更影响：

| 包 | 保持 MIT 的版本 | 从哪个版本起是 Apache-2.0 |
|---|---|---|
| `aimanong/framework` | ≤ 1.10.1 | **1.11.0** |
| `aimanong/core` | ≤ 1.1.0 | **1.1.1** |
| `aimanong/laravel` | ≤ 1.0.1 | **1.0.2** |
| `aimanong/thinkphp` | ≤ 1.0.0 | **1.0.1** |
| `aimanong/region` | ≤ 1.0.0 | **1.0.1** |

### 顺带修了一个合规缺口

拆包之后**每个包独立发布，但都没有 LICENSE 文件** ——
用户从 Packagist 装下来拿不到许可全文。

这在 MIT 下只是不规范，在 **Apache-2.0 §4(a)（必须向接收者提供许可副本）**
下是明确的义务。现在 5 个包各自带一份 `LICENSE`，
并有 CI 断言保证它们与根 `LICENSE` 逐字一致。

## [1.10.1] - 2026-10-14

> 主题：修掉一个**只有真从 Packagist 装一次才会暴露**的发布事故。

### 事故：`aimanong/core` 1.0.0 在 Packagist 上是旧的

装 `aimanong/thinkphp` 1.0.0 后一跑就 `Class "Aimanong\Aimanong" not found`。

原因是：core 这一轮新增了公开入口 `Aimanong`、`Contracts\DataSource` /
`FrameworkRuntime` / `PageResult`、`Ai\ScopeHooks`、`Ai\Verifier`，
但 `version` 一直没升（还是 1.0.0）—— 发版脚本按版本派生包 tag，
于是**同名 tag `v1.0.0` 被 force-update 到新 commit**，
而 **Packagist 不会重新索引一个已存在的 tag**：

    远端 tag v1.0.0 → f5f1d673（新，含 Aimanong.php）
    Packagist 记录  → 50bbc0d3（旧，不含）

修法：core 升到 **1.1.0**（它确实新增了公开 API），新 tag 会被正常索引。

> 又一次印证：**仓内测试全绿 ≠ 用户装得到对的东西**。
> demo 用 path 仓库指向本地源码，永远看不到「已发布的包是旧的」。

### 变更

- `aimanong/core` 1.0.0 → **1.1.0**（新增公开入口与契约）
- `aimanong/framework` 1.10.0 → **1.10.1**



> 主题：**多框架支持** —— 同一套 Resource 声明，Laravel 12 与 ThinkPHP 8 都能跑。

### 新增：`aimanong/thinkphp`（ThinkPHP 8 驱动）

框架拆成「内核 + 驱动」之后的第一条新驱动：

| 能力 | 状态 |
|---|---|
| 5 个契约实现（数据源 / 内省 / 字典 / 权限 / 上传） | ✅ |
| 模板层（Blade → think-template 转换器 + 4 条硬规则） | ✅ |
| 后台页面 `/admin` | ✅ |
| 数据 API `/admin/api/{uri}` | ✅ |
| 认证（登录 / 会话 / 记住我） | ✅ |
| 登录限流（每 IP / 每 IP+账号） | ✅ |
| RBAC（角色 / 权限 / 超管绕过） | ✅ |
| Console（`aimanong:install` / `ai:verify` / `aimanong:schema`） | ✅ |
| 多租户数据作用域（`ScopeHooks`） | ✅ |
| batch / export / tree / move | ⬜ 明确返回 501 |

**关键前提**：`aimanong/core` 是零 illuminate 依赖的，
所以 ThinkPHP 项目里 `vendor/illuminate` 根本不存在 ——
这消灭了原本的致命冲突（`illuminate/support` 抢注全局 `env()`，
导致 ThinkPHP 启动即 fatal）。

### 修复：全面验收发现的 8 处高危语义缺口

详见 `internal-docs/第4段-全面验收报告.md`。其中最严重的几条：

- **多租户隔离在 TP 上失效** —— `ScopeHooks` 未接，跨租户数据全量返回
- **只读资源在 TP 上可写** —— 而共享的 OpenAPI 产物却按 readonly 省略了写接口
- **多对多关联被静默丢弃** —— 提交成功但不写中间表（幽灵能力）
- **会话固定 / 限流大小写绕过** —— 安全问题

### 修复：`aimanong/thinkphp` 此前没有静态分析

补上 PHPStan Level 8 + Pint 后**第一次跑就抓到 3 个真问题**，
其中 `topthink/think-template` 根本不在包依赖里 ——
用户单独装这个包时渲染视图必然致命（demo 因为自己手加了依赖才没暴露）。

## [1.9.1] - 2026-10-13

> 主题：修掉 1.9.0 里一个**只有真装一次才看得见**的版本号 bug。

### 修复：后台界面显示的版本号是内部包的，不是产品版本

1.9.0 发布后做验收（全新项目从 Packagist 装 `aimanong/framework`）时发现：
界面显示 **1.0.0**，而产品版本是 1.9.0。

根因在 `Aimanong::version()` 的解析顺序：

- `aimanong/framework` 是 metapackage，**Composer 不为它建目录**，
  所以「读 framework/composer.json」这条路径落空；
- 于是落到下一候选 —— **本包（laravel）自己的 composer.json** → 1.0.0。

修法：把「用 Composer 的 `InstalledVersions` 取产品包版本」提到最前，
且**只认 `aimanong/framework`**。

> 只认 framework 这点也是踩出来的：Composer 会把 **root 包**一并放进
> `InstalledVersions`，而驱动包在仓库里跑测试时它自己就是 root ——
> 把 `aimanong/laravel` 也列进候选，测试环境就会返回 laravel 的版本。

这个 bug 单测抓不到（测试环境里 root 包的情况与真实安装不同），
只有「从 Packagist 真装一次并打开界面」才能发现 —— 属于 AGENTS.md
第 9 条「验收标准盯着用户可感知的结果」那一类。

### 变更：包 tag 由各包自己的版本派生

发布脚本原先把**主仓库的 tag 名**原样推到包仓库。主仓库 tag 是产品级的
（`v1.9.0`），而各包有自己的版本线 —— 原样推会在 laravel 仓库上造出一个
根本不存在的 `v1.9.0`，Packagist 就会列出 laravel 1.9.0。
（第一次发 laravel 时 `v1.9.0` 还没打，侥幸跳过了。）

现在远端 tag 一律用「该提交上本包 composer.json 的 version」。

## [1.9.0] - 2026-10-13

> 主题：**把框架拆成「内核 + 驱动」，为多框架适配铺路**。
>
> 对使用者**没有可见变化** —— `composer require aimanong/framework`
> 照旧、命名空间照旧、后台照旧。改的是包的内部结构。

### 变更：`aimanong/framework` 变成元包

一次安装现在会引入三个包：

| 包 | 版本 | 作用 |
|---|---|---|
| `aimanong/framework` | 1.9.0 | **元包** —— 用户装的、对外的产品版本 |
| `aimanong/laravel` | 1.0.0 | Laravel 12 驱动（Eloquent / Blade / 路由 / 认证 / 控制台 / MCP） |
| `aimanong/core` | 1.0.0 | 框架无关内核（声明 DSL + 四产物编译）**零 illuminate 依赖** |

**为什么要拆**：`aimanong/framework` 原先把 `illuminate/*` 列为直接依赖，
于是能被装进任何非 Laravel 项目，然后以一个残缺的全局 `env()` 炸掉宿主
（详见 [多框架适配-PoC报告.md](./internal-docs/多框架适配-PoC报告.md)）。
拆出零依赖的 `core` 之后，这个可能性从根上消失。

### 新增：驱动接缝

`core` 与驱动之间只有一个接缝 —— `Aimanong\Support\Drivers`：

- `SchemaIntrospector` —— 幽灵列检测所需的内省（表名 / 列清单 / 关联）
- `DictionarySource` —— 数据字典
- `PermissionChecker` —— 权限判定
- `UploadSpec` —— 上传能力描述

**两类缺失，处理方式不同**：字典 / 权限 / 上传缺驱动时**抛异常**
（声明了能力却兑现不了就是幽灵能力）；Schema 内省缺驱动时返回 null
（这项检查本就尽力而为）。

### 变更：`Aimanong::version()` 的语义

拆包后版本号有歧义（三个包各有自己的版本）。现在明确：
**对外版本号 = `aimanong/framework` 的版本**，后台界面、README 徽章、
文档里写的都是它。内部包升版不影响用户看到的数字。

### 顺带修复

- `aimanong/core` 里不再可能混入 `illuminate/*` 依赖 —— 新增 CI 硬门禁
  `scripts/check-core-purity.sh` 拦住（已用真实反例验证会拦）。
- 发布脚本增加**依赖守卫**：发 `aimanong/framework` 前确认它依赖的
  `aimanong/*` 已在 Packagist 上，否则会发出一个「永远装不上」的包。
- e2e 脚本补上**退出码保证**：跑不完或断言失败必须非 0。
  此前 Laravel 的全局异常处理器会把崩溃渲染出来却**不改变退出码**，
  于是 `pre-release.sh` 把 `92 / 94`、`9 / 24` 都显示成「✅ 通过」。
  修完后真实结果是 `95 / 95`、`24 / 24`。

## [1.8.1] - 2026-10-13

> 主题：**堵掉一个「装得上、不报错、然后炸掉宿主」的坑**。
>
> 这一版来自多框架适配 PoC 的实测发现，改动只有一行，
> 但消除的是本项目**最严重的一类缺陷**（宪法第三条「零隐式」）。

### 修复：装进 ThinkPHP 项目会导致对方整个应用起不来

```bash
$ composer require aimanong/framework     # Composer 报【零冲突】，安装 83 个包「成功」
$ php -r '... (new think\App())->initialize();'
PHP Fatal error: Class "PhpOption\Option" not found
  in vendor/illuminate/support/Env.php:254
```

**根因**：两个框架都定义全局函数 `env()`，各自用 `function_exists` 保护：

| | 定义位置 | 实现 |
|---|---|---|
| ThinkPHP | `topthink/framework/src/helper.php:215` | `think\Env::get()` |
| Laravel | `illuminate/support/helpers.php:150` | `Illuminate\Support\Env::get()` |

Composer 的 `autoload_files` 先加载 Illuminate 的，ThinkPHP 的被**静默跳过**。
而 `illuminate/support` 并不依赖 `phpoption/phpoption`（那是完整 `laravel/framework` 才带的），
所以这个 `env()` 在非 Laravel 项目里**本身就是残缺的** —— 一调用就炸。

全局函数同名冲突共 **7 个**：`env` `tap` `throw_if` `throw_unless`
`class_basename` `class_uses_recursive` `trait_uses_recursive`。

**修法**：`composer.json` 增加

```json
"conflict": { "topthink/framework": "*" }
```

**效果**（真实实测，用 Packagist 上发布的包）：

```
$ composer require aimanong/framework:^1.8.1     # 在 ThinkPHP 8.1 项目里
  Problem 1
    - aimanong/framework 1.8.1 conflicts with topthink/framework *.
Installation failed, reverting ./composer.json and ./composer.lock to their original content.
```

### ⚠️ 这条守卫的边界（务必知道）

**它只对 1.8.1 及以后的版本生效，挡不住 1.8.0。**
Packagist 上已发布的版本是**不可变**的，1.8.0 的 composer.json 里没有这个 conflict，
也无法追溯补上。而 Composer 求解器在 1.8.1 冲突后会**向下回退**：

| 命令 | 结果 |
|---|---|
| `composer require aimanong/framework:^1.8.1` | ✅ 明确拒绝，自动回滚 |
| `composer require aimanong/framework`（默认写法） | ❌ 回退解析到 **1.8.0**，照常装上 |

所以：**默认写法仍然能把这个包装进 ThinkPHP 项目并炸掉它。**
要彻底堵住，只有两条路：

1. 从 Packagist 删除 `v1.8.0`（它会迫使解析落到 1.8.1）——
   代价是锁定了 1.8.0 的用户 `composer install` 会报包不存在
2. 完成**拆包**（框架无关内核不依赖 `illuminate/*`），从根上消除冲突

初次发布时只验证了「pin 版本能拒绝」，没验证「默认写法会回退」，
属于论证不充分 —— 这里如实记下来。

> 更深的根因是：本包依赖零散的 `illuminate/*` 而非 `laravel/framework`，
> 于是能被装进任何非 Laravel 项目，然后以残缺的 `env()` 炸掉宿主。
> 顺带一提，靠「补上 `vlucas/phpdotenv` 让 `env()` 能用」是**行不通的**：
> Illuminate 的 `Env` 读的是进程环境变量，而 ThinkPHP 从不把 `.env`
> 灌进那里 —— 结果是 `env('DB_DRIVER')` 返回默认值 `mysql`，
> 把「响亮的崩溃」换成「静默连错数据库」，更糟。
>
> 详见 [多框架适配-PoC报告.md](./internal-docs/多框架适配-PoC报告.md)。

## [1.8.0] - 2026-10-12

> 主题：**修掉两个生产部署必踩的 P0、补上安全基线、消除两个幽灵能力**。
>
> 这一版来自一次系统排查（读代码 + 真实环境实测），
> 结论是「功能面已经完整，但有三类问题挡在『能让人放心用』前面」。

### 修复（P0）：生产部署缓存命令导致后台 500

Laravel 官方部署流程的第一步就是：

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

而框架跑完这些**整个后台 500**，且报错完全指不到根因：

| 命令 | 报错 | 真实原因 |
|---|---|---|
| `config:cache` | `Auth driver [admin] for guard [admin] is not defined` | 多应用 guard 的注册被「配置里已有该 guard」的判断跳过 —— 而 `Auth::extend()` 注册的是**运行时**状态，缓存文件不含它 |
| `route:cache` | `Target class [admin.admin] does not exist` | `bootApplication()` 在 `routesAreCached()` 时**整段 return**，连中间件组都没注册 —— 而缓存文件里存的是组名字符串 |

**实测对照**：无缓存时 `customCreators = [admin, merchant]`，
有缓存时只剩 `[merchant]`。

修法是把「运行时注册」（中间件组 + guard）与「路由文件加载」分离，
前者在路由缓存时也执行。

> 这两个问题**只在生产部署路径上出现** —— 编译期不报、单测不报、
> 本地 e2e 也不报（因为本地从不跑这三条命令）。

### 修复（安全）：登录无速率限制

补上之前实测：连续 10 次错误密码**全部 422，零拦截**。
全仓库 grep `throttle` → 0 处。

攻击者可对暴露公网的后台无限次爆破密码 ——
登录日志会记录失败，但**日志不能阻止攻击**。

新增双维度限流（`Aimanong\Auth\LoginThrottle`）：

| 维度 | 防什么 |
|---|---|
| 按 IP | 单机爆破（一个 IP 打很多账号） |
| 按 IP + 账号 | 针对性爆破（分布式时每 IP 对每账号仍受限） |

只按 IP 不够 —— 分布式攻击时每个 IP 只试几次就绕过去了。

接入顺序很关键：**先检查是否被锁，再尝试登录**。
若反过来，被限流期间每次请求仍会真的查库 + 校验密码，
等于「限流了但没省力」。

配置：`auth.throttle.{enable,per_ip,per_account,window}`。

### 新增：筛选器 UI

`->filter()` 此前是**幽灵能力**：schema 产出 `"filterable": true`，
后端 `?filters[列]=值` 也真生效，**但页面上一个控件都不渲染**。

第六轮 AI 实测的真实反馈：「我最后移除了这个声明 ——
留着只会骗下一个读 schema 的人」。

现在工具栏会渲染筛选控件：有 `map`/`dict` 走**下拉框**，
否则走**输入框**；有值时显示清除按钮。
选项来源不需要新 API（`->dict()` 内部已调用 `->map()`）。

### 新增：批量操作全链路

`$grid->batchActions([...])` 是**最严重的幽灵能力** ——
它在 4 处对外文档许诺了功能，实际是彻底的空操作（连 schema 都到不了）。

现在打通全链路：

| 层 | 实现 |
|---|---|
| 声明 | `$grid->batchActions(['delete', 'export'])`，未支持的动作名**编译期报错** |
| 编译 | Compiler + emitter 都带上（此前**两层各漏一次**） |
| 后端 | `POST /api/{uri}/batch`，**只接受声明过的动作**（未声明 403） |
| 前端 | 选择列（含全选框）+ 批量按钮（选中才显示） |

- 权限复用单条删除的 `destroy` —— 能删一条就该能删一批
- **部分成功语义**：一次删 50 条、3 条被约束拦住时逐条执行并回报明细，
  而不是全部回滚（后者要用户自己找是哪 3 条）
- 翻页清空选择（否则「已选」里可能是不在屏幕上的行，易误删）
- 导出选中项复用导出端点（加 `ids[]` 参数），不新开端点

### 修复：导出未带筛选条件

`doExport` 只带 `keyword`/`sort`，**没带 `filters`** ——
筛选后导出，导出的不是看到的内容，与文档承诺「导出与内容一致」不符。

### 新增：《生产部署》

此前全仓库没有部署文档。新文档覆盖：Nginx 完整配置、
必须修改的环境变量、上传存储三种模式、**9 项安全清单**、
升级与回滚、备份（含 `.env` 里的 `APP_KEY`）、常见问题。

特别提醒一条容易忽略的：

```ini
AIMANONG_AI_ENABLE=false
```

自省接口默认在「local 或 APP_DEBUG」时开启 ——
只要没关掉 `APP_DEBUG`，它会把你的 Resource 结构公开出去。

### 修复：CI 端到端 job 一直是红的

这个 job 从加进去那天就失败，而**从未被验证过**：

| 缺失 | 导致 |
|---|---|
| `packages/framework` 的 composer 依赖 | PHPUnit / PHPStan / Pint / 版本号一致性**全部报错** |
| `docs` 的 npm 依赖 | 文档站构建失败 |
| demo 服务 | http-e2e 判定「服务未运行」 |
| Laravel 骨架版本未锁定 | 依赖解析失败（拿到 Laravel 13 骨架） |
| `types.ts` 未生成 | tsc-check 失败 |

同时让 e2e 失败**在 CI 上可见**（此前只输出「见 /tmp/xxx.log」，
而那个文件本地看不到）。

现在 4 个 job 全绿并有 `gh run list` 实测确认。

### 测试

`tests/e2e/framework-e2e.php` 从 64 项扩到 **94 项**。

**质量门禁**：PHPUnit 183 tests / 651 assertions、PHPStan Level 8、
Pint PASS 168 files、`pre-release.sh --with-e2e` 全绿。

### 三条记入验证方法论的教训

1. **断言检查了「错误的东西」** —— 写「安装命令会创建管理员账号」时，
   第一版检查的是「方法存不存在」。反向测试（删掉调用、只留定义）
   **断言照样通过** —— 假绿灯比没有红灯更危险。
2. **Laravel 12 没有 `hasCustomCreator()`** —— 我按记忆写的检测方法
   不存在，导致断言假红。改为「真的去解析一次 guard」的行为验证。
3. **PHP 双引号里的正则会被变量插值** ——
   `"/batchDelete.*?authorize_\(\$uri.../"` 中的 `$uri` 被插值成空串，
   断言恒假。

---

## [1.7.1] - 2026-10-11

> 修一个 **P0 级死锁**：装完框架**进不去后台**。
>
> 附带补一份面向**完全不懂编程**的人的使用指南。

### 修复（P0）：安装命令不创建管理员账号

`quickstart.md` 写着「用安装时创建的账号登录」，而实测
`aimanong:install` 的全部动作是：注册 provider / 发布配置 /
发布迁移 / 执行迁移 —— **没有任何一步创建账号**。

于是形成**闭环死锁**：

```
装完 → admin_users 表为空 → 打开后台跳登录页 → 没有账号可登
    → 进不去 → 也无法用「管理员账号」界面创建第一个账号
```

使用者唯一的出路是手工执行 tinker 脚本 —— 而那段代码
**不在任何对外文档里**，所以这个问题在历次验证中从未暴露。

**修复**：安装命令新增建号步骤

| 行为 | 说明 |
|---|---|
| 交互式询问 | 账号默认 `admin`；密码回车则生成 **16 位随机强密码** |
| 非交互参数 | `--admin-user=` / `--admin-password=`（CI / 脚本用） |
| `--no-admin` | 跳过建号 |
| **幂等** | 已有账号时跳过，**不重置密码**（重跑安装不该改用户改过的密码） |
| **绑定超管角色** | 否则 RBAC 开启时登进去菜单为空，和「进不去」差别不大 |
| 容错 | 表不存在时提示「请先执行 migrate」而不是崩溃 |

> 密码默认随机而非 `admin123`：安装命令常被复制进 CI，
> 固定弱密码等于给每个新项目留一个公开后门。

### 修复：安装命令不同步权限节点

内置五个 Resource 的权限节点是**自动推导**的，但必须执行 sync
才写入数据库。不同步的后果：RBAC 开启时这些权限在
「角色 → 分配权限」界面里看不到（超管绕过判定，所以第一个账号
不受影响 —— 但下一个账号就会，症状是「菜单莫名消失」）。

现在 install 自动同步，失败不阻断安装。

### 新增：《不懂编程，怎么用它给自己造一个后台》

面向**完全不懂编程**的读者，回答「非程序员能不能用这个项目开发自己的系统」。
包含：完整流程、可直接复制给 AI 的**需求模板**、常见问题、
术语表（12 个词的大白话解释）、以及**如实列出 5 项已知不足**。

侧边栏把非程序员入口放在「入门」组最前，原「快速开始」改名
「快速开始（开发者）」。

### 修正

`quickstart.md` 的失实描述（「安装时创建的账号」）、补非交互参数表、
补「验证装好了没」、补 `permission sync` 说明。

### 验证

**真实项目从零安装**（只走安装命令，不手工写库）：

```
✓ 权限节点已同步（新增 24，已存在 0）
  登录账号: admin
  登录密码: 9{)9ZBk.f5on~ldp
```

数据库核对：管理员 1 个、角色「超级管理员」、权限节点 24 个；
实测登录 **200**、后台首页 **200**。

幂等验证：重跑安装 → 「已存在管理员账号，跳过创建」→ 原密码仍可登录。
边界验证：`--no-admin` 正常跳过；表不存在时友好提示不崩溃。

文档内的恢复命令**全部实测**：忘密码的 tinker 代码照抄可改密码
（新密码登录 200）、`permission super admin` 可执行。

**e2e 从 64 项扩到 72 项**，新增 8 条覆盖安装命令的建号与同步。

### 一条教训（第 10 次验证误导）

写「安装命令会创建管理员账号」断言时，第一版检查的是
`str_contains($src, 'createAdmin')` —— 即「方法存不存在」。
反向测试（删掉调用、只留方法定义）**断言照样通过**。

它防不住本来要防的回归，属于**假绿灯**。改为检查调用模式后
反向测试正确失败。已记入 [验证方法论](./internal-docs/验证方法论.md)。

---

## [1.7.0] - 2026-10-11

> 主题：**框架核心与插件彻底解耦**。
>
> 插件从此可以自带前端 UI —— 第三方想加自定义字段，
> 不必再改框架源码。

### 新增：插件字段 UI 注入口（公开 API）

在此之前，插件注册了字段类型（`Extension::field()`）却**无法表达
「它长什么样」** —— 渲染逻辑只能写在框架核心的 `resource.blade.php` 里。

```php
// 插件侧
public function boot(): void
{
    $this->views(__DIR__.'/../resources/views', 'aimanong-region');

    $this->fieldView('region', 'aimanong-region::field');   // 长什么样
    $this->fieldScript('aimanong-region::script');          // 逻辑怎么算
}
```

| 入口 | 作用 |
|---|---|
| `Extension::fieldView($type, $view)` | 注册字段的 blade 渲染片段，框架按类型 `@include` |
| `Extension::fieldScript($view)` | 注册 JS 片段，在页面 `setup()` **作用域内**执行，可用 `ref` / `form`，通过 `pluginExports` 暴露给模板 |
| `FieldType::fieldViewFor()` / `viewableTypes()` | 自省：哪些字段类型带自定义 UI |

框架侧新增三个插件无关的钩子：`pluginFieldViews` / `pluginScripts` /
`pluginFieldInit`（打开表单时初始化插件字段状态）。

### 修复（P0）：框架核心硬依赖 region 插件

框架核心模板**无条件调用**了可选插件的类：

```php
const regionProvinces = @json(\Aimanong\Region\Region::provinces());
```

而 `framework` 的 composer.json **没有 require region**，
`aimanong:install` 也不装它。

**影响面**：`resource.blade.php` 是所有 Resource 的列表/表单页 ——
只想 `composer require aimanong/framework` 的用户（完全按 README
「快速开始」做），打开任意列表页就是
`Class "Aimanong\Region\Region" not found` → **整个后台不可用**。

对「带地基的平台」来说，「装完打不开」是最不该有的问题。

修复方式不是加个 `class_exists` 就完事，而是**把 UI 归还给插件**
（见上节的注入口）—— 根治耦合，顺带让第三方插件也有出路。

### 迁移：region 插件带走了自己的 UI

框架核心删除 61 行 region 专用代码（三级联动 UI + 5 个 JS 函数），
改为插件自带 `resources/views/field.blade.php` + `script.blade.php`。

**解耦的证明**：清空所有插件视图注册后重新渲染页面 ——
95083 字节正常输出，**不含任何 region 残留**。

### 验证

**在真实的最小安装项目里实测**（只装 framework、确认无 region）：

```
/admin/admin-users            = 200     /admin/admin-login-logs = 200
/admin/admin-dict-types       = 200     /admin/profile          = 200
/admin/admin-operation-logs   = 200     GET /admin/api/admin-users = 200
```

修之前这些页面**每一个都是 Fatal error**。

**真机实测三级联动仍正常**：3 个 select（`->level(3)`）、
2 个 select（`->cityLevel()`）；选省→市级加载、选市→区级 5 个选项、
三级选完 → 「北京市 / 市辖区 / 东城区」；控制台无错误。

### 其它

- `composer.json` 增加 `suggest`，让「插件可选」在 Composer 层面可见
- 删除 `packages/ui` 空目录（决策见
  [packages-ui-决策分析](./internal-docs/packages-ui-决策分析.md)），
  并修正三处文档失实：仓库结构图、CSS 前缀（`amn-` → 实际 `am-`）、
  npm 包状态
- `scripts/setup-demo.sh` 新增 `AMN_MINIMAL=1` 最小安装模式
- **e2e 从 47 项扩到 64 项**，新增：可选插件守卫、注释里无指令、
  插件字段 UI 注入、最小安装可用性

### 两条来自模板解析的教训（同一类坑，各踩一次）

1. **Blade 注释不是注释** —— 注释块里的 `@json(...)` 会被编译成真实的
   PHP 调用。修守卫时把反例写进注释，结果「守卫加了、页面照样 500」。
2. **注释里不能出现指令名** —— 插件视图的注释里写了 `@php`，
   Blade 无视注释上下文直接解析，导致注释块提前结束、模板结构错乱
   （症状：三个 select 只渲染出最后一个）。

> 已把两条都变成 e2e 断言，防止后人再犯。

**质量门禁**：PHPUnit 183 tests / 650 assertions、PHPStan Level 8、
Pint PASS 167 files、`pre-release.sh --with-e2e` 全绿
（framework 64/64、tsc 真编译、http 32/32）。

---

## [1.6.2] - 2026-10-11

> 由《初衷对照评估》驱动的**一致性与可发现性修复**。
>
> 这一版的核心不是新功能，而是**让「文档说的」与「实际做的」一致** ——
> 对一个以「可自省、可验证、AI 一次改对」为卖点的框架，
> 自身声明失真是最不能接受的问题。
>
> 其中两个缺陷属于「照着框架的提示做，会得到错误结果」，
> 性质最严重。

### 修复：只读资源在 OpenAPI 里仍宣告写接口

`Resource::readonly()` 让接口层 `store/update/destroy` 一律 403，
但生成的 OpenAPI 仍然写着 `POST / PUT / DELETE` —— AI 照文档写代码
就会写出必然 403 的调用，而且没有任何理由怀疑文档。

这正是 `方案.md` 第一条要杜绝的「文档与实现漂移」。

| 产物 | 修复前 | 修复后 |
|---|---|---|
| `schema.json` | ✅ 有 readonly | ✅ |
| `openapi.json` | ❌ 宣告写接口 | ✅ 只出 GET |
| `types.ts` | ❌ 接口签名无该字段 | ✅ 进签名 |
| `ai-context.md` | ❌ 0 处提到只读 | ✅ 明确告知 |

并新增 **跨 emitter 一致性断言**，防止将来新增能力时又漏改一处。

### 修复：`types.ts` 根本编译不过（用真实 tsc 才发现）

这份产物的存在理由是「端到端类型安全」，但**从未被编译过**：

1. 它引用的 `AimanongGridSchema` / `AimanongFormSchema` /
   `AimanongField` / `AimanongColumn` **从未定义**
2. 补上后仍编译不过 —— 基类型与真实产出有四处不符：
   字段实际有 `jsonType`、非树形 `grid.tree` 实际是 `null`、
   空 `props` 是 `[]` 而非 `{}`、空 `rules` 也是数组

新增 `tests/e2e/tsc-check.sh`（用真实 tsc 编译）并接入发版检查。

> 教训：**基类型要照着产出写，不能照着想象写。**

### 修复：`llms.txt` 教了一个不存在的方法

```php
$grid->filter()->equal('status');   // Call to undefined method
```

`llms.txt` 是「读这个文件就够开始工作」的入口文档，这类错误
浪费的是 AI 的整轮尝试。已删除并说明列表页的筛选入口是 `->searchable()`。

同时说明列上的 `->filter()` **只产元数据、不渲染 UI**（会产出
`"filterable": true`、API 层 `?filters[x]=y` 也生效，但界面上没有筛选框）。

### 修复：框架 hint 把用户从「能编辑」引向「不能编辑」

`UNIQUE_CONSTRAINT_VIOLATION` 原本建议：

    在 form() 中声明 ->rules('unique:表名,字段') 可在提交前校验

**照着做会让编辑功能 100% 崩溃**（父 Agent 独立复现）：

```
裸 unique:equipment_repairs,repair_no  → 编辑自己 = ❌ 已存在
Rule::unique(...)->ignore($id)         → 编辑自己 = ✅ 通过
```

根因：`unique` 规则把记录自己也算作冲突，而声明式表单拿不到
当前记录 id，写不出 `ignore()`。hint 已改为「数据库唯一索引已正确拦截，
无需额外处理」并解释为什么不能加。

### 修复：业务语言检索不到能力（第六轮 AI 实测）

需求方说的是「运营希望自己改文案」，而能力面写的是
「把枚举变成一等公民」—— 「文案」「运营」出现 **0 次**，
AI 拿业务词检索就是 MISS。

- 检索改为**分词匹配**（中文 2-gram + 整块 + 停用词），
  不再要求整串命中
- 能力面新增 `also_known_as` **业务同义词**：
  `dict` → 「运营改文案 / 状态文案不用发版」；
  `operation_log` → 「处理留痕 / 谁改的 / 事后能查到」；
  `upload` → 「现场照片 / 传多张图 / 报价单扫描件」

### 修复：能力面谎报「权限节点自动生成」

原文「权限节点由 Resource 注册时自动生成，无需手写」——
实际节点名是自动推导的，**写库仍需显式 `permission sync`**。
第六轮实测里 AI 据此断言节点已生成，而库里实际是 0 个。

已改为 `naming`（命名自动推导）+ `requires_sync`（必须执行命令）。

### 修复：Playground 落后于框架（含一个静默 bug）

- 补 4 种上传字段类型（26 → 30）
- 补 `->dict()` / `->accept()` / `->maxSize()` / `->requiredOnCreate()` /
  `->omitWhenEmpty()` / `->relation()` / 条件高亮 / `file` 列展示器
- **修掉驼峰方法被静默跳过**：映射表键是小写 `multiselect`，
  而 PHP 方法名是 `multiSelect` —— 查表 miss 后 `continue`，
  字段凭空消失且**不报任何错**（注释早就写着「大小写不敏感」，代码没做）

新增 `tests/e2e/playground-check.mjs` 用真实声明验证。

### 修复：README 描述了两个不存在的目录

`packages/ui`（npm 包）与 `demo/` 实际都只有 `.gitkeep`。
前端真实方案是**零构建内联 Vue**（框架内置，零 CDN）——
这是刻意取舍，不是"还没做"。已改为实际结构并说明原因。

### 测试

`tests/e2e/` 从 47 项扩到 **88 项**，并修掉「只能在特定 demo 上跑」
的可移植性问题（否则 CI 根本用不了它）。

**质量门禁**：PHPUnit **183 tests / 650 assertions**、PHPStan Level 8、
Pint PASS 167 files、`pre-release.sh --with-e2e` 三项全绿
（framework 56/56、tsc 真编译、http 32/32）。

---

## [1.6.1] - 2026-10-11

> CRM 真实场景验证（第三件）挖出的缺陷修复。
>
> 这四个缺陷的共同特征是：**界面看着正常、测试全绿**，
> 只有把真实业务需求端到端跑一遍才会暴露。

### 修复：`dangerWhen()` 从来没有过视觉效果

`dangerWhen()` / `dangerBelow()` / `warningAbove()` 是**第一次真实场景验证**
专门加出来的能力（需求：「库存少于 10 要标红」），
这次用「商机金额超 100 万标红」实测，发现它**一次都没生效过**。

三层原因，缺一层都修不好：

| 层 | 问题 | 修复 |
|---|---|---|
| JS | 产出 `hl-danger` | CSS 里叫 `.am-hl-danger`（BEM 命名空间） |
| CSS | 特异度不够 | `.am-hl-danger`(0,1,0) 被 `.am-table td`(0,1,1) 覆盖颜色 —— **字重生效了、颜色没有**，看起来像「高亮只是加粗」 |
| 缓存 | 改了看不到 | 资源路由是 `Cache-Control: public`，升级后浏览器仍用旧 CSS → 资源 URL 加 `?v=<版本号>` |

> 教训：验收标准要盯着**用户可感知的结果**（颜色变了），
> 而不是中间产物（类名加上了）。

### 修复：`daterange` 没有列契约

前端硬编码绑定 `{字段名}_start` / `{字段名}_end`，
真实列名（如合同的 `start_at` / `end_at`）无法声明 ——
表单能渲染、提交永远存不进去，`ai:verify` 还会报一个
莫名其妙的 `SUSPECT_FIELD('period')`。

```php
$form->dateRange('period')->columns('start_at', 'end_at');
```

验证器改为按声明的列检查，而不是检查字段名本身。

### 修复：数值字段留空导致 NOT NULL 违约

商机的「赢单率」滑块没动过，提交时报：

```
NOT NULL constraint failed: crm_opportunities.probability
```

链路：数值字段初始化为空串 → Laravel 的 `ConvertEmptyStringsToNull`
变成 `null` → 撞 NOT NULL。

- 数值类字段（`number` / `decimal` / `money` / `rate` / `slider`）
  无默认值时初始化为 `0`
- NOT NULL 违约改为返回**中文字段名 + 两种改法**，不再把 SQL 抛给用户

### 修复：`->dict()` 让 CSV 导出退回英文

`dict()` 最初是 `map()` 再 `badge()`，而 `badge()` 会把 formatter
覆盖成 `'badge'` —— 导出器只在 formatter 为 `map`/`enum` 时才套用中文映射。

界面上是「商务谈判」，导出文件里是 `negotiation`。
前端三种 formatter 都走 `mapLabel`，所以**只有导出会坏**。

- `dict()` 改为「先 badge 再 map」
- 导出器对 `badge` 也检查 `props.map`（两种书写顺序都正确）

### 修复：多文件上传被客户端预检误拦

`->files('attachments')->accept('pdf,jpg,png,docx')` 的字段上传 png 时
**客户端直接拒绝** —— 预检只看全局白名单，而全局白名单里没有 png。
服务端一次请求都收不到，用户只看到「数量没变」。

预检改为优先使用字段自己的 `accept`；
`images` / `files` 也纳入「多值字段初始化为数组」的分支。

### 测试

新增 `CrmScenarioTest`（5 个用例）。**质量门禁**：
PHPUnit **176 tests / 605 assertions**、PHPStan Level 8、Pint PASS 167 files。

---

## [1.6.0] - 2026-10-11

> 「带地基的平台」收官：**P0 六项基座能力一次性补齐** ——
> 装完就有，一行代码都不用写。
>
> 途中挖出并修掉一个**框架级**缺陷：多对多关联写入从未生效。

### 新增：审计日志（操作日志 + 登录日志）

| 项 | 说明 |
|---|---|
| 操作日志 | 自动记录写操作；**GET 不记**（否则刷新一下列表就是一条，重要记录会被淹没） |
| 登录日志 | 成功与失败**都记** —— 只记成功等于放弃了「有人在爆破」这条线索 |
| 脱敏 | `password` / `token` / `api_key` 等一律掩码为 `******`，含 `new_password` 这类变体 |
| 只读 | 审计数据允许被改就失去了意义 |

### 新增：只读 Resource（通用能力）

```php
public static function readonly(): bool { return true; }
```

三层同时生效：前端隐藏写入口、接口 `store/update/destroy` 一律 403、
权限节点只生成 `index/show/export`。

> ⚠️ 只读校验必须排在 RBAC 判断**之前** —— RBAC 默认关闭时 `authorize_()`
> 会直接放行，写在后面等于没写（已用 `AIMANONG_RBAC=false` 实测 403）。

### 新增：数据字典（枚举变成一等公民）

```php
$form->select('status')->label('状态')->dict('order_status');
$grid->column('status', '状态')->dict('order_status');
```

- **两种来源，一个入口**：代码声明（可 git diff、后台只读）+ 数据库字典（运营可改文案）
- **字典缺失直接报错**：编译期抛 `DICT_NOT_FOUND`，带 `did_you_mean` + 可用字典 + 示例
- **颜色进字典**：列表徽章用字典声明的语义色，不再靠关键词表猜
- **缓存自动失效**：写库即时生效

它真正解决的问题不是「缺一个功能」，而是**已有的分叉**：
同一个枚举原先在列表页写一遍 `map()`、表单页写一遍 `options()`、导出再写一遍。

### 新增：文件上传

```php
$form->image('cover')->maxSize(2048);
$form->images('gallery');
$form->file('contract')->accept('pdf,docx');
$form->files('docs');
```

| 决策 | 理由 |
|---|---|
| 落盘名 = 可读原名 + 8 位随机 | 纯随机名用户认不出文件；只用原名会**静默覆盖**同名文件 |
| 数据库存相对路径 | 换域名 / 换 CDN 不用刷数据 |
| 默认**不含 svg** | SVG 可内嵌 `<script>`，同源读取 = XSS |
| 扩展名 + 真实 MIME 双白名单 | 只看扩展名挡不住 `shell.php.jpg` |
| `auto` 按 driver 判断 | Laravel 的 `public` 盘**总是**配了 `url`，按「有没有 url」判断会让新项目直接得到坏图 |

零构建上传组件（本地 Vue，无 CDN），URL 模板由服务端下发。

### 新增：个人中心

`GET {prefix}/profile` —— 基本资料（含头像上传）/ 修改密码 / 我的权限 / 我的操作记录。

**不做成 Resource**：Resource 的心智模型是「一张表 = 一组页面」，
而个人中心操作的是当前登录者自己 —— 没有 id、没有列表，
硬套会得到一个「理论上能删自己」的页面。

改密码必须验当前密码（防止会话被劫持后直接改密）；
只允许改 `name/email/phone/avatar` 四个字段（白名单）。

### 新增：管理员账号管理

内置 `admin-users` 资源。v1.3.0 起框架就带了 admin guard + 用户表，
却**没有管理界面** —— 地基埋好了，门一直没装。

含角色分配、启停用、最后登录时间与 IP。停用的账号在**密码校验之后**被拒绝登录
（先验密码再报停用，否则成了「用错误密码探测账号是否存在」的旁路）。

### 新增：两个表单语义

| 声明 | 不写会怎样 |
|---|---|
| `->requiredOnCreate()` | 用 `required()` 会让**编辑被自己的规则卡住**；用 `nullable()` 会让新增静默存空密码 |
| `->omitWhenEmpty()` | 编辑用户留空密码本意是「不改」，但空串进了请求体，`'hashed'` cast 把它哈希成新密码 —— **账号当场失效且不报错** |

> ⚠️ 剔除必须发生在 `validate()` **之前**。第一版写成「先校验再剔除」，
> `min:6` 对着空串报错「密码 不能少于 6 个字符」—— 用户明明只是想不改密码。

### 修复：多对多关联写入从未生效（框架级）

`splitRelationFields()` / `syncRelations()` 定义了却**从未被任何地方调用**。

后果：`multiSelect()->relation()` 是**幽灵能力** —— 表单提交成功、
列表看不出区别、中间表一条都没有。demo 里的多对多标签只能自己写模型事件绕过。

实测证据：给用户传 `roles=[2]`，中间表零行；接线后 create/update 均正确 sync。

### 修复：多应用模式下操作日志一条都不会记

单后台与多应用此前**各硬编码了一份中间件列表**，新增 `admin.audit` 时
只改了单后台那份 —— 不报错、测试全绿。已合并为 `adminMiddlewareGroup()` 单一来源。

### 修复：审计日志的 user_id / username 全是 null

用了 `$request->user()`，它走 `config('auth.defaults.guard')`（通常是 `web`），
而后台是 admin guard。审计日志「不知道是谁干的」等于没有价值。

### 修复：界面偏好的服务端同步一直是坏的

路由写成 `Route::prefix('api')` + `'api/ui/preferences'`，实际路径变成
`/admin/api/api/ui/preferences`，与前端 `Aimanong::url('api/ui/preferences')`
对不上：保存 405、读取报「Resource [ui] 未注册」。

### 修复：配置浅合并导致「升级了但新功能没出现」

`mergeConfigFrom` 只做浅合并：用户 config 里只要有 `foundation` 这个键，
包内 `foundation` 的其它子键就拿不到默认值。已改为**递归补齐**。

### 其它

- `Uploader` / `Dictionary` / `Capabilities` 在无容器环境（单测、静态自省）下
  容忍配置缺失，不再抛 `ReflectionException: Class "config" does not exist`
- 上传能力抽成 `partials/upload.blade.php` 供个人中心复用
- `search-docs` 与 `aimanong:docs` 不再静默丢弃数组型能力字段（如 `uris`）
- 内置 Resource 的菜单排序：角色(90) → 权限(91) → 租户(92) → 数据字典(93) → 字典条目(94) → 操作日志(95) → 登录日志(96) → 上传演示(97)

### 测试

新增 `AuditFoundationTest`、`DictionaryTest`、`UploadTest`、
`AdminUserFoundationTest` 与三个夹具，共 **+33 个用例**。

**质量门禁**：PHPUnit **171 tests / 597 assertions**、PHPStan Level 8 无错误、
Pint PASS 166 files、`pre-release.sh` 全部通过。

**真机验证**（不是读 CSS）：

- 四种上传字段各传一遍（含中文文件名），列表缩略图与文件名链接正确，附件可下载
- 路径穿越（`../../evil.pdf` → `evil-xxxx.pdf`，确认未逃出目录）与危险文件名全部被拦
- 分步表单端到端保存、字典徽章取色、个人中心传头像+改密码、用户角色写入中间表
- 3 页 × 320→2560px 响应式扫描无横向溢出
- demo 应用 `ai:verify` **28/28 全部通过**（顺带补了 2 处声明与表结构不一致）

---

## [1.5.0] - 2026-10-11

> 用户连续四轮反馈界面「老气、没有辨识度、颜色太闷」——
> 这一版把**全站视觉重做了一遍**，并在真机验证时挖出**三个此前一直存在的表单渲染缺陷**。

### 新增：全站设计系统（方案 B「新芽」）

配色以 LOGO 取色为基准（不是拍脑袋）：主蓝 `#205098`、成功绿 `#70d070`、
危险红 `#e05040`、警告琥珀 `#e8a840` —— 四色实测占比 62.8% / 17.3% / 9.1% / 8.5%。

| 项 | 内容 |
|---|---|
| 设计令牌 | `design-system.css`（兜底）+ `themes.css`（明/暗）+ `user-prefs.css`（密度/圆角/配色覆盖） |
| 组件命名 | `am-` BEM 命名空间，约 124 个组件类 |
| 组件覆盖 | 工作台、列表页、表单弹窗、登录页**四个页面全部迁移** |
| 界面配置面板 | 10 个可视化选项，实时生效并持久化到服务端 |
| 零外部依赖 | Vue 3 本地内置，移除 CDN —— 此前一次 jsdelivr 抖动导致全站列表页白屏 |

### 移除：三套主题合并为明/暗两套

原本的「墨 / 深 / 极光」三套主题，实测主色相为 216.2° / 223.3° / 202.4°，
**肉眼完全分不出区别**，属于「选项很多、选择为零」的假自由度，已删除。

### 修复：三个表单渲染缺陷（自 UI 重构起一直存在）

这三个缺陷不会报错、不会让测试变红，只会在浏览器里静静地显示错东西：

**1. 分步表单的步骤条把整段 JSON 打印出来**

模板写的是 `@{{ s }}`，而 `s` 是 `{title, fields}` 对象。
四步表单的顶部因此显示四大段原始 JSON。已改为 `@{{ s.title }}` 并补上
CSS 里早就写好、却从未被使用的 `.am-step__label`（省略号截断）。

**2. 所有下拉框、单选、多选的选项都渲染成 JSON，且值全是 0/1/2**

Schema 编译层输出的选项是**数组**：
```json
[{"value": 1, "label": "Laravel"}, {"value": 2, "label": "Vue"}]
```
（`OpenApiEmitter` 直接 `array_column($options, 'value')`，契约就是数组。）

但前端模板把它当**对象**遍历：
```html
<!-- ❌ 错误：Vue 对数组解构时 val 拿到的是下标 -->
<option v-for="(text, val) in f.props.options">@{{ text }}</option>
```
于是选项文案变成 `{ "value": 1, "label": "Laravel" }`，
每个选项的 `value` 退化成 `0 / 1 / 2 …`，
**带默认值的 select 永远匹配不上 → `selectedIndex = -1` → 显示空白**，
必填下拉框因此永远保存不了，用户只知道「保存失败」。

已统一改为 `fieldOptions(f)` 归一化函数，并在 `FormOptionContractTest` 里
把「选项必须是数组」「模板必须走 fieldOptions()」两条契约钉死。

**3. 开关（switch）根本不可见**

CSS 期望 `<span class="am-switch__track">`，模板却输出了没有类名的 `<span>`，
开关的尺寸因此是 0×0。已修正并补上「开 / 关」文字。

### 修复：表单校验信息全是英文

新增 `resources/lang/zh_CN/validation.php`（62 条规则 + 字段名映射），
此前用户看到的是 `The slug field is required.`，现在是「别名 不能为空」。

> 踩坑记录：`loadTranslationsFrom($path, 'aimanong')` 会注册成
> `aimanong::validation` 命名空间，而 Laravel 校验器读的是**默认** `validation` 键，
> 必须省略命名空间参数才会 `addPath()` 到默认查找路径。

### 修复：原生 alert / confirm 全部替换

`alert()` ×2、`confirm()` ×1 换成设计系统内的 Toast 与确认弹窗：

- 删除确认显示**业务可读标识**（如 `laravel-12-queue-notes`），而不是自增 ID `#12`；
- 422 校验失败逐字段渲染为危险 Toast（最多 3 条），成功 2.6s 自动消失、失败 4.5s。

### 修复：交互与响应式

| 问题 | 处理 |
|---|---|
| 侧栏缩窄后**主内容区塌陷**到 64px | 缩窄态保持 `position: relative`，靠溢出而非绝对定位实现悬浮展开 |
| 缩窄后菜单层级丢失、全部展开 | 缩窄态只显示一级图标，子项折叠；悬浮还原全文 |
| 缩窄后开关按钮**点不到**（位移 128px） | 悬浮展开只绑定 `.am-sidebar__nav`，实测位移 1px |
| 汉堡按钮的 CSS 存在、**按钮本身不存在** | 补上按钮 + 抽屉 + 遮罩 + Esc 关闭 |
| 分步表单没有「上一步」 | 补上；步骤条本身也可点击回跳 |
| 必填下拉框显示空白 | 见上文缺陷 2 |
| 移动端表格挤压成 3 行 | 每列最小宽度 144px，< 960px 切换卡片模式 |

### 新增：MCP Server 版本号单一来源

`Mcp/AimanongServer.php` 曾把版本写成 `'1.4.1'` 字面量 ——
AI 客户端握手拿到的 `serverInfo.version` 会与实际框架版本不一致，
AI 会基于错误版本判断能力是否存在。现改为从 `composer.json` 注入，
并由 `VersionTest` 锁死；README 版本徽章同样纳入测试。

### 测试

新增 `FormOptionContractTest`（3 个用例）与 `OptionFormResource` 夹具。
版本一致性测试从 2 条扩到 4 条（新增 MCP Server、README 徽章）。

**质量门禁**：PHPUnit 136 tests / 447 assertions、PHPStan Level 8 无错误、Pint PASS 136 files。

**真机验证**：8 个 Resource ×（下拉/单选/多选/开关/分步）扫描，
4 个页面 × 320→2560px 响应式扫描，以及一次完整的分步表单端到端保存（实际写入数据库）。

---

## [1.4.1] - 2026-10-09

> 用户上手实测反馈：「登录进去后没看到左侧菜单栏」——**属实，已修**。

### 修复：首页缺侧边栏 + 内容不适合使用者

**问题**：侧边栏只加在了 Resource 列表页，**首页 `/admin` 没有**。
用户登录后正好落在首页，因此看不到菜单。

同时首页内容是框架自述（里程碑、环境版本），对使用者没有价值。

**修复**：首页改为真正的**工作台**

| 区块 | 内容 |
|---|---|
| 侧边栏 | 与列表页一致，按权限过滤 |
| 快捷入口 | 可见模块的磁贴（图标 + 名称，点击直达） |
| 当前身份 | 账号 / 角色 / 权限数，未授权时给出明确提示 |
| 系统信息 | 模块数 / 账号数 / 权限开关 / 版本 |

**三个账号实测**

| 账号 | 菜单项 | 磁贴 | 身份显示 |
|---|---|---|---|
| admin | 22 | 22 | 超级管理员 / 全部权限 |
| ops_demo | 4 | 4 | 内容运营 / 24 |
| viewer_demo | 1 | 1 | 只读观察员 / 1 |

> 教训：新 UI 组件必须在**用户实际落地的那一页**验证，
> 不能只在自己测的那一页确认。

---

## [1.4.0] - 2026-10-09

> 「带地基的平台」第三步完成：**菜单系统**。
> 至此开箱即用的地基齐了：登录 + 用户 + RBAC 权限 + 菜单。

### 新增：菜单系统（声明式）

Resource 覆盖 `menu()` 即可声明菜单（**可选**，不覆盖也有默认菜单）：

```php
public static function menu(): array
{
    return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10];
}
```

| 键 | 说明 |
|---|---|
| `group` | 分组名（如「内容管理」），不填则不分组 |
| `icon` | 图标（emoji） |
| `sort` | 排序，小的在前，默认 100 |
| `label` | 显示名，默认用 label() |
| `visible` | 是否显示（后台工具页可设 false） |

**行为**

- 侧边栏自动渲染：分组 + 图标 + 当前页高亮
- **按权限过滤**：RBAC 开启时只显示当前用户有 `index` 权限的菜单
  （实测：超级管理员 22 项 / 只读用户 1 项）
- 可自省：`GET /__ai/menu` 返回菜单树；capabilities 含 menu 说明

### 新增：页面路由权限判定（上轮实测发现的缺陷）

RBAC 原本只拦 API，页面路由未拦 —— 未授权用户看到「暂无数据」的空表格
而非「无权限」提示。已修：页面也返回 403 + 明确提示。

### 修复

- 生成器 uri 统一 kebab（下划线自动转换）
- 生成器为框架自带表（RBAC）生成 Resource 时指向框架模型

---

## [1.3.0] - 2026-10-09

> **方向调整**：从「纯开发工具」转向「带地基的平台」第一步 —— **内置 RBAC**。
>
> 此前多租户场景暴露：框架对权限零支持，AI 不得不手写四层防线。
> 有了内置权限与用户地基，AI 就能专注写业务。

### 新增：内置 RBAC（角色 / 权限）

**权限节点由 Resource 自动生成，无需手写。**

每个注册的 Resource 自动生成 6 个节点：
`{uri}.index` / `.show` / `.create` / `.update` / `.destroy` / `.export`

例：`cms-authors` → `cms-authors.update`（显示名「作者 · 编辑」）

本次 demo 项目自动生成了 **132 个权限节点**（22 个 Resource × 6）。

```php
// 数据模型
admin_roles            // 角色（含 is_super 超级管理员）
admin_permissions      // 权限节点
admin_permission_role  // 角色 ↔ 权限
admin_role_user        // 用户 ↔ 角色
```

**使用**

```php
// 判定
PermissionGate::check('cms-authors.update');

// 同步权限节点（Resource → 权限表）
php artisan aimanong:permission sync
php artisan aimanong:permission list
php artisan aimanong:permission super admin   // 设某用户为超级管理员
```

Controller 已内置拦截：未授权返回 **403** 并提示缺少哪个权限。

**可关闭**：`AIMANONG_RBAC=false`（默认关闭）→ 全部放行，保留灵活性。

### 新增：用框架造自己的权限后台（吃自己的狗粮）

用户/角色/权限管理后台**用 Aimanong 自己生成**：

```bash
php artisan aimanong:make-resource admin_roles --label=角色 --register
```

角色分配权限用**框架原生的多对多**：

```php
$form->multiSelect('permissions')->relation('permissions')->options(...);
```

> 这同时验证了 v1.1.1 的多对多能力 —— 框架能造自己的后台，
> 说明它够用。

### 修复（本轮开发中发现）

1. **生成器 uri 不一致**：`Str::kebab('admin_roles')` 保留下划线，
   与既有 20 个 kebab Resource 不一致 → 页面 404。已统一为 kebab
2. **生成器指向不存在的模型**：为框架自带表（RBAC）生成 Resource 时
   指向 `App\Models\AdminRole`（不存在）→ 页面 500。
   已改为自动识别框架表并指向框架模型
3. **`Registry::find()` 不支持下划线 uri**：已支持两种写法互通
4. **`PermissionGate::enabled()` 无容器时崩溃**：已兜住

### 文档

- `cannot_verify` 更新：权限/RBAC 从「框架没有」改为「已内置（操作级），
  按钮级/字段级仍需自行实现」

---

## [1.2.0] - 2026-10-09

> 本版本来自 **SaaS 多租户场景验证**（第四轮真实场景）。
> 该场景暴露的不是功能缺失，而是**一个更根本的问题**：
> **校验器对安全需求完全无感，却照样给绿灯。**

### 最重要的发现

AI 在 `validate_declaration`（含 requirements）+ `ai:verify` + 16 条隔离测试
**全部绿灯**的同时，发现租户名单正在被泄漏。

原因：「每租户只能看自己数据」这类**安全需求**，
映射不到 requirements 的任何一个键（只有 9 个：
searchable/sortable/required/columns/fields/per_page/tree/export/step），
**校验器对它完全无感，也不会说"我无法验证这条"**。

> 这意味着电商场景「传 requirements 就够」的方法论，
> **在安全需求上直接失效**。

### 修复 1：显式声明能力边界（`cannot_verify`）

框架现在会**主动告诉 AI「这些领域我管不了」**：

```json
"cannot_verify": {
  "多租户/数据隔离": "框架不提供行级数据隔离…",
  "权限/RBAC": "框架没有权限系统，只有登录/未登录一个粒度",
  "行级权限": "…",
  "审计日志": "…",
  "接口限流": "…"
}
```

原则：**宁可说"我不知道"，也不能默认通过。**

### 修复 2：数据作用域钩子（`ScopeHooks`）

AI 报告指出：框架对多租户零支持，且**默认姿势相反**
（Resource 一注册就全表裸奔），**不提供任何官方拦截点**。

新增官方注册点：

```php
// 查询作用域（读）
ScopeHooks::query('tenant', fn ($q) => $q->where('tenant_id', TenantContext::id()));

// 写入钩子（自动盖章）
ScopeHooks::writing('tenant', fn ($data) => $data + ['tenant_id' => TenantContext::id()]);
```

- 自动应用于所有 Resource 的查询与写入
- **可自省**：未配置时 `introspect()` 返回警告
  「⚠️ 未配置任何数据作用域 —— 所有 Resource 默认返回全表数据」
- 状态暴露在 `capabilities.json` 的 `scope_hooks`

> 这把「不可见的业务约定」变成「框架能感知的声明」。

### 修复 3：现代访问器被误判为幽灵列

`isVirtualAttribute()` 只检查了传统写法 `hasGetMutator()`，
漏了 Laravel 9+ 推荐写法 `hasAttributeGetMutator()`。
已补齐，两种写法都能识别。

### 修复 4：编辑表单回填不完整（我上一轮的修复有漏洞）

v1.1.1 修了 API 返回，但**前端仍勾不上**：
API 返回 `[{id,name}]`，checkbox `:value` 是标量 `'1'`，
Vue 的 `v-model` 用 `===` 严格相等 → 对象永远不等于标量。

> **教训：我上一轮只验了 API 返回值就宣布"修好了"，没验 UI。**
> 这是「三层验证」里第 3 层缺失的典型。

已修：
- 进入表单时把关联对象归一化成标量 id 数组
- 新增时多选字段初始化为 `[]`（否则 v-model 无法绑定）

### 修复 5：分步表单编辑时锁死在第 1 步

`@click="i <= currentStep && ..."` 导致编辑时无法跳到后续步骤，
**后面的字段根本改不了**。已改为：新增只许往前（防跳过必填），
**编辑可自由跳转**。

---

## [1.1.1] - 2026-10-09

> 本版本修复 **多对多关联（belongsToMany）支持缺失** ——
> CMS 场景验证暴露，电商场景（只有 belongsTo）发现不了。

### 修复

| 严重度 | 问题 | 现象 |
|---|---|---|
| **高** | 多对多关联列**导出 500** | 集合上取 `->name` 抛异常 |
| **高** | 多对多关联列**列表渲染为空** | 前端按字符串渲染数组 → 空值 |
| **高** | 编辑表单**勾不上已选值** | 关联是对象数组，checkbox 的 `:value` 是标量 id |
| 中 | 表单多对多写入需手写样板 | `unset($data['tags'])` + 模型 `saved` 事件里 `sync()` |

### 新增

**表单多对多：声明关联即自动处理**

```php
$form->multiSelect('tags')->label('标签')->relation('tags')->options(...);
```

- 写入时自动 `sync()` 中间表（主表保存后）
- 编辑时自动回填已选值
- 该字段自动从主表数据中剔除（不是真实列）

**列表与导出支持多对多**

```php
$grid->column('tags.name', '标签');   // 显示「Laravel / 性能优化 / 运维」
```

- 前端 `normalizeCell()` 处理数组：优先取 `name`/`title`/`label`
- 导出拼成 ` / ` 分隔（便于运营阅读），而非 JSON

### 前端补齐 6 个字段类型的专门渲染

原来这些类型都退化成纯文本输入框：

| 类型 | 修复后 |
|---|---|
| `multiselect` | 复选框组（多对多常用） |
| `color` | 原生取色器 |
| `slider` | 滑块 + 实时数值 |
| `rate` | 星级评分（可点击） |
| `tags` | 逗号分隔输入 |
| `daterange` | 双日期选择 |

### 流程备注

本版本在 AI 实测**进行中**修复 —— 违反了 v1.1.0 写入流程的
「实测期间框架冻结」原则。

**边界澄清**：应在 AI **交付完成后**即可解冻；
本次 AI 已交付四个模块，仅在做报告，故影响可控
（已核对其交付物未被破坏）。

---

## [1.1.0] - 2026-10-09

> 本版本的新增能力，全部来自**真实业务场景验证**与**官方插件开发** ——
> 而非凭空设计。两轮验证共暴露 6 个缺口，均已修复。

### 新增

#### 关联列（真实场景暴露）

商品列表要显示「分类名」而非 `category_id` —— 框架原来不支持，
AI 只能手写 `->map()` 自己查表拼映射。

```php
// 点号命名自动识别为关联列
$grid->column('category.name', '所属分类');

// 或显式声明
$grid->column('cat', '分类')->relation('category.name');
```

- Repository 自动 `with()` 预加载，**无 N+1**
- 关联列可参与**搜索**（`whereHas`）、**筛选**（`whereHas`）、**排序**（子查询）
- 前端 `cellValue()` 深取关联值

#### 条件高亮（真实场景暴露）

需求「库存少于 10 要一眼看出来」—— 框架原来**完全无法实现**。

```php
$grid->column('stock', '库存')->dangerBelow(10);        // 少于 10 标红
$grid->column('sold_count', '销量')->warningAbove(100); // 多于 100 标橙
$grid->column('score', '评分')->dangerWhen('<', 60, 'warning');  // 通用形式
```

- 三级样式：`danger` / `warning` / `success`
- **条件用「运算符 + 阈值」而非闭包** —— 保证可序列化、AI 可读、无隐式魔法

#### 插件可注入字段类型（官方插件暴露）

原字段类型是硬编码常量，插件无法扩展。现在：

```php
// 插件侧
class MyExtension extends Extension
{
    public function register(): void
    {
        $this->field(MyField::class, 'mytype', 'string', 'string');
    }
}

// 使用侧（与内置字段无异）
$form->mytype('field', '标签');
```

- `FieldType::register()` 运行期注册
- `Form::__call()` 分发 —— 但**只服务已注册类型**，
  未注册抛 `BadMethodCallException` 并列出可用类型（**不是任意魔法入口**）

#### 查询列安全（电商场景暴露）

关联列直接当列名用会 500，不存在的列会**静默返回错误结果**。

新增 `Support\ColumnResolver` —— 查询列安全校验的唯一入口：

| 防线 | 时机 | 拦截内容 |
|---|---|---|
| `GhostColumnException` | 编译期 | 本表不存在的列 / 关联不存在 / 非法字符 |
| `InvalidQueryColumnException` | 编译期 | 关联目标表没有该列 |

**设计原则：编译期拒绝，运行期兼容。**

### 修复

- **导出与界面不一致**（高危）：`map()` 列导出原始值（`paid` 而非「已付款」）、
  关联列导出空值。文档承诺「导出内容与界面一致」，实际只有行数一致。
  现已套用列的 formatter（map/bool/money/datetime）
- **关联列搜索 500**（高危）：`orWhere('product.name')` 被 SQL 当列名，
  且会**拖垮同一查询的其它条件**。现走 `whereHas`
- **Repository 替换点对 HTTP 不生效**（中危）：Controller 硬编码
  `new EloquentRepository()`，从未解析容器契约。现优先从容器解析
- **幽灵列检测过松**：原来只检查 `method_exists()`，
  模型上恰好有同名方法就会放行。现要求返回 `Eloquent Relation`
- **校验器假阳性**：对可搜索的关联列增加**运行时探测**，
  避免「必然 500 却拿到绿灯」

### 文档

- **AI 使用手册** —— 写给「指挥 AI 用本框架的人」：三个必做动作、
  6 个真实踩过的坑、4 类场景建议、3 个提示词模板、三层验证法
- **发布前验证流程** —— 7 节流程 + 4 条原则，附可执行脚本
  `bash scripts/pre-release.sh --with-e2e`
- 查询列规则同步到 AI 自省接口

### 官方插件

- **aimanong/region** —— 中国省市区三级联动选择器（首个官方插件）
  - 三级联动 / 两级模式（`cityLevel()`）
  - 自带数据源与级联查询路由
  - 独立 Composer 包，不改框架核心

### 流程改进

**实测期间框架必须冻结** —— v1.0 期间犯过这个错：
在 AI 实测进行中修改框架，导致其早期探测结论失效
（同一文件 21:08 测 500、21:14 测 200）。已写入流程文档。

---

## [1.0.0] - 2026-10-09

首个正式版本。

### 定位

**Aimanong（AI 码农）** —— 一个「AI 优先」的后台开发框架，基于 Laravel 12 + Vue 3。

设计目标是：**让任何 AI Agent 在 5 分钟内理解框架、30 分钟内产出可运行的后台系统**。
为此明确牺牲部分"灵活性"换取"确定性"（禁止方法重载、禁止嵌套闭包）。

---

### 新增

#### 核心（M0–M2）

- **Resource 声明式开发**：一个 PHP 类 = 一张表 = 一组后台页面（列表/表单/详情）
- **Schema 编译层**：一份声明编译出四份产物 —— JSON Schema / TypeScript 类型 / OpenAPI 文档 / AI 上下文
- **漂移检测**：`php artisan aimanong:schema --check`，产物与声明不一致时退出码非 0，可阻断 CI
- **数据源抽象**：Repository 契约，可替换 Eloquent 为 API / 数组等数据源
- **admin guard**：自定义认证，已适配 Laravel 11+ 的契约变更
  （`rehashPasswordIfRequired()` / `getAuthPasswordName()`）

#### AI 能力层（M3）

- **自省接口** `/__ai/*`：capabilities / schema / openapi / context / examples / verify
- **MCP Server**：8 个工具 —— list_resources、describe_resource、scaffold_resource、
  create_page、validate_declaration、run_verify、query_data、search_docs
- **可自愈错误**：异常自带 `did_you_mean` / `example` / `hint` / `docs`
- **模糊匹配**：字段类型与类名拼错时给出建议（编辑距离）
- **幽灵列检测**：拼错列名立刻拦截，不再静默消失
- **需求达标度校验**：`validate_declaration` 支持传 `requirements` 逐条核对，
  `per_page` 会**运行时实测**而非只读声明

#### 字段与展示器（M4）

- **26 个字段类型**：text / textarea / email / url / password / tel /
  number / decimal / money / rate / slider / select / multiselect / radio /
  checkbox / switch / date / datetime / time / daterange / color / icon /
  tags / hidden / display / divider
- **列展示器**：`bool()` / `badge()` / `money()` / `image()` / `link()` / `progress()` / `map()` / `using()` / `dateTime()`
- **前端渲染补齐**：radio 单选组、checkbox 多选组、原生日期选择器、小数步进、
  帮助文本、空值占位、语义标签配色

#### 增强（M5）

- **数据导出**：`$grid->export()`，CSV 格式（UTF-8 BOM 防 Excel 乱码）；
  复用列表的搜索/排序/筛选逻辑，导出内容与界面一致；分批流式，大表不炸内存
- **树形结构**：Resource 实现 `tree()` 方法即可；
  **循环引用检测**（A→B→A 返回 422 而非无限递归）；移动合法性校验
- **分步表单**：`$form->step('标题')`，用「当前步骤游标」声明，无嵌套闭包
- **插件系统**：继承 `Extension` 即可；**依赖检查** + **失败隔离**（单个扩展出错不拖垮其它）
- **多应用**：一个项目跑多个隔离后台，各自独立前缀 / guard / 用户模型

#### 生态（M6）

- **文档站**：API 参考由 Schema 自动生成，永不漂移
- **Playground**：浏览器内编辑 Resource 声明，实时查看四份产物（真实解析，非演示）
- **代码生成器**：`php artisan aimanong:make-resource {table} --label=X --register`
- **扩展脚手架**：`php artisan aimanong:make-extension {Name}`

---

### 安全

- **统一输出转义**：所有用户输入经 JSON 序列化 + Vue 文本插值，杜绝 XSS
- **目录穿越防护**：框架资源路由校验路径前缀
- **敏感接口默认关闭**：`/__ai/*` 仅 local/debug 开启，生产需 token
- **漏洞响应 SLA**：确认 72 小时内 / 严重漏洞 7 天内修复

> 作为对比：参考对象 dcat-admin 有 4 条公开 CVE **全部零补丁**。

---

### 开发过程（值得记录的透明度）

本版本经过 **5 轮 AI 实测**，累计发现并修复 **20 个真实缺陷**。

| | 第一轮 | 第二轮 | 第三轮 | 第四轮 | 第五轮 |
|---|---|---|---|---|---|
| 一次成功率 | 0.5 | 0.5 | 1.0 | 1.0 | 1.0 |
| 框架纠错贡献 | 0% | 50% | 100% | 100% | 100% |
| 是否读源码 | 读了 | 读了 | 没读 | 没读 | 没读 |

测试方式：给 AI 一份文档和一个 MCP 连接，**不给任何提示**，让它独立完成开发任务，
再由复核者独立验证结论。

**最重要的四条教训**（均已写入代码或测试）：

1. **校验器只读元数据 = 假阳性工厂** —— 任何「已设置」的断言都要有运行时验证
2. **同一份信息多个来源 = 迟早分叉** —— 解法是让它们同源，而非"记得同步更新两处"
3. **异常携带的上下文不能在捕获时丢弃** —— 否则 AI 只看到笼统的"编译失败"
4. **校验器的「沉默」比「报错」更危险** —— 不认识的键必须报错，不能静默通过

---

### 已知限制

| 项 | 说明 |
|---|---|
| `draggable()` | 声明会被编译，但**前端拖拽 UI 尚未实现**（编译产物含 `draggable_supported: false`）。调整层级请用编辑表单或 `PUT /{uri}/{id}/move` |
| 导出格式 | 仅支持 CSV，暂无 xlsx |
| 前端组件库 | 使用内联 Vue（CDN），尚未发布独立的 `@aimanong/ui` npm 包 |

---

### 环境要求

- PHP ^8.2
- Laravel ^12.0
- 数据库：MySQL / PostgreSQL / SQLite 均可

---

## 版本链接

- [1.4.1](https://github.com/zyzyzzy/aimanong/releases/tag/v1.4.1)
- [1.4.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.4.0)
- [1.3.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.3.0)
- [1.2.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.2.0)
- [1.1.1](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.1)
- [1.1.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.0)
- [1.0.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.0.0)

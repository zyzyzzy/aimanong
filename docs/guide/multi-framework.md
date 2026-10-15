# 多框架支持

> 同一套 Resource 声明，**Laravel 12** 与 **ThinkPHP 8** 都能跑。
>
> 1.11.0 起，框架拆成「框架无关内核 + 各框架驱动」，
> 你可以按团队的技术栈选，声明代码不用改。

---

## 为什么要拆

一个后台框架的价值，很大一部分在**声明层**：字段、校验、列展示、
权限、产物生成。这些东西本来就跟框架无关 ——
「这个字段是必填的」「这一列要能搜索」不是 Laravel 的知识。

拆开之后的两个直接好处：

1. **声明只写一遍**，换框架不用重写业务描述
2. **ThinkPHP 项目不会被迫装 Laravel** —— 这不是洁癖，是硬需求：
   `illuminate/support` 会抢注全局 `env()`，装进 ThinkPHP 项目会
   **启动即致命**（`Class "PhpOption\Option" not found`）。
   拆包之后 `aimanong/core` 零 `illuminate` 依赖，冲突从根上消失。

---

## 两个驱动

| | `aimanong/laravel` | `aimanong/thinkphp` |
|---|---|---|
| 框架 | Laravel 12 | ThinkPHP 8.1+ |
| 数据层 | Eloquent | ThinkORM |
| 模板 | Blade | think-template |
| 状态 | 稳定 | **可用，但请先读下面的能力对照表** |

安装时选一个（`aimanong/framework` 是元包，只拉内核 + Laravel 驱动）：

```bash
# Laravel 项目
composer require aimanong/framework

# ThinkPHP 项目
composer require aimanong/thinkphp
```

> **元包是什么**：`aimanong/framework` 没有代码，只有一个
> `composer.json`，里面 require 了 `aimanong/core` + `aimanong/laravel`。
> Laravel 用户装它就够了；ThinkPHP 用户装 `aimanong/thinkphp`。

---

## 能力对照表

**这张表的意义是：让你在选之前就知道哪些能用、哪些不能用。**
没有「敬请期待」这种模糊说法 —— 没做的就标没做。

### 两边都有

| 能力 | 说明 |
|---|---|
| ✅ 5 个契约 | 数据源 / Schema 内省 / 字典 / 权限 / 上传能力 |
| ✅ 页面（HTML） | 控制台首页 + **各资源页面**（列表/表单由声明渲染）、侧边栏、主题面板 |
| ✅ 静态资源 | 设计系统 CSS / 主题 / Vue 运行时 / LOGO（由包内资源路由提供） |
| ✅ 数据 API | `index` / `show` / `store` / `update` / `destroy`（JSON） |
| ✅ 分页 / 搜索 / 排序 / 筛选 | 由 `->searchable()` / `->sortable()` / `->filter()` 声明驱动 |
| ✅ 幽灵列检测 | 声明了不存在的列 → 编译期报错并给出 `did_you_mean` |
| ✅ `->required()` / `->requiredOnCreate()` 校验 | 新增必填、编辑可留空 |
| ✅ 唯一键 / NOT NULL 冲突 | 转成 422 + 字段名，不是 500 |
| ✅ 只读资源 | `Resource::readonly()` —— 拒绝一切写操作 |
| ✅ 认证 | 登录 / 登出 / 会话 / 记住我 |
| ✅ 登录限流 | 每 IP + 每 IP×账号 两个维度 |
| ✅ RBAC | 角色 / 权限 / 超管绕过；由 `auth.rbac` 开关控制。节点同步：`php artisan aimanong:permission sync`（两端同名） |
| ✅ 按钮级权限 | 无权限的动作按钮**服务端不渲染**（不是 CSS/JS 藏起来），无需声明 |
| ✅ 条件显示 | `$form->select('x')->visibleWhen('type', 'bug')`（等于 / 其中之一） |
| ✅ 多租户数据作用域 | `ScopeHooks`（查询 + 写入），搜索/排序/筛选都绕不过 |
| ✅ 四份产物 | `aimanong:schema` → JSON Schema / TypeScript / OpenAPI / AI 上下文 |
| ✅ 声明自检 | `ai:verify`（含 `--json`） |
| ✅ **多应用（多后台）** | 一个项目跑多个互相隔离的后台，各自独立的登录 / 菜单 / 权限 |

### 只有 Laravel 有

| 能力 | ThinkPHP 侧的情况 |
|---|---|
| ❌ `batch` / `export` / `move` | **返回 501**，不是静默失败 |
| ❌ 内置 Resource | 管理员账号 / 操作日志 / 登录日志 / 字典管理 / 个人中心 |
| ❌ MCP 工具 | 8 个 MCP 工具（AI Agent 直接调用） |
| ❌ 扩展机制 | `aimanong:make-extension` / `extensions` |
| ❌ 上传落盘 | 只有**能力描述**（前端该知道的参数），真正的存文件没实现 |
| ❌ 数据库字典 | 只读代码声明，没有后台可维护的字典表 |
| ❌ 登录日志 / 操作日志 | —— |

> ThinkPHP 侧目前提供 **3 个** Console 命令：
> `aimanong:install` / `ai:verify` / `aimanong:schema`。
> Laravel 侧有 8 个。

### 行为差异（会用得到）

| | Laravel | ThinkPHP |
|---|---|---|
| 时间字段 JSON | ISO-8601（`2026-10-14T01:23:45.000000Z`） | `2026-10-14 01:23:45` |
| 路由前缀配置键 | `aimanong.route.prefix` | `aimanong.prefix` |
| 字典配置键 | `aimanong.foundation.dict.*` | `aimanong.dict.*` |
| 权限未启用时 | 放行 | 放行（**两边一致**） |
| `schema` 默认输出目录 | `resource_path('aimanong')` | `<项目根>/aimanong` |

配置文件**不能互换** —— 上面这些键名不同。声明代码（Resource）可以。

> **多应用是例外**：两个框架的 `applications` 配置**结构完全相同**
> （`title` / `route.prefix` / `auth.guard` / `auth.model`），
> 同一个项目的两端可以对照着看。

---

## ThinkPHP 快速开始

```bash
composer require aimanong/thinkphp

# 在 app/service.php 里登记（必须是**第一项**，见下）
#   return [
#       \Aimanong\ThinkPHP\ThinkService::class,
#       \app\YourService::class,
#   ];

php think aimanong:install --admin-user=admin --admin-password=你的密码
php think aimanong:schema          # 生成四份产物
php think ai:verify                # 校验声明
```

然后打开 `/admin`。

> ⚠️ **`ThinkService` 必须是 `app/service.php` 的第一项**。
> 它的 `register()` 会绑定框架运行时与驱动；排在它前面的服务调用
> `Aimanong::registry()` 会抛「未注册」。
> `aimanong:install` 会自动把它插到第一项。

### 还要自己配的两件事

ThinkPHP 项目需要 `config/log.php` 与 `config/cache.php`
（框架用 Cache 做登录限流），标准骨架里一般已有。

### 定义一个 Resource

与 Laravel 侧**写法完全一样**：

```php
namespace app;

use Aimanong\Resource;

class CustomerResource extends Resource
{
    public static function model(): string { return Customer::class; }
    public static function label(): string { return '客户'; }
    public static function uri(): string { return 'customers'; }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('name', '名称')->searchable();
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('name')->label('名称')->required();
    }
}
```

注册它（ThinkPHP 侧在 Service 里）：

```php
use Aimanong\Aimanong;

public function register(): void
{
    Aimanong::registry()->register(CustomerResource::class);
}
```

---

## 包结构

```
packages/
├── core/        框架无关内核 —— 契约、Grid、Form、Schema、产物生成
│                零 illuminate 依赖
├── laravel/     Laravel 12 驱动 + 包资源（模板/配置/路由/迁移）
├── thinkphp/    ThinkPHP 8 驱动 + 模板/配置/路由
├── region/      省市区字段扩展
└── framework/   元包 —— 只有 composer.json，require core + laravel
```

依赖关系：

```
framework ──→ laravel ──→ core
thinkphp  ─────────────→ core
region    ──→ framework
```

**`framework` 没有 `src/` 目录**，它是 metapackage。真正的框架代码在
`core` / `laravel` / `thinkphp` 里。

---

## 多应用（多后台）

一个项目里同时跑多个互相隔离的后台。**两个框架都支持，配置结构相同。**

```php
// config/aimanong.php
'applications' => [
    'admin' => [
        'title' => '总管理后台',
        'route' => ['prefix' => 'admin'],
        'auth'  => ['guard' => 'admin', 'model' => App\Models\Administrator::class],
    ],
    'agent' => [
        'title' => '代理商后台',
        'route' => ['prefix' => 'agent'],
        'auth'  => ['guard' => 'agent', 'model' => App\Models\Agent::class],
    ],
],
```

每个后台有自己的：**路由前缀 · 登录守卫 · 用户模型 · 菜单 · 权限节点**。

### Resource 声明归属

```php
class OrderResource extends Resource
{
    // 属于哪些后台。**不写 = 所有后台都能看到**
    public static function applications(): array
    {
        return ['admin', 'agent'];
    }
}
```

### 权限按后台隔离

开启多应用后，权限节点自动带上后台前缀：

```
单应用：  orders.index
多应用：  admin.orders.index  /  agent.orders.index
```

**给代理商开的权限，不等于给总后台开了。** 而且菜单是按权限过滤的，
所以**权限一隔离，菜单隔离也自动成立**。

> **单应用项目零影响**：只在配了 `applications` 时才加前缀 ——
> 已有项目的授权不受影响。

### 升级已有项目

权限节点改名后，旧的授权需要迁一次：

```bash
php artisan aimanong:permission migrate-permissions --to=admin
```

**旧节点全部保留** —— 回滚只需把 `applications` 清空。

### 跨后台访问返回 404，不是 403

在代理商后台访问只属于总后台的资源，返回 **404**（看起来就像不存在），
而不是 403 —— 403 等于告诉对方「这个资源存在，只是你没权限」，
那是把后台结构泄露出去。

---

## 关于「一致性」，一句实话

两端**共享同一套声明与同一个内核**，但驱动实现是两份代码。
我们做了这些来保证一致：

- 内核里的编译器、产物生成器、菜单、注册表**只有一份**
- 每个驱动实现的契约都有端到端断言
- CI 里跑三个 e2e：`framework-e2e`、`http-e2e`、`thinkphp-e2e`

但**「能不能跑」和「两边一不一样」是两个问题**。
2026-10-14 的全面验收就是专门查后者，一次找出 14 处语义差异
（多租户隔离失效、只读资源可写、多对多关联静默丢弃等），
**全部已修并补上断言**。

所以：如果你在 ThinkPHP 侧发现与本文档不符的行为，
那大概率是真 bug，欢迎提 issue。

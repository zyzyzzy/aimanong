# Aimanong（AI 码农）· 元包

> 为 AI Agent 设计的后台开发框架。**这个包没有代码** —— 它只负责把
> 「框架无关内核」+「Laravel 12 驱动」一起拉下来。

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## 安装

```bash
composer require aimanong/framework
```

这一步会装：

| 包 | 内容 |
|---|---|
| `aimanong/core` | 框架无关内核（声明 DSL、Schema 编译、四产物生成）。**零 illuminate 依赖** |
| `aimanong/laravel` | Laravel 12 驱动（Eloquent 数据源、Blade 视图、路由、控制台） |

## 快速开始

```bash
# 1. 安装引导（建表 + 建管理员账号）
php artisan aimanong:install --admin-user=admin --admin-password=你的密码

# 2. 写一个 Resource（放 app/Aimanong/）
cat > app/Aimanong/OrderResource.php <<'PHP'
<?php
namespace App\Aimanong;

use Aimanong\Resource;
use App\Models\Order;

class OrderResource extends Resource
{
    public static function model(): string { return Order::class; }
    public static function label(): string { return '订单'; }
    public static function uri(): string   { return 'orders'; }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('order_no', '订单号')->searchable();
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('order_no')->label('订单号')->required();
    }
}
PHP

# 3. 校验 + 生成产物
php artisan ai:verify
php artisan aimanong:schema
```

然后访问 `/admin`。

## 用它做什么

写一个 Resource 类，自动得到四份产物：

- `schema.json` —— 声明本身的结构化形式
- `types.ts` —— TypeScript 类型（`strict` 可直接编译）
- `openapi.json` —— OpenAPI 文档
- `ai-context.md` —— 给 AI Agent 读的上下文

**这四份产物与实现不会漂移** —— 它们由同一份声明编译出来。

## 常用命令

| 命令 | 作用 |
|---|---|
| `aimanong:install` | 建表 + 建管理员 |
| `aimanong:schema` | 生成四份产物 |
| `ai:verify` | 校验声明（幽灵列、必填冲突等） |
| `aimanong:docs` | 生成能力清单 |
| `aimanong:make-resource` | 从数据表生成 Resource 骨架 |
| `aimanong:permission` | 权限节点管理（`sync` / `list` / `super` / `migrate-permissions`） |
| `aimanong:make-extension` · `aimanong:extensions` | 扩展（插件）机制 |

## 可选扩展

```bash
composer require aimanong/region   # 中国省市区三级联动字段
```

不装也能用，只是没有 `->region()` 这个字段类型。

> ⚠️ `aimanong/region` **只内置少量演示数据**（5 个省），
> 生产环境需通过 `Region::useData()` 注入完整数据集。详见该包 README。

## 用的是 ThinkPHP？

```bash
composer require aimanong/thinkphp
```

**声明代码完全一样**，只是驱动不同。两端能力有差异（ThinkPHP 侧
暂不支持 `batch` / `export` / `tree` / `move` 等），
对照表见[多框架支持](https://zyzyzzy.github.io/aimanong/guide/multi-framework)。

## 多应用（多后台）

一个项目里可以跑多个互相隔离的后台：

```php
// config/aimanong.php
'applications' => [
    'admin' => ['title' => '总管理后台', 'route' => ['prefix' => 'admin'], 'auth' => ['guard' => 'admin', 'model' => Admin::class]],
    'agent' => ['title' => '代理商后台', 'route' => ['prefix' => 'agent'], 'auth' => ['guard' => 'agent', 'model' => Agent::class]],
],
```

每个后台有独立的登录、菜单、权限。Resource 用 `applications()` 声明归属。

## 文档

- 文档站：https://zyzyzzy.github.io/aimanong
- 多框架支持：https://zyzyzzy.github.io/aimanong/guide/multi-framework
- 源码：https://github.com/zyzyzzy/aimanong

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。
2026-10-14 之前发布的版本为 MIT。

# Aimanong · Laravel 12 驱动

> 让 [Aimanong](https://github.com/zyzyzzy/aimanong) 跑在 Laravel 12 上：
> Eloquent 数据源、Blade 视图、路由、控制台命令、MCP 工具。

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## 安装

```bash
composer require aimanong/framework
```

> **通常不用直接装这个包。** `aimanong/framework` 是元包，
> 会把它和 `aimanong/core` 一起拉下来 —— 直接装这个包容易漏掉内核版本约束。

只想显式指定的话：

```bash
composer require aimanong/laravel
```

## 快速开始

```bash
php artisan aimanong:install --admin-user=admin --admin-password=你的密码
php artisan aimanong:schema     # 生成四份产物
php artisan ai:verify           # 校验声明
```

访问 `/admin`。

## 命令

| 命令 | 作用 |
|---|---|
| `aimanong:install` | 建表 + 建管理员 + 建超管角色 |
| `aimanong:schema` | 生成四份产物（JSON Schema / TypeScript / OpenAPI / AI 上下文） |
| `ai:verify` | 校验声明（幽灵列、必填冲突、未注册等） |
| `aimanong:docs` | 生成能力清单 |
| `aimanong:permission` | 权限节点：`sync` / `list` / `super` / `migrate-permissions` |
| `aimanong:make-resource` | 从数据表生成 Resource 骨架 |
| `aimanong:make-extension` | 创建扩展（插件）骨架 |
| `aimanong:extensions` | 列出已装扩展 |

## 内置 Resource

装完就有 5 个可用的后台页面（ThinkPHP 侧**没有**这些）：

| Resource | 用途 |
|---|---|
| 管理员账号 | `admin-users` |
| 角色 · 权限节点 | RBAC 管理（`auth.rbac` 打开后生效） |
| 操作日志 · 登录日志 | 审计 |
| 数据字典 · 字典条目 | 枚举选项的后台维护 |

## 多应用（多后台）

```php
// config/aimanong.php
'applications' => [
    'admin' => ['title' => '总管理后台', 'route' => ['prefix' => 'admin'], 'auth' => ['guard' => 'admin', 'model' => Admin::class]],
    'agent' => ['title' => '代理商后台', 'route' => ['prefix' => 'agent'], 'auth' => ['guard' => 'agent', 'model' => Agent::class]],
],
```

每个后台有独立的登录 / 菜单 / 权限节点。Resource 用 `applications()`
声明归属，跨后台访问返回 **404**。

已有项目开启多应用后，旧的权限授权需要迁一次：

```bash
php artisan aimanong:permission migrate-permissions --to=admin
```

**旧节点全部保留** —— 回滚只需把 `applications` 清空。

## MCP 工具

Laravel 侧提供 8 个 MCP 工具，AI Agent 可直接调用（无需读文档猜 API）。

## 扩展（插件）

```php
// config/aimanong.php
'extensions' => [
    \Aimanong\Region\RegionExtension::class,
],
```

扩展必须显式登记 —— 框架不会自动发现。

## 环境要求

- PHP ^8.2
- Laravel ^12.0（`illuminate/*` ^12.0 | ^13.0）
- `laravel/mcp` ^0.4
- 数据库：MySQL / PostgreSQL / SQLite 均可

## 用的是 ThinkPHP？

装 `aimanong/thinkphp`，**声明代码完全一样**。
两端能力差异见[多框架支持](https://zyzyzzy.github.io/aimanong/guide/multi-framework)。

## 文档

- 文档站：https://zyzyzzy.github.io/aimanong
- 源码：https://github.com/zyzyzzy/aimanong

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。

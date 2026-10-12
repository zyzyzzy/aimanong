# 快速开始

> **完全不懂编程？** 请先看 [不懂编程也能用](./no-code) ——
> 那份文档不需要任何编程基础。
>
> 本页面向会 PHP / Laravel 的开发者。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | ^8.2 |
| Laravel | ^12.0 |
| 数据库 | MySQL / PostgreSQL / SQLite 均可 |

## 安装

```bash
# 1. 创建 Laravel 12 项目（已有可跳过）
composer create-project laravel/laravel:^12.0 my-admin
cd my-admin

# 2. 安装框架
composer require aimanong/framework

# 3. 运行安装引导
php artisan aimanong:install
```

安装命令会完成五件事：

| 步骤 | 说明 |
|---|---|
| 注册服务提供者 | 写入 `bootstrap/providers.php`（Laravel 11+ 的正确方式） |
| 发布配置文件 | `config/aimanong.php` |
| 发布并执行迁移 | 建出用户 / 角色 / 权限 / 日志 / 字典等表 |
| **创建管理员账号** | 交互式询问账号密码，并绑定超级管理员角色 |
| **同步权限节点** | 把内置 Resource 的权限点写入数据库 |
| 打印登录信息 | 账号、密码、访问地址 |

> 权限节点是**自动推导**出来的（`{uri}.{action}`），但必须执行
> sync 才写入数据库。install 已自动帮你做这一步；之后新增
> Resource 时要手动跑一次：
>
> ```bash
> php artisan aimanong:permission sync
> ```
>
> 不跑的话，新 Resource 的权限在「角色 → 分配权限」界面里看不到，
> 也就无法分配给任何角色（超管绕过判定，不受影响）。

### 关于管理员账号

安装过程会问你账号和密码：

```
── 创建第一个管理员账号 ──
 账号 [admin]:
 密码（直接回车则使用随机强密码）:
```

- **直接回车** → 生成 16 位随机强密码并打印出来，请立刻记下
- 已有账号时**跳过**，不会重置你改过的密码
- 安装完成后请立即登录并修改密码（个人中心 → 修改密码）

> ⚠️ 不创建账号的话，装完是**进不去后台的** ——
> 而「管理员账号」界面本身也要先登录才能打开，
> 所以这是必须的一步。

### 非交互安装（CI / 脚本）

```bash
php artisan aimanong:install \
  --admin-user=admin \
  --admin-password='换成强密码' \
  --no-interaction
```

| 参数 | 说明 |
|---|---|
| `--admin-user=` | 指定账号，不传则交互询问 |
| `--admin-password=` | 指定密码，不传则交互询问 / 随机生成 |
| `--no-admin` | 跳过创建账号（后续可重跑 `--force` 补建） |
| `--force` | 覆盖已存在的配置文件 |

## 创建第一个 Resource

一个 Resource = 一张数据表 = 一组后台页面。

```php
<?php

declare(strict_types=1);

namespace App\Aimanong;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;
use App\Models\User;

class UserResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function label(): string
    {
        return '用户';
    }

    public static function uri(): string
    {
        return 'users';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('name', '姓名')->searchable();
        $grid->column('email', '邮箱')->searchable();
        $grid->column('created_at', '创建时间')->dateTime()->sortable();
    }

    public static function form(Form $form): void
    {
        $form->text('name', '姓名')->required()->max(255);
        $form->email('email', '邮箱')->required()->rules('email');
        $form->switch('enabled', '是否启用');
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'name', 'email']);
    }
}
```

## 注册 Resource

在任意服务提供者的 `boot()` 中注册：

```php
<?php

namespace App\Providers;

use Aimanong\Aimanong;
use Illuminate\Support\ServiceProvider;

class AimanongResourceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Aimanong::registry()->register(\App\Aimanong\UserResource::class);
    }
}
```

然后把它加到 `bootstrap/providers.php`：

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AimanongResourceProvider::class,
];
```

## 访问后台

```bash
php artisan serve
```

打开 `http://localhost:8000/admin`，用安装时创建的账号登录。

你的 Resource 会自动出现在 `/admin/users`，包含**列表、新增、编辑、删除、搜索、排序、分页** ——
**这些都不需要写任何控制器或前端代码。**

## 验证装好了没

```bash
php artisan ai:verify
```

输出 `✅ 全部通过` 即可。它会检查：声明是否合法、
Resource 是否已注册、有没有拼错的列名。

## 下一步

| 想做什么 | 看哪份 |
|---|---|
| 理解框架原理 | [核心概念](/guide/concepts) |
| 查字段怎么用 | [字段类型](/api/fields) |
| 查列表页选项 | [列展示器](/api/columns) |
| 用内置能力（日志/字典/上传/个人中心） | [基座能力](/guide/foundation) |
| 让 AI Agent 帮你写 | [AI 协作指南](/guide/ai-collaboration) |
| **不懂编程** | [不懂编程也能用](/guide/no-code) |

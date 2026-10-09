# 快速开始

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

安装命令会自动完成：

- 注册服务提供者到 `bootstrap/providers.php`（Laravel 11+ 的正确方式）
- 发布配置文件 `config/aimanong.php`
- 发布并执行数据库迁移

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

## 下一步

- [核心概念](/guide/concepts) —— 理解 Schema 编译与数据流
- [字段类型](/api/fields) —— 全部 27 种字段
- [列展示器](/api/columns) —— 列表页可用选项
- [AI 协作指南](/guide/ai-collaboration) —— 如何让 AI Agent 使用本框架

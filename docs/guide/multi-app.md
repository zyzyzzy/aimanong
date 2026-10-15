# 多应用：从零配一遍

> 这是**走查**，不是 API 参考。照着做，你能把 `/admin` `/agent` 两个后台
> 在一个项目里跑起来，并确认它们**真的隔离**。
>
> 概念与配置项说明见 [多框架支持](./multi-framework#多应用-多后台)。

---

## 你将得到什么

```
/admin     总管理后台   运营用，能看全部，能配系统
/agent     代理商后台   代理商用，只看被授权的模块

两个后台：各自登录页 · 各自菜单 · 各自权限节点
```

用例以 Laravel 为例。ThinkPHP 的差异在最后一节。

---

## 前提

一个已经跑起来的 Aimanong 后台（照 [快速开始](./quickstart) 做完）：

- `composer require aimanong/framework` 装好了
- `php artisan aimanong:install` 跑过了
- `/admin` 能打开

> ⚠️ **先把单后台跑通再开多应用。** 多应用出问题时，「配置错」和「框架坏」
> 很难区分 —— 有个已知能用的基线，排查会快很多。

---

## 第 1 步：给第二个后台建用户体系

代理商和管理员**不是同一批人**，字段也不同，所以**单独一张表**。

```bash
php artisan make:model Agent -m
```

```php
// database/migrations/xxxx_create_agents_table.php
Schema::create('agents', function (Blueprint $table) {
    $table->id();
    $table->string('username', 190)->unique();   // ⚠️ 必须是 username
    $table->string('name')->nullable();
    $table->string('password');
    $table->string('company')->nullable();
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
```

> ⚠️ **后台用户体系统一用 `username` 登录**（不是 `email`）。
> 登录接口按 `username` 查库；表里没有这一列的话，登录会**静默失败**
> （表现为「密码明明对，就是登不进去」）。
>
> 用 `users` 表当第二个后台时，记得加这一列。

模型要实现 `Permissible` 契约 —— 权限判定**基于能力而不是具体类**：

```php
namespace App\Models;

use Aimanong\Concerns\HasAimanongPermissions;
use Aimanong\Contracts\Permissible;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Agent extends Authenticatable implements Permissible
{
    use HasAimanongPermissions;   // 角色关联 + isSuper()/hasPermission()/isEnabled()

    protected $table = 'agents';
    protected $fillable = ['username', 'name', 'password', 'company', 'enabled'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'password' => 'hashed'];
    }
}
```

```bash
php artisan migrate
```

> **为什么必须实现契约**：框架判权限时不能假设「用户一定是 `Administrator`」，
> 否则会把你第二个后台的用户当成**未登录** → 登录成功但到处 403。
> 这个坑我们在两个框架上各踩过一次。

---

## 第 2 步：加 guard 与 provider

`config/auth.php`：

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],

    // 每个后台一个 guard
    'admin' => ['driver' => 'session', 'provider' => 'admin'],
    'agent' => ['driver' => 'session', 'provider' => 'agent'],
],

'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => env('AUTH_MODEL', User::class)],

    'admin' => ['driver' => 'eloquent', 'model' => \Aimanong\Models\Administrator::class],
    'agent' => ['driver' => 'eloquent', 'model' => \App\Models\Agent::class],
],
```

**为什么每个后台要独立的 guard**：登录态存在 session 里，共用一个 key 时
两个后台会互相顶掉（在 `/admin` 登录，`/agent` 会读到同一个 id 去查
`agents` 表 —— 要么查不到，要么**查到同 id 的另一个人**）。

---

## 第 3 步：声明这两个后台

`config/aimanong.php`：

```php
'applications' => [
    'admin' => [
        'title' => '总管理后台',
        'route' => ['prefix' => 'admin'],
        'auth'  => ['guard' => 'admin', 'model' => \Aimanong\Models\Administrator::class],
    ],
    'agent' => [
        'title' => '代理商后台',
        'route' => ['prefix' => 'agent'],
        'auth'  => ['guard' => 'agent', 'model' => \App\Models\Agent::class],
    ],
],
```

**顺序有意义**：第一个是「无请求上下文时」的默认应用
（CLI 命令、队列任务拿不到请求路径，只能按配置顺序取）。

### 🔴 确认 RBAC 打开

```php
'auth' => [
    'rbac' => true,   // 不开的话权限判定恒放行，隔离根本测不出来
],
```

---

## 第 4 步：声明 Resource 属于哪个后台

```php
class OrderResource extends Resource
{
    public static function uri(): string { return 'orders'; }

    // 属于哪些后台。**不写 = 所有后台都能看到**
    public static function applications(): array
    {
        return ['admin', 'agent'];
    }
}
```

系统管理类（管理员账号、角色、日志、字典）应该**只属于主后台**。
框架内置的那几个已经声明好了 —— 用的是 `PRIMARY` 占位符：

```php
use Aimanong\Contracts\ApplicationResolver;

public static function applications(): array
{
    return [ApplicationResolver::PRIMARY];   // 只属于主后台（配置里第一个应用）
}
```

> ⚠️ **不要写死应用名**（如 `['admin']`）。你的主后台可能叫 `sys` / `ops`，
> 写死的话这些页面会**在任何后台都看不见** —— 静默消失，没有任何报错。

---

## 第 5 步：跑起来并**验证隔离**

```bash
php artisan config:clear
php artisan aimanong:permission sync
```

### 造两个后台的登录账号

```php
$agent = \App\Models\Agent::create([
    'username' => 'agent1', 'name' => '代理A',
    'password' => 'agent123', 'enabled' => true,
]);

// 挂角色（⚠️ 用模型的角色方法，不要直接写关联表）
$role = \Aimanong\Models\Role::firstOrCreate(
    ['slug' => 'agent_role'],
    ['name' => '代理商角色', 'is_super' => false]
);
$agent->aimanongRoles()->syncWithoutDetaching([$role->id]);
```

### 自查清单

逐条确认，**不要跳过** —— 下面每一条都对应真实踩过的坑：

| # | 检查 | 命令 / 方法 | 期望 |
|---|---|---|---|
| 1 | 两个登录页都能开 | `curl -o /dev/null -w '%{http_code}' .../admin/auth/login` | `200` |
| 2 | 各自跳**自己**的登录页 | 未登录访问 `/agent` | `302 → /agent/auth/login`（**不是** `/admin/...`） |
| 3 | 两边都能登录 | 分别用 admin / agent1 登录 | 首页 `200` |
| 4 | 菜单不串台 | 看 `/agent` 的侧边栏 | **没有**「管理员账号」「操作日志」这类系统管理项 |
| 5 | 权限节点带前缀 | `select slug from admin_permissions limit 10` | `admin.orders.index` / `agent.orders.index` |
| 6 | 跨后台访问被拒 | 用 agent 的登录态访问 `/agent/api/admin-users` | **`404`**（不是 403） |

> **第 6 条为什么必须是 404**：403 等于告诉对方「这个资源存在，
> 只是你没权限」—— 那是把后台结构泄露出去。404 才与「真的没注册」不可区分。

### 菜单为空是正常的

新账号**没有任何权限**时，菜单就是空的 —— 那不是 bug。
去「角色」里给它分配权限，菜单才会出现对应模块。

---

## 第 6 步：迁移已有的权限授权

**如果你是从单应用升级上来的**，权限节点改名了：

```
升级前：  orders.index
升级后：  admin.orders.index   /   agent.orders.index
```

不迁移的话，所有人一升级就全部 403：

```bash
# 先看会迁多少（不写入）
php artisan aimanong:permission migrate-permissions --to=admin --dry-run

# 确认后执行
php artisan aimanong:permission migrate-permissions --to=admin
```

**旧节点全部保留** —— 回滚只需把 `applications` 清空，授权立刻恢复原样。

---

## ThinkPHP 侧的差异

声明代码**完全一样**，配置结构也**逐字相同**。差异在这些地方：

### 1. 必须先登记 Service（且必须第一项）

```php
// app/service.php
return [
    \Aimanong\ThinkPHP\ThinkService::class,   // ← 必须第一项
    \app\YourService::class,
];
```

排在它前面的服务调用 `Aimanong::registry()` 会抛「未注册」。
`aimanong:install` 会自动插到第一项。

### 2. 用户的角色挂法不同

TP 侧用 `assignRole()`（**不能**裸 attach —— `wherePivot` 只作用于读取，
写入不会带 `user_type`，结果是「刚授的权立刻查不到」）：

```php
$agent->assignRole([$role->id]);
```

### 3. ⚠️ ThinkPHP 侧目前**没有资源页面的 HTML 路由**

只有控制台页（`/admin`）与 JSON 数据 API（`/admin/api/{uri}`）。

```
/admin                 200 ✅
/admin/api/customers   200 ✅
/admin/customers       404 ❌
```

控制台菜单里的链接目前指向这些不存在的页面。完整差异见
[多框架支持](./multi-framework)。

---

## 常见问题

**登录成功但到处 403**
→ 用户模型没实现 `Permissible`，或 RBAC 没开。

**登不进去，密码是对的**
→ 用户表缺 `username` 列（框架按 `username` 查库）。

**两个后台互相顶掉登录态**
→ guard 没分开，或 session key 没带应用维度。

**菜单全空**
→ 该账号没被授予任何权限（正常），或权限节点没同步（跑 `aimanong:permission sync`）。

**CLI 里拿到的应用不对**
→ 应用的**配置顺序**决定 CLI 下的默认应用，把主后台放第一个。

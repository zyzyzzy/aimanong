# Aimanong · ThinkPHP 8 驱动

> 让 [Aimanong](https://github.com/zyzyzzy/aimanong) 跑在 ThinkPHP 8 上。
> **同一套 Resource 声明，Laravel 与 ThinkPHP 都能用，声明代码不用改。**

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## 安装

```bash
composer require aimanong/thinkphp
```

装上之后 `vendor/illuminate` **不存在** —— 这个包不会把 Laravel 拖进来。

> 为什么这点重要：`illuminate/support` 会抢注全局 `env()`，
> 装进 ThinkPHP 项目会**启动即致命**（`Class "PhpOption\Option" not found`）。
> Aimanong 的内核（`aimanong/core`）零 `illuminate` 依赖，从根上避开这个问题。

## 必须做的一步

在 `app/service.php` 里登记 `ThinkService`，**且必须是第一项**：

```php
return [
    \Aimanong\ThinkPHP\ThinkService::class,   // ← 必须第一项
    \app\YourService::class,
];
```

> ⚠️ 它的 `register()` 会绑定框架运行时与驱动。排在它前面的服务调用
> `Aimanong::registry()` 会抛「未注册」。
> 跑 `aimanong:install` 会自动帮你插到第一项。

## 快速开始

```bash
php think aimanong:install --admin-user=admin --admin-password=你的密码
php think aimanong:schema     # 生成四份产物
php think ai:verify           # 校验声明
```

然后访问 `/admin`。

### 定义一个 Resource

与 Laravel 侧**写法完全一样**：

```php
namespace app;

use Aimanong\Resource;

class CustomerResource extends Resource
{
    public static function model(): string { return Customer::class; }
    public static function label(): string { return '客户'; }
    public static function uri(): string   { return 'customers'; }

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

注册它（ThinkPHP 侧在 Service 的 `register()` 里）：

```php
use Aimanong\Aimanong;

public function register(): void
{
    Aimanong::registry()->register(CustomerResource::class);
}
```

## 命令

| 命令 | 作用 |
|---|---|
| `aimanong:install` | 建表 + 建管理员 + 登记 Service |
| `aimanong:schema` | 生成四份产物 |
| `ai:verify` | 校验声明 |

**只有这 3 个**（Laravel 侧有 8 个）。`make-resource` / `docs` /
`permission` / `make-extension` 等在 ThinkPHP 侧尚未实现 ——
不假装有，调用会得到明确的「命令不存在」。

## 这个包**没有**什么

如实列出（避免你配到一半才发现）：

| 缺失 | 说明 |
|---|---|
| `batch` / `export` / `move` | **返回 501**，不是静默失败 |
| 内置 Resource | 管理员账号 / 操作日志 / 登录日志 / 字典管理等（Laravel 侧有 5 个） |
| MCP 工具 | Laravel 侧有 8 个供 AI Agent 直接调用 |
| 扩展（插件）机制 | `make-extension` / `extensions` |
| 上传落盘 | 只有**能力描述**（前端该知道的参数），真正的存文件未实现 |
| 数据库字典 | 只读代码声明，没有后台可维护的字典表 |
| **个人中心页** | `/admin/profile` **404**（顶栏「个人中心」链接点不进去）。资源页面本身是有的 |
| ~~树形~~ | ✅ **已支持**：`tree()` 声明 + `/api/{uri}/tree` + 层级缩进/展开折叠，两端一致 |
| 内置页面路由 | Laravel 侧的管理员/角色/日志/字典页面，TP 侧都没有 |

**有的**：5 个契约 · **控制台页面 + 资源页面（HTML）** · 数据 API（JSON） ·
静态资源（设计系统 CSS / Vue 运行时 / LOGO） ·
分页/搜索/排序/筛选 · 幽灵列检测 · 必填校验 · 只读资源 · 认证 ·
登录限流 · RBAC · 多租户数据作用域 · **多应用（多后台）** ·
四份产物 · `ai:verify`。

完整对照表：https://zyzyzzy.github.io/aimanong/guide/multi-framework

## 多应用（多后台）

```php
// config/aimanong.php —— 与 Laravel 侧**结构完全相同**
'applications' => [
    'admin' => ['title' => '总管理后台', 'route' => ['prefix' => 'admin'], 'auth' => ['guard' => 'admin', 'model' => \Aimanong\ThinkPHP\Models\Administrator::class]],
    'agent' => ['title' => '代理商后台', 'route' => ['prefix' => 'agent'], 'auth' => ['guard' => 'agent', 'model' => \app\Agent::class]],
],
```

Resource 用 `applications()` 声明归属；权限节点自动带后台前缀
（`agent.orders.index`）；跨后台访问返回 **404**（不泄露资源是否存在）。

## 环境要求

- PHP ^8.2
- ThinkPHP ^8.0
- `topthink/think-template` ^3.0（会自动装上）
- 项目需有 `config/log.php` 与 `config/cache.php`（框架用 Cache 做登录限流；标准骨架一般已有）

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。

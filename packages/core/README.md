# Aimanong · 框架无关内核

> Aimanong 的**核心**：声明 DSL（Resource / Grid / Form / Show / Tree）、
> Schema 编译器、四产物生成器。
>
> **零 `illuminate` 依赖，零 ThinkPHP 依赖** —— 只要求 PHP 8.2 与 `ext-json` / `ext-mbstring`。

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## 安装

```bash
composer require aimanong/core
```

> 如果你只是想在 Laravel 项目里用 Aimanong，**不要直接装这个包** ——
> 装 `aimanong/framework`，它会把内核和 Laravel 驱动一起拉下来。

## 这个包**不包含**什么

它是内核，不是应用。没有：

- ❌ HTTP 服务（没有路由、控制器、中间件）
- ❌ 后台界面（没有页面、没有前端资源）
- ❌ 数据库访问（没有 ORM、没有数据源实现）
- ❌ 认证 / 权限 / 上传的实现

这些由**驱动包**提供（`aimanong/laravel`、`aimanong/thinkphp`）。

**装 `aimanong/core` 单独跑不出一个后台。** 它的价值在于：
把「声明 → 产物」这一层变成框架无关的，让第二个、第三个框架驱动
不必重写一遍。

## 它有什么

| 目录 | 内容 |
|---|---|
| `Resource.php` | 声明基类（`uri()` / `label()` / `model()` / `grid()` / `form()` / `menu()` / `applications()`） |
| `Grid/` | 列表页声明（列、`searchable()` / `sortable()` / `filter()`） |
| `Form/` | 表单声明（30 个字段类型、校验规则、`required()` / `requiredOnCreate()`） |
| `Show/` | 详情页声明 |
| `Tree/` | 树形结构声明 |
| `Schema/` | 编译器 + 4 个产物生成器 |
| `Schema/Emitters/` | `JsonSchemaEmitter` · `TypeScriptEmitter` · `OpenApiEmitter` · `AiPromptEmitter` |
| `Contracts/` | 驱动契约（见下） |
| `Menu/` · `Registry.php` | 菜单与资源注册表 |
| `Ai/` | AI 自省与错误可读化 |

四份产物：

```
Resource 声明 ──┬──→ schema.json        （结构化声明）
                ├──→ types.ts           （TypeScript，strict 可直接编译）
                ├──→ openapi.json       （OpenAPI 文档）
                └──→ ai-context.md      （给 AI Agent 读的上下文）
```

**它们由同一份声明编译出来，不会与实现漂移。**

## 驱动契约

内核不绑定任何框架，它通过契约向外要能力：

| 契约 | 要什么 |
|---|---|
| `SchemaIntrospector` | 数据表/模型的列信息（幽灵列检测靠它） |
| `DictionarySource` | 数据字典（枚举选项） |
| `PermissionChecker` | 权限判定（`check()` / `slug()`） |
| `UploadSpec` | 上传能力描述（大小上限、允许类型） |
| `FrameworkRuntime` | 框架入口（URL、注册表、当前用户、控制台） |
| `ApplicationResolver` | 多应用（多后台）解析 |
| `Permissible` | 可被权限判定的用户模型 |
| `DataSource` | 数据读写（分页、增删改查） |
| `PageResult` | 分页结果（屏蔽各框架分页器差异） |

实现这组契约，就能把 Aimanong 接到另一个框架上。

> **`ApplicationResolver` 的类注释里写了「驱动实现必须遵守的三条硬规则」**
> （路径推断、CLI 不读进程状态、**归属校验必须先于权限校验且返回 404**）。
> 这三条都是真实事故换来的 —— 两个驱动各犯过一遍。

## 独立使用（不经 HTTP）

内核可以脱离框架生成产物。**但产物生成需要驱动** —— 例如
`JsonSchemaEmitter` 要 `UploadSpec`（上传约束是 schema 的一部分），
没有驱动会抛 `DriverNotRegisteredException`：

```php
use Aimanong\Contracts\UploadSpec;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Schema\Emitters\TypeScriptEmitter;
use Aimanong\Support\Drivers;

// 产物里含上传约束，所以要先给一个 UploadSpec
Drivers::registerUploadSpec(new class implements UploadSpec {
    public function toArray(): array
    {
        return ['max_size' => 10 * 1024 * 1024, 'accept' => ['image/*'], 'disk' => 'public'];
    }
});

$node = (new Compiler)->compile(App\Aimanong\OrderResource::class);

$json = (new JsonSchemaEmitter)->emit($node);   // array
$ts   = (new TypeScriptEmitter)->emit($node);   // string
```

两点说明：

- `Compiler` 需要 `SchemaIntrospector` 才能做列校验 ——
  不传时能编译，但**不会报幽灵列**（声明了不存在的列）
- 上面这段是可以直接跑的（我们验证过）；驱动缺失时的报错信息会明确
  告诉你缺哪个契约

> 这段代码是**实测跑通**才写进来的。第一版只写了 `new Compiler` +
> emitter，实际会抛 `DriverNotRegisteredException` ——
> 文档教一个跑不通的片段，比不写更糟。

## 环境要求

- PHP ^8.2
- `ext-json`、`ext-mbstring`

## 文档

- 文档站：https://zyzyzzy.github.io/aimanong
- 源码：https://github.com/zyzyzzy/aimanong

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。

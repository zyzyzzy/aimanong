# 核心概念

## 一句话模型

> **一个 Resource = 一张数据表 = 一组后台页面。**

你只写一个 PHP 类，框架产出其余全部。

## 架构分层

```
┌──────────────────────────────────────────────┐
│ 消费层（三种客户端，同一套 Schema）             │
│  ├─ 人：Vue 3 页面                            │
│  ├─ AI：自省 API / MCP Server                 │
│  └─ 第三方：REST API                          │
├──────────────────────────────────────────────┤
│ Schema 编译层（核心枢纽）                      │
│  PHP 声明 → AST → {JSON Schema, TS 类型,      │
│                    OpenAPI, AI 上下文}        │
├──────────────────────────────────────────────┤
│ DSL 层                                        │
│  Resource / Grid / Form / Show                │
├──────────────────────────────────────────────┤
│ 基础设施                                       │
│  认证 / 数据源抽象 / 自省 / 校验                │
└──────────────────────────────────────────────┘
```

## 为什么要有编译层

**问题**：传统框架里，文档、类型定义、API 契约、前端页面是**四份独立的真相**。
改了一处忘了另一处，就产生"文档说 A、实现做 B"的漂移 —— 这恰恰是 AI 出错的最大来源。

**解法**：让它们都从同一份声明编译出来。

```bash
php artisan aimanong:schema          # 生成四份产物
php artisan aimanong:schema --check  # 检测漂移（漂移则 exit=1，可阻断 CI）
```

| 产物 | 位置 | 用途 |
|---|---|---|
| `schema.json` | `resources/aimanong/` | 前端运行时渲染依据 |
| `types.ts` | 同上 | 前端 TypeScript 类型 |
| `openapi.json` | 同上 | REST API 文档 |
| `ai-context.md` | 同上 | **给 AI 读的上下文** |

**改动 Resource 后必须重新生成**，否则 `--check` 会失败。

## 数据流

以访问 `/admin/users` 为例：

```
1. 浏览器请求 /admin/users
2. HomeController 取到 UserResource，编译为 ResourceNode
3. JsonSchemaEmitter 产出 schema.json 并注入页面
4. Vue 3 按 schema 渲染表格（列名、搜索框、排序都在 schema 里）
5. 前端请求 /admin/api/users?page=1
6. ResourceController 从编译产物取「可搜索列」「校验规则」
7. Repository 查询并返回分页数据
```

**关键：第 4、6 步的列名和规则都不是硬编码的**，全部来自声明。

## 三个阶段方法

Resource 类实现三个静态方法，分别对应三类页面：

| 方法 | 对应页面 | 说明 |
|---|---|---|
| `grid(Grid $grid)` | 列表页 | 定义列、搜索、排序、分页 |
| `form(Form $form)` | 新增/编辑页 | 定义字段、校验、默认值 |
| `show(Show $show)` | 详情页 | 定义展示字段 |

三个方法都是**静态的**，且没有 `$model` / `$uri` 属性 —— 它们通过静态方法返回。

## 数据源抽象

Controller 依赖 `Aimanong\Contracts\Repository` 契约，而非具体 ORM：

```php
interface Repository
{
    public function paginate(array $params = [], ?int $defaultPerPage = null): LengthAwarePaginator;
    public function create(array $data): Model;
    public function update(int|string $id, array $data): Model;
    public function delete(int|string $id): bool;
    public function find(int|string $id): Model;
}
```

默认实现是 `EloquentRepository`，你可以替换成 API 数据源、数组数据源等。

## 校验的双重保障

声明里的规则同时作用于**前后端**：

```php
$form->text('name')->required()->max(255);
```

- 前端：渲染必填标记，提交前提示
- 后端：`ResourceController` 从编译产物取规则，校验失败返回 422

规则只写一次，两边永远一致。

## 下一步

- [字段类型](/api/fields) —— 26 种字段的完整用法
- [列展示器](/api/columns) —— 列表页的渲染选项
- [AI 协作指南](/guide/ai-collaboration) —— AI 如何发现和使用这些能力

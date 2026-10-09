# AI 协作指南

本框架的设计目标是：**让 AI Agent 无需读源码就能完成开发任务。**

这一页说明具体怎么做，以及框架为此提供了什么。

## 给 AI 的三种接入方式

### 方式一：MCP 工具（推荐）

如果 AI 客户端支持 MCP（Claude Desktop / Cursor / Cline 等）：

```bash
php artisan mcp:start aimanong
```

可用工具：

| 工具 | 用途 | 何时用 |
|---|---|---|
| `list_resources` | 列出全部 Resource 及字段 | **第一步总是调它** |
| `describe_resource` | 看某 Resource 完整定义 | 修改前先确认字段名 |
| `scaffold_resource` | **从数据库表生成代码** | 表已存在时首选 |
| `create_page` | 按指定字段生成代码 | 表尚未创建时用 |
| `validate_declaration` | 校验声明 | **生成后必调** |
| `run_verify` | 项目整体自检 | 改动多个 Resource 后 |
| `query_data` | 只读查询真实数据 | 调试、确认真实形态 |
| `search_docs` | 检索框架用法 | 不确定 API 时 |

**标准工作流**：

```
list_resources → scaffold_resource → validate_declaration → run_verify
```

### 方式二：HTTP 自省接口

```bash
curl http://localhost:8000/__ai/capabilities.json
```

| 接口 | 说明 |
|---|---|
| `/__ai/capabilities.json` | 全部能力清单（含每个字段类型的特有方法） |
| `/__ai/schema/{uri}` | 某 Resource 的完整定义 |
| `/__ai/openapi.json` | OpenAPI 3.1 文档 |
| `/__ai/context` | Markdown 版上下文 |
| `/__ai/examples/{pattern}` | 可复制代码片段 |
| `/__ai/verify` | 校验声明合法性 |

> 安全：默认仅 `local` / `debug` 环境开启；生产需配置 `AIMANONG_AI_TOKEN`。

### 方式三：命令行自检

```bash
php artisan ai:verify              # 校验全部 Resource
php artisan ai:verify --json       # JSON 输出（便于程序解析）
php artisan ai:verify "App\\Aimanong\\UserResource"   # 校验单个
```

## 核心机制一：可自愈错误

框架的异常会带修正建议，AI 读到即可一次改对。

```json
{
  "error": "UNKNOWN_FIELD_TYPE",
  "message": "字段类型 'texte' 不存在，是否想用 'text'？",
  "did_you_mean": "text",
  "hint": "可用字段类型: text, textarea, select, switch, ...",
  "example": "$form->text('name')->label('名称')->required();",
  "docs": "https://aimanong.com/llms/fields.txt"
}
```

已实现的模糊匹配：

| 输入 | 建议 |
|---|---|
| `texte` | `text` |
| `taxtarea` | `textarea` |
| `swich` | `switch` |
| `customer_nmae` | `customer_name` |
| `App\Aimanong\UserResourc` | `App\Aimanong\UserResource` |

## 核心机制二：需求达标度校验

> ⚠️ **这一条最重要。**

`validate_declaration` **默认只检查语法合法性**，不检查是否满足你的需求。

如果任务有具体要求，**必须传 `requirements` 参数**：

```json
{
  "resource": "App\\Aimanong\\OrderResource",
  "requirements": {
    "searchable": "order_no,customer_name",
    "sortable": "amount,paid_at",
    "required": "order_no",
    "per_page": 30
  }
}
```

它会逐条核对并明确指出缺什么：

```
## 需求核对

✅ 可搜索: order_no, customer_name — 已满足
❌ 可排序: amount, paid_at — 缺少 paid_at（当前: amount）
   修正: $grid->column('paid_at', '...')->sortable();
✅ 每页 30 条 — 已生效（运行时实测 30 条）

⚠️ 1 项需求未满足 —— 请修正后再交付
```

**为什么重要**：AI 实测中发现，如果没有这个校验器，脚手架生成的代码缺了 3 条需求，
而 `validate_declaration` 仍返回「✅ 语法校验通过」 —— AI 会误判成功并交付残次品。

> 注意「**已生效（运行时实测 30 条）**」这个措辞：`per_page` 是真的跑了一次分页查询，
> 而不是只读声明值。早期版本只读声明，导致"声明写了但功能没生效"却报绿灯。

## 核心机制三：幽灵列检测

拼错列名（如 `customer_nmae`）会被立刻拦截，并给出最接近的真实列名。
**不会静默通过** —— 早期版本会静默忽略该列，AI 拿不到任何反馈。

## 写 Resource 的铁律

1. **三个方法必须是 `static`** —— `grid()` / `form()` / `show()` 都是静态方法
2. **没有 `$model` / `$uri` 属性** —— 用静态方法返回
3. **方法签名唯一** —— 不存在重载，不要猜第二种写法
4. **不嵌套闭包** —— 所有配置走链式调用
5. **字段必须是数据库真实列**（或已定义的访问器/关系）
6. **生成后必须调 `validate_declaration`**

## AI 实测记录

本框架经过三轮 AI 实测迭代，全部记录公开在仓库：

| 轮次 | 一次成功率 | 框架纠错贡献 | 是否读源码 |
|---|---|---|---|
| 第一轮 | 0.5 | 0% | 读了 |
| 第二轮 | 0.5 | 50% | 读了 |
| 第三轮 | **1.0** | **100%** | **没读** |

第三轮的测试条件是：**明确禁止 AI 阅读框架源码**，
只能使用文档、MCP 工具、自省接口和错误信息。

结果：**写代码阶段 0 错误**，首次校验即通过。

三轮共发现并修复 11 个真实缺陷，包括：

- 验证器只查语法、不查需求达标度（**最致命**）
- MCP 搜索静默失效（返回全表而不是报错）
- `did_you_mean` 实际返回 null（文档承诺落空）
- 文档示例用非静态写法 → 照抄跑不起来
- 未知 query 参数静默忽略（`order=` 不生效也不报错）
- `map()` 整数键被序列化成数组，前端取值失败

详见仓库 `docs/AI实测记录.md`。

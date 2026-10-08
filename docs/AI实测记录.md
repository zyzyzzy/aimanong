# AI 任务集实测记录

> 每个任务的实测结果，用于追踪「AI 一次成功率」北极星指标。

---

## 测试方法

1. 启动一个**完全不了解本框架**的 AI Agent
2. 只提供两份材料：`llms.txt`（框架文档）+ MCP 工具访问权
3. 让它独立完成任务，**不给予任何人工提示**
4. 记录：是否一次成功、错在哪、错误信息是否帮到它
5. **父 Agent 独立复核**产出（不轻信子 Agent 自述）

---

## L2-4：为 products 表创建管理后台

**日期**：2026-10-09
**任务**：为 `products` 表（id/title/price/stock/status/published/timestamps）创建 Resource
**要求**：title+status 可搜索、price+stock 可排序、title 必填、注册到 Provider

### 结果

| 项 | 结果 |
|---|---|
| **一次成功率** | **0.5**（初版不合格，需修正） |
| **框架纠错贡献** | **0**（修正靠读源码，非框架错误信息） |
| 生成文件 | `app/Aimanong/ProductResource.php`（55 行）+ `app/Models/Product.php` |
| 框架自检 | `ai:verify` → 2 个 Resource 全部通过 |
| Schema 漂移 | `--check` → exit=0，无漂移 |
| 页面渲染 | ✅ 标题「商品」、8 列全部正确 |
| 搜索验证 | ✅ 搜「键盘」→ 精确 1 条 |
| 排序验证 | ✅ 点价格列 → ↑ 指示器出现 |
| 控制台错误 | ✅ 零 JS 错误 |

### ⚠️ 关键更正：这不是一次成功

**首版记录误判为 1.0，原因是只看了最终产物的正确性，没有追溯过程。**

AI 的诚实报告揭示：**`scaffold_resource` 生成的代码不能直接用**，有 4 处硬伤：

| # | 脚手架产出 | 问题 | 谁发现的 |
|---|---|---|---|
| 1 | `use App\Models\Product;` | 模型不存在，`model()` 会崩 | AI 自己 |
| 2 | `status` 无 `->searchable()` | 漏了需求 | AI 自己 |
| 3 | `price`/`stock` 无 `->sortable()` | 漏了需求 | AI 自己 |
| 4 | `published` 推成 `number` + 必填 | 布尔被当成数字 | AI 自己 |

**关键：这 4 处问题，`validate_declaration` 和 `run_verify` 全部报「通过」。**

验证器只检查「语法合法 / 能编译」，**不检查「是否满足需求」**。AI 若把 verify 通过当成交付标准，会交出残次品，且拿到绿灯。

AI 之所以最终写对，是因为它**动手前先读了框架源码**——而不是框架帮它纠错。按评分标准：

> 需 ≥2 次修正，或修正未借助框架错误信息 = 应记 0.5 甚至 0

**这次实战恰恰否定了 M3 的核心假设：框架的纠错机制没有参与纠错。**

### 发现的确凿 Bug（父 Agent 已独立复现）

| # | Bug | 复现证据 | 严重度 |
|---|---|---|---|
| 1 | MCP `query_data` 关键词搜索**静默失效** | 传「键盘」返回全表 3 条而非 1 条 | **高**（AI 唯一调试手段被污染） |
| 2 | llms.txt 承诺的 `did_you_mean` 实际为 `null` | `context()` 无该键，`didYouMean()` 返回 NULL | **高**（文档承诺落空） |
| 3 | `$show->field()->dateTime()` 必然致命 | `Text` 类无 `dateTime()` 方法 | **中**（照文档写必崩） |
| 4 | llms.txt 主推示例用了实例属性 + 非静态方法 | 基类三方法全 static，无 `$model` 属性 | **高**（照抄示例跑不起来） |

Bug 1 的失败方式尤其危险：**静默返回全表**比报错更糟，AI 会以为"数据本来就这样"，从而做出错误判断。

### 产出代码质量

AI 生成的代码**完全符合框架约定**：

```php
public static function grid(Grid $grid): void
{
    $grid->column('id', 'ID')->sortable();
    $grid->column('title', '标题')->searchable();
    $grid->column('price', '价格')->sortable();
    $grid->column('stock', '库存')->sortable();
    $grid->column('status', '状态')->searchable();
    // ...
}

public static function form(Form $form): void
{
    $form->text('title')->label('标题')->required()->max(255);
    $form->decimal('price')->label('价格')->decimals(2);   // ← 用了 capabilities 里才有的方法
    $form->number('stock')->label('库存')->default(0);
    $form->switch('published')->label('是否上架');
}
```

**关键观察**：它用了 `decimals(2)` 和 `default(0)` —— 这两个方法**只存在于 `/__ai/capabilities.json` 的反射输出里**，不在 llms.txt 的任何示例中。说明 AI 真的去查了自省接口，而不是从训练数据里猜。**这验证了自省接口的核心假设：AI 会用机器可读的接口代替猜测。**

同时它自觉做到了：
- 没有写任何前端代码（遵守铁律）
- 没有嵌套闭包（遵守铁律）
- 主动创建了缺失的 Eloquent 模型 `App\Models\Product`

### 结论

**L2 难度任务一次成功率 = 0.5，未达 ≥85% 目标。**

**M3 的核心假设未通过验证**：框架的纠错机制（可自愈错误、验证器）在此次任务中**没有参与纠错**。AI 靠读源码绕过问题，而不是靠框架的错误信息。

按开发计划 §九 的风险预案：

> 若 M3 验收不达标，应暂停后续开发，回头重设计 API，而不是继续堆组件。

### 因此决定：先修问题，再考虑 M4

必须修的 4 项（按优先级）：

1. **验证器要能查"需求达标度"** ← 最致命
   现在 `validate_declaration` 全绿但产出缺需求。AI 会把绿灯当交付标准。
   方向：支持传入需求描述做对齐检查；或让 `scaffold_resource` 明确输出"我漏了什么、为什么不确定"。

2. **文档与实现对齐**（3 处矛盾）
   - 示例改 static、去掉 `$model` 属性
   - 异常补上 `did_you_mean` 键
   - `Show` 补 `dateTime()` 等方法，或文档改正确用法

3. **MCP `query_data` 搜索修复** + 静默失败改为显式报错

4. **`scaffold_resource` 引用不存在的 Model 要提示**
   它读了表结构，却不知道 `app/Models/Product.php` 不存在。

### 保留的优点（经验证成立）

- ✅ `scaffold_resource` 读真实表结构，8 个字段名/类型零猜测
- ✅ **「一份声明、永不漂移」是真的**：`searchable()`/`sortable()` 一处声明，
  schema.json / openapi.json / TypeScript / ai-context.md / HTTP API / MCP 全部同步，`--check` 零漂移
- ✅ `ai-context.md` 反向给 AI 读的设计有效
- ✅ 测试质量（`EmittersTest` 连"四份产物字段数一致"都测了）

### 待改进（体验层）

| 问题 | 影响 | 建议 |
|---|---|---|
| `status` 生成为 text 而非 select | 列表显示 `on_sale` 原始值 | `scaffold_resource` 按列名语义（status/type/state）建议 select |
| `published` 布尔显示 `true/false` | 不直观 | 布尔列默认渲染为标签 |
| 参数名写错与资源不存在返回同一错误 | 无法区分 | 缺失参数应返回 MISSING_PARAMETER |

---

## L2-4 重测（orders 表，7 条验收要求）

**日期**：2026-10-09（修复后第二轮）
**任务**：为 `orders` 表创建 Resource，7 条明确要求（含"每页 30 条"这类易漏项）

### 结果

| 项 | 结果 |
|---|---|
| **一次成功率** | **0.5**（仍需修正，但**框架参与了一半纠错**） |
| 7 条验收要求 | ✅ 全部满足 |
| 框架纠错贡献 | **50%**（3 项由框架发现，3 项由 AI 自己读源码发现） |

### 框架的纠错机制**这次起作用了**

| 问题 | 谁发现的 |
|---|---|
| 缺 searchable/sortable/perPage（3 条需求） | ✅ **requirements 校验器**明确报出并给修正代码 |
| `App\Models\Order` 不存在 | ✅ **框架**报 MODEL_NOT_FOUND |
| `perPage()` API 存在但无文档 | ❌ AI 自己读源码 |
| `perPage(30)` 声明了却不生效 | ❌ AI 自己实测 |
| 幽灵列不被拦截 | ❌ AI 自己注入实验 |

AI 原话：

> **requirements 校验器是本次唯一救命机制。** 如果不用它，`validate_declaration` 对那份缺 3 条需求的产物会返回「✅ 语法校验通过」，我会误判成功并交付一个不合格的 Resource。

**对比第一轮（框架纠错贡献 0）→ 第二轮（贡献 50%），说明修复方向正确。**

### 但暴露了一个更严重的问题：验证器假阳性

**AI 发现 `perPage(30)` 是假的** —— 校验器报「每页 30 条 ✅ 已设置」，
但 `EloquentRepository::paginate()` 只读 HTTP 参数，**从不读 `$grid->perPage()`**。

实测确认：37 行数据，不传参 → `perPage=20`。

**这是比"不检查"更危险的问题**：校验器给了绿灯，功能却没实现。
上一轮的问题是"缺需求也全绿"，这一轮是"需求写了但不生效也全绿"——
**同一类失效的两种表现：只验证声明，不验证运行时行为。**

### 本轮发现并修复的 5 个缺陷

| # | 缺陷 | 修复 | 验证 |
|---|---|---|---|
| A | `perPage` 声明不生效（假阳性） | Controller 传声明值 + Repository 接收 `$defaultPerPage` | ✅ 实测 30 生效，`?per_page=5` 仍可覆盖 |
| B | requirements 在语法失败时被丢弃 | 失败分支也输出需求核对 | ✅ |
| C | 幽灵列静默通过 | 新增 `GhostColumnException` + 编译期检查，带 `did_you_mean` | ✅ `customer_nmae` → `customer_name` |
| D | 未注册的 Resource 也能全绿 | 新增 `NOT_REGISTERED` 检查 | ✅ |
| E | AGENTS.md 谎称检查"路由/权限/迁移" | 改为实际能力描述 + 补充 requirements 用法 | ✅ |

### 关键改进：需求校验从"读声明"改为"跑运行时"

```diff
- $actual = $node->meta['perPage'];        // 只读声明 → 假阳性
+ $actual = $this->runtimePerPage($node);  // 真跑一次分页 → 可信
```

现在输出：`✅ 每页 30 条 — 已生效（运行时实测 30 条）`

### 教训（写进开发原则）

> **校验器只读元数据 = 假阳性工厂。**
> 任何"已设置"的断言，都必须有对应的运行时验证。

回归测试：新增 `RuntimeVerificationTest`（5 项），
专门覆盖"声明了但没生效"这一类问题。

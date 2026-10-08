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
| **一次成功率** | **1.0** |
| 生成文件 | `app/Aimanong/ProductResource.php`（55 行）+ `app/Models/Product.php` |
| 框架自检 | `ai:verify` → 2 个 Resource 全部通过 |
| Schema 漂移 | `--check` → exit=0，无漂移 |
| 页面渲染 | ✅ 标题「商品」、8 列全部正确 |
| 搜索验证 | ✅ 搜「键盘」→ 精确 1 条 |
| 排序验证 | ✅ 点价格列 → ↑ 指示器出现 |
| 控制台错误 | ✅ 零 JS 错误 |

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

**L2 难度任务一次成功率 = 1.0，达到 ≥85% 的目标。**

验证了 M3 的核心假设：**只要提供自省接口 + 可自愈错误，AI 能独立完成框架开发任务，无需人工干预。**

### 待改进

| 问题 | 影响 | 建议 |
|---|---|---|
| `status` 生成为 text 而非 select | 列表显示 `on_sale` 原始值，不够友好 | `scaffold_resource` 可识别列名语义（status/type/state）自动建议 select + map |
| `published` 布尔值显示为 `true/false` | 不够直观 | 默认布尔列应转为开关标签渲染 |

这两项属于**体验优化**而非功能缺陷，记入 M4 待办。

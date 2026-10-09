# Aimanong v1.1.0

> 本版本的新增能力，**全部来自真实业务场景验证与官方插件开发** —— 而非凭空设计。

```bash
composer require aimanong/framework
```

---

## 新增

### 关联列

商品列表要显示「分类名」而非 `category_id` —— 框架原来不支持。

```php
$grid->column('category.name', '所属分类');   // 点号自动识别
```

- 自动预加载，**无 N+1**
- 可参与搜索（`whereHas`）、筛选、排序

### 条件高亮

需求「库存少于 10 要一眼看出来」—— 框架原来**完全无法实现**。

```php
$grid->column('stock', '库存')->dangerBelow(10);
$grid->column('sold_count', '销量')->warningAbove(100);
```

> 条件用「运算符 + 阈值」而非闭包 —— 保证可序列化、AI 可读、无隐式魔法。

### 插件注入字段类型

```php
// 插件侧
$this->field(MyField::class, 'mytype', 'string', 'string');

// 使用侧（与内置字段无异）
$form->mytype('field', '标签');
```

### 查询列安全

**编译期拒绝，运行期兼容**：

| 防线 | 拦截内容 |
|---|---|
| `GhostColumnException` | 本表不存在的列 / 关联不存在 / 非法字符 |
| `InvalidQueryColumnException` | 关联目标表没有该列 |

---

## 修复（4 个真实缺陷）

| 严重度 | 问题 |
|---|---|
| **高危** | 导出与界面不一致：`map()` 列导出 `paid` 而非「已付款」，关联列导出空值。文档承诺「一致」实际只有行数一致 |
| **高危** | 关联列搜索 500，且**拖垮同一查询的其它条件** |
| 中危 | Repository 替换点对 HTTP 不生效（Controller 硬编码实现，未解析容器契约） |
| — | 幽灵列检测过松（只查 `method_exists`，不验是否返回 `Relation`） |
| — | 校验器假阳性（对关联列增加**运行时探测**） |

---

## 官方插件

**[aimanong/region](https://github.com/zyzyzzy/aimanong/tree/main/packages/region)** —— 中国省市区三级联动（首个官方插件）

- 三级联动 / 两级模式（`cityLevel()`）
- 自带数据源与级联查询路由
- 独立 Composer 包，不改框架核心

---

## 文档

- **[AI 使用手册](https://zyzyzzy.github.io/aimanong/guide/ai-handbook)** —— 写给指挥 AI 用本框架的人
- **[发布前验证流程](https://github.com/zyzyzzy/aimanong/blob/main/internal-docs/发布前验证流程.md)** —— 7 节流程 + 4 条原则

---

## 验证

```
bash scripts/pre-release.sh --with-e2e   → 全部通过
  framework-e2e  27/27
  http-e2e       20/20
  PHPUnit        98 tests / 314 assertions
  PHPStan        Level 8 零错误
  Pint           115 files PASS
```

---

## 流程改进

**实测期间框架必须冻结。**

v1.0 期间犯过这个错：在 AI 实测进行中修改框架，导致其早期探测结论失效
（同一文件 21:08 测 500、21:14 测 200）。已写入流程文档。

---

**许可**：MIT —— 允许免费商用。


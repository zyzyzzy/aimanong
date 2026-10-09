# Aimanong v1.0.0

> **Aimanong（AI 码农）** —— 一个「AI 优先」的后台开发框架，基于 Laravel 12 + Vue 3。
> 让任何 AI Agent 在 5 分钟内理解框架、30 分钟内产出可运行的后台系统。

```bash
composer require aimanong/framework
php artisan aimanong:install
```

---

## 核心特性

### 🤖 为 AI 而设计

| 能力 | 说明 |
|---|---|
| 自省接口 | `/__ai/*` —— capabilities / schema / openapi / context / examples / verify |
| MCP Server | 8 个工具，任何 AI 客户端可直接调用 |
| 可自愈错误 | 异常自带 `did_you_mean` / `example` / `hint` / `docs` |
| 需求达标度校验 | 传 `requirements` 逐条核对，`per_page` **运行时实测**而非只读声明 |

### 📐 一份声明，四份产物

```bash
php artisan aimanong:schema          # 生成
php artisan aimanong:schema --check  # 漂移检测（可阻断 CI）
```

PHP 声明 → JSON Schema / TypeScript 类型 / OpenAPI 文档 / AI 上下文。
**消灭文档与实现漂移** —— 这是 AI 出错的最大来源。

### 🎨 开箱即用

- **26 个字段类型** + 6 个列展示器（bool / badge / money / image / link / progress）
- **数据导出**：CSV，复用列表的筛选逻辑
- **树形结构**：含循环引用检测（A→B→A 返回 422 而非无限递归）
- **分步表单**：无嵌套闭包声明
- **插件系统**：依赖检查 + 失败隔离
- **多应用**：独立前缀 / guard / 用户模型

---

## 质量数据

| 项 | 结果 |
|---|---|
| 单元测试 | 81 tests / 265 assertions |
| PHPStan | Level 8 零错误 |
| 端到端 | 47 项（27 框架 + 20 HTTP） |
| **AI 实测** | **5 轮，一次成功率 0.5 → 1.0，累计修复 20 个真实缺陷** |

### 五轮 AI 实测

| | 一 | 二 | 三 | 四 | 五 |
|---|---|---|---|---|---|
| 一次成功率 | 0.5 | 0.5 | 1.0 | 1.0 | **1.0** |
| 框架纠错贡献 | 0% | 50% | 100% | 100% | **100%** |
| 是否读源码 | 读了 | 读了 | 没读 | 没读 | **没读** |

测试方式：给 AI 一份文档和一个 MCP 连接，**不给任何提示**，让它独立完成开发任务。

📖 [完整实测报告](https://zyzyzzy.github.io/aimanong/blog/ai-first-in-practice)

---

## 安全承诺

- 统一输出转义，杜绝 XSS
- 目录穿越防护
- 敏感接口默认关闭（生产需 token）
- **漏洞响应 SLA**：确认 72 小时内 / 严重漏洞 7 天内修复

> 对比：参考对象 dcat-admin 有 4 条公开 CVE **全部零补丁**。

---

## 已知限制

| 项 | 说明 |
|---|---|
| `draggable()` | 声明会被编译，但**前端拖拽 UI 尚未实现**（编译产物含 `draggable_supported: false`） |
| 导出格式 | 仅 CSV，暂无 xlsx |
| 前端组件库 | 使用内联 Vue（CDN），尚未发布独立 npm 包 |

---

## 链接

- 📖 [文档站](https://zyzyzzy.github.io/aimanong/)
- 🎮 [Playground 在线试玩](https://zyzyzzy.github.io/aimanong/playground/)
- 📝 [五轮 AI 实测报告](https://zyzyzzy.github.io/aimanong/blog/ai-first-in-practice)
- 📋 [完整 CHANGELOG](https://github.com/zyzyzzy/aimanong/blob/main/CHANGELOG.md)
- 🏷️ [商标政策](https://github.com/zyzyzzy/aimanong/blob/main/TRADEMARK.md) · [商业化说明](https://github.com/zyzyzzy/aimanong/blob/main/COMMERCIAL.md)

**许可**：MIT —— 允许免费商用。

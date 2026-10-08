# AI 码农 · Aimanong

> **AI-First 后台开发框架** —— 让任何 AI Agent 在 5 分钟内理解框架、30 分钟内产出可运行的后台系统。

基于 **Laravel 12 + Vue 3**，专为 AI Agent 设计，同时为人类保留完整文档。

```
        斗笠 = 农   电路 = 码   新芽 = AI
        一个让代码自己长出来的农夫
```

## 为什么是 AI-First

传统后台框架（dcat-admin、Filament 等）的使用者是人，追求灵活与优雅。
AI Agent 的需求完全不同：**低歧义、可自省、可验证、错误可自愈**。

Aimanong 为此明确牺牲部分"灵活性"来换取"确定性"：

| 设计 | 目的 |
|---|---|
| 禁止方法重载、禁止嵌套闭包 | 降低 AI 生成歧义 |
| 一份声明 → Schema 编译出全部产物 | 消灭文档与实现漂移 |
| 自省 API + MCP Server | AI 无需"读文档"，直接调工具 |
| 错误带 `didYouMean` + 正确示例 | AI 一次改对 |

## 快速开始

> 开发中，尚未发布首个版本。参见 [开发计划](./docs/开发计划.md)。

## 仓库结构

| 路径 | 说明 |
|---|---|
| `packages/framework` | 核心框架（Composer: `aimanong/framework`） |
| `packages/ui` | Vue 3 组件库（npm: `@aimanong/ui`） |
| `docs/` | 文档站（VitePress） |
| `demo/` | 示例项目 |

## 文档

- [llms.txt](./llms.txt) —— **AI 读这个**
- [AGENTS.md](./AGENTS.md) —— **AI Agent 项目内约定**
- [开发计划](./docs/开发计划.md) —— 人读这个

## 状态

| 里程碑 | 状态 | 说明 |
|---|---|---|
| M0 地基 | ✅ **完成** | Laravel 12.69.3 实测：安装、认证、登录、会话全部跑通 |
| M1 Schema 编译层 | ✅ **完成** | 一份声明 → 四份产物，漂移检测可阻断 CI |
| M2 核心 DSL + Vue | ⬜ 下一个 | Grid / Form / Show 端到端 |
| M3 AI 能力层 | ⬜ | 自省 API + MCP Server |
| M4 组件库 | ⬜ | 字段与展示器 |
| M5 增强 | ⬜ | Tree / 分步表单 / 多应用 |
| M6 生态与文档 | ⬜ | 文档站 / 代码生成器 / v1.0 |

### M0 已交付

```
packages/framework/
├── src/
│   ├── AimanongServiceProvider.php   中间件组 / 路由 / 配置注入
│   ├── Auth/AdminGuard.php           GuardHelpers 实现
│   ├── Auth/AdminUserProvider.php    含 Laravel 11+ 新契约方法
│   ├── Http/Middleware/              Authenticate / Session / Bootstrap
│   ├── Http/Controllers/             Auth / Home
│   ├── Models/Administrator.php      Authenticatable 契约
│   ├── Registry.php                  Resource 注册表
│   └── Contracts/Resource.php
├── config/aimanong.php
├── database/migrations/              admin_users 表
├── resources/views/                  登录页 / 控制台
└── routes/admin.php
```

**Laravel 12 兼容性实测结论**：

- ✅ 安装命令用 `addProviderToBootstrapFile()` 正确写入 `bootstrap/providers.php`
- ✅ 认证链路：`attempt()` 成功 / 错误密码拒绝 / 会话保持
- ✅ HTTP 层：登录页 200、CSRF 生效（419）、登录后首页 200
- ✅ 已规避硬断点：`rehashPasswordIfRequired()`、`getAuthPasswordName()`、`redirectTo($request)`

### M1 已交付

```
packages/framework/src/Schema/
├── Compiler.php              声明 → AST 编译管道
├── Ast/                      ResourceNode / ColumnNode / FieldNode
└── Emitters/
    ├── JsonSchemaEmitter.php 前端运行时 Schema
    ├── TypeScriptEmitter.php 前端类型
    ├── OpenApiEmitter.php    REST 文档
    └── AiPromptEmitter.php   给 AI 读的上下文（本项目独有）
```

**四份产物由同一份声明编译，永不漂移**：

```bash
php artisan aimanong:schema           # 生成
php artisan aimanong:schema --check   # 漂移检测（漂移 exit=1 阻断 CI）
```

实测：改动声明后四份产物**同时**检出漂移；重新生成后恢复一致。
字段类型映射正确（`switch`→`boolean`、`number`→`number`）、22 项断言全绿。

AI 打错类型时会得到可自愈提示：`texte` → 建议 `text`。

## 许可

MIT License

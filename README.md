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

| 里程碑 | 状态 |
|---|---|
| M0 地基 | 🚧 进行中 |
| M1 Schema 编译层 | ⬜ |
| M2 核心 DSL + Vue | ⬜ |
| M3 AI 能力层 | ⬜ |
| M4 组件库 | ⬜ |
| M5 增强 | ⬜ |
| M6 生态与文档 | ⬜ |

## 许可

MIT License

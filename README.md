<div align="center">
  <img src="docs/public/assets/logo.png" alt="Aimanong" width="160" />

# AI 码农 · Aimanong

> **AI 优先的 PHP 后台开发框架** —— 一份声明，四份产物，AI 独立完成开发。

[![Version](https://img.shields.io/badge/version-1.6.3-blue.svg)](./CHANGELOG.md)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](./LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-blue.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-%5E12.0-red.svg)](https://laravel.com)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E8.0-green.svg)](https://www.thinkphp.cn)

🌐 **[English](./README_EN.md)** | 中文

</div>

## 这是什么

Aimanong（AI 码农）是一个 **AI 优先的 PHP 后台开发框架**。你写一份声明式 Resource，框架自动编译出 JSON Schema、TypeScript 类型、OpenAPI 文档和 AI 上下文四份产物；前端由 Schema 驱动渲染，不需要手写 Vue 组件。

设计原则见 [项目宪法](./CONSTITUTION.md)。

## 给谁用

| ✅ 是 | ❌ 不是 |
|---|---|
| 有编程基础的程序员 | 完全不懂编程的人 |
| 会用命令行、装过 PHP 环境 | 连环境配置都不会的人 |
| 能看懂 AI 写了什么（但不亲自写） | 完全无法判断代码对错的人 |
| 用 AI 开发自己的 Web 后台系统 | 想拖拽建站的非技术人员 |

完整工作流见 [用 AI 开发你的后台](./docs/guide/ai-workflow.md)。

## 快速开始

```bash
composer require aimanong/framework
php artisan aimanong:install
```

> 📖 完整安装指南与第一个 Resource 的写法 → [快速上手](./docs/guide/quickstart.md)
>
> 当前版本：**core 1.6.3 · laravel 1.3.3 · thinkphp 1.4.3** | [更新日志](./CHANGELOG.md)

## 核心能力

| 能力 | 说明 | 文档 |
|---|---|---|
| 声明驱动 | 一份 Resource → Schema / TS / OpenAPI / AI 上下文，`--check` 阻断漂移 | [Schema 编译](./docs/guide/concepts.md) |
| CRUD 后台 | 列表 / 搜索 / 排序 / 分页 / 增删改查，全部由声明推导 | [Resource 声明](./docs/api/resources.md) |
| AI 自省 | `/__ai/capabilities.json` + MCP Server + `ai:verify` | [AI 工作流](./docs/guide/ai-workflow.md) |
| 30 种字段 | text / email / select / tree / money / image / tags … | [字段参考](./docs/api/fields.md) |
| 基座能力 | 登录日志 / 操作日志 / 数据字典 / 文件上传 / 个人中心 / 管理员账号，开箱即用 | [基座能力](./docs/guide/foundation.md) |
| 树形 / 导出 / 分步表单 | `$grid->tree()` / `$grid->export()` / `$form->step()` | [高级功能](./docs/guide/concepts.md) |
| 插件系统 | 继承 Extension，依赖检查 + 失败隔离 | [多框架](./docs/guide/multi-framework.md) |
| 多应用 | 独立前缀 / guard / 用户模型，请求级隔离 | [多应用](./docs/guide/multi-app.md) |
| 多框架 | Laravel 12 + ThinkPHP 8，同一套声明两端行为一致 | [多框架](./docs/guide/multi-framework.md) |
| RBAC 权限 | 按钮级权限，无权限的按钮服务端不渲染 | [AI 协作](./docs/guide/ai-collaboration.md) |

## 文档

| 给谁 | 链接 |
|---|---|
| 👤 人读 | [在线文档站](https://zyzyzzy.github.io/aimanong/) |
| 🤖 AI 读 | [llms.txt](./llms.txt) · [AGENTS.md](./AGENTS.md) |
| 🎮 试玩 | [Playground](https://zyzyzzy.github.io/aimanong/playground/) |
| 📊 API 参考 | [由 Schema 自动生成](./docs/api/)，永不漂移 |
| 📝 更新日志 | [CHANGELOG.md](./CHANGELOG.md) |

## 仓库结构

| 路径 | 说明 |
|---|---|
| `packages/core` | 框架无关内核（Grid / Form / Schema / 契约） |
| `packages/laravel` | Laravel 驱动 |
| `packages/thinkphp` | ThinkPHP 驱动 |
| `packages/framework` | Composer 元包 |
| `packages/region` | 官方扩展：中国省市区三级联动 |
| `docs/` | 文档站 + Playground |
| `tests/e2e/` | 端到端测试 |
| `scripts/` | 发版检查、demo 搭建 |

> **前端没有独立包**：Vue 3 内置在框架里（零构建），页面由 Schema 自动渲染，使用者不需要 npm、不需要打包。

## 许可

**Apache License 2.0** —— 允许免费商用、修改、分发、闭源，并明文授予专利许可。

| 你可以 | 你不可以 |
|---|---|
| ✅ 商用、修改、分发、闭源 | ❌ 用 "Aimanong" 命名你的 fork 或产品 |
| ✅ 用于公司内部系统 | ❌ 暗示官方背书 |
| ✅ 获得贡献者的明文专利授权 | ❌ 就本项目专利起诉他人（授权自动终止） |

1.10.1 及更早的版本按 MIT 授权且永远保持 MIT；Apache-2.0 从 1.11.0 起对新版本生效。两者都是宽松许可，实际自由度没有变化。

见 [LICENSE](./LICENSE) · [商标政策](./TRADEMARK.md) · [商业化说明](./COMMERCIAL.md)

> **我们的承诺**：框架本体永久免费，不做社区版功能阉割；收费的是新增能力（企业插件、托管服务、支持），不是把免费的东西锁起来。

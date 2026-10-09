# 端到端测试

两个脚本，覆盖 M0–M6 全部功能与多应用隔离。

## 前提

需要一个运行中的 demo 项目（含完整数据）：

```bash
cd <demo-app>
php artisan serve --port=8899
```

## 运行

```bash
# 1. 框架功能测试（27 项）—— 不需要 HTTP
php tests/e2e/framework-e2e.php

# 2. HTTP 层测试（20 项）—— 需 demo 服务运行
php tests/e2e/http-e2e.php
```

## 覆盖范围

**framework-e2e.php**

| 分组 | 内容 |
|---|---|
| M0 基础 | ServiceProvider / admin guard / 管理员模型 |
| M1 编译 | AST / 四份产物 |
| M2 DSL | Grid / Form / Repository |
| M3 AI 层 | capabilities / MCP / 验证器 / 需求核对器 |
| M4 字段 | 26 个类型 / 6 个展示器 |
| M5 增强 | 导出 / 树形 / 循环检测 / 分步表单 / 多应用 / 插件 |
| M6 生态 | 代码生成器 / 文档命令 |
| 上下文收口 | ApplicationContext 推断正确 |

**http-e2e.php**

| 分组 | 内容 |
|---|---|
| 多应用隔离 | 两个后台登录页 / 登录流程 / 无重定向循环 |
| 功能页面 | 树形 / 分步 / 代码生成 / 综合 |
| 功能接口 | 树形 API / 导出 CSV / 列表 / 未开启导出的 403 |
| AI 自省 | 4 个接口 + M5 元数据完整性 |
| 资源与安全 | LOGO 免登录 / 目录穿越拦截 |

## 为什么不放进 PHPUnit

这两个脚本依赖**真实数据库数据与运行中的 HTTP 服务**，
不适合放进单元测试套件（会让 CI 变脆）。

单元测试（`vendor/bin/phpunit`）覆盖纯逻辑，
这两个脚本覆盖**集成与真实行为** —— 两者互补。

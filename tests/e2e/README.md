# 端到端测试

三个脚本，覆盖 M0–M6 全部功能、v1.6.0 基座能力与多应用隔离。

## 前提

需要一个运行中的项目（含数据）。**可以一键从零搭建**：

```bash
bash scripts/setup-demo.sh /tmp/demo-e2e
cd /tmp/demo-e2e && php artisan serve --port=8899
```

> 这个脚本同时是「新人装框架能否跑通」的验证 ——
> 它跑通，说明安装路径没问题。

## 运行

```bash
# 1. 框架功能测试 —— 不需要 HTTP
DEMO_PATH=/tmp/demo-e2e php tests/e2e/framework-e2e.php

# 2. HTTP 层测试 —— 需服务运行
E2E_BASE=http://localhost:8899 DEMO_PATH=/tmp/demo-e2e php tests/e2e/http-e2e.php

# 3. 生成的 types.ts 能否通过真实 tsc 编译
bash tests/e2e/tsc-check.sh /tmp/demo-e2e
```

## 可移植性（重要）

前两个脚本**不再假定 demo 专有的 Resource 存在**。

这一点是 2026-10-11 补上的：在此之前它们直接引用
`App\Aimanong\CategoryResource` 等类，在全新安装的项目上会直接崩，
所以 **CI 根本没法跑 e2e** —— 而 v1.5.0/v1.6.0 两个大版本
新增了 7 项能力，e2e 覆盖为 0，发布检查却一直是绿的。

现在它们的策略是：

- 通用断言 → 用框架**内置** Resource（任何项目都有）
- demo 专有能力（树形 / 分步 / 多应用 / 插件）→ **有则验证，无则明确跳过**
- 「跳过」和「通过」在输出里是两件事，不会把「没测」伪装成「没问题」

实测：
- 全新项目：framework-e2e 50/50、http-e2e 24/24
- demo 项目：framework-e2e **64/64**、http-e2e 32/32

## 覆盖范围

**framework-e2e.php**

| 分组 | 内容 |
|---|---|
| M0 基础 | ServiceProvider / admin guard / 管理员模型 |
| M1 Schema | 四份产物齐备 |
| M2 DSL | Grid / Form / Repository |
| M3 AI 层 | capabilities / MCP / 验证器 / 需求核对器 |
| M4 字段 | 字段类型数 / 6 个展示器 |
| M5 增强 | 导出 / 树形 / 分步 / 多应用 / 插件（条件） |
| M6 生态 | 生成器 / 文档命令 |
| 应用上下文 | prefix / guard / CLI 回退 |
| **基座能力（v1.6.0+）** | **内置 Resource / 只读三层一致 / 上传安全 / 字典 / 审计 / 管理员密码契约 / 个人中心 / 能力面一致性 / 业务语言可检索 / 可选插件守卫 / 插件字段 UI 注入** |

**http-e2e.php**

| 分组 | 内容 |
|---|---|
| 多应用隔离 | admin / merchant（条件） |
| 登录链路 | 登录成功 / 登录态生效 / 无重定向循环 |
| 各功能页面 | 树形 / 分步 / 生成器 / 综合（条件） |
| 功能接口 | 树形 API / 导出 CSV / 列表 API |
| AI 自省接口 | capabilities / schema / openapi / context |
| **基座能力 · 页面** | **七项内置能力页面全部可访问** |
| **基座能力 · 只读** | **POST / DELETE 必须 403（对照可写资源）** |
| **基座能力 · 上传** | **未登录读文件被拒** |
| **基座能力 · 同义词** | **能力面暴露 also_known_as** |
| 资源与安全 | LOGO 免登录 / 目录穿越拦截 |

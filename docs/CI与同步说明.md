# CI 与仓库同步说明

> 记录 `.github/workflows/` 的用途、当前配置决策，以及为什么这样选。
> 避免以后重复讨论同样的问题。

## `.github/workflows/` 是什么

GitHub Actions 的自动化配置目录。放在这个固定路径，GitHub 会自动识别并执行。

Gitee 里也有这个目录，是因为**仓库被完整镜像过去了** —— Gitee 只是原样保存文件，
**不会执行 GitHub Actions**。

> 参考：Vue、Redis、Spring Boot 等项目的 Gitee 镜像仓里同样保留 `.github` 目录。
> 这是镜像仓的普遍现象，不影响任何功能。

## 当前两个 workflow

### 1. `ci.yml` —— 质量门禁

每次 push 到 `main` / `develop`，或提 PR 时自动运行：

| Job | 检查项 |
|---|---|
| 框架质量门禁（PHP 8.3） | Pint 代码风格 → PHPStan Level 8 → PHPUnit |
| PHP 8.2 兼容性 | 确认不依赖 8.3 独占语法 |

**关键：所有命令都在 `packages/framework` 下执行。**

本仓库是 monorepo，根目录**没有** `composer.json`。早期版本的 CI 假设在根目录执行，
导致第一步 `composer install` 就失败 —— 这个 bug 已修复。

> ⚠️ 修改 CI 时务必保留 `defaults.run.working-directory: packages/framework`。

### 2. `mirror-to-gitee.yml` —— 同步到 Gitee

push 后自动把代码同步到 Gitee。

**当前未配置 Secrets，因此该 job 会优雅跳过**（不会报红）。

## 决策记录

### 决策 1：保留 `.github`，不做推送过滤

**日期**：2026-10-09

**背景**：有反馈「Gitee 上显示 `.github/workflows` 不好看」。

**评估过的方案**：

| 方案 | 结论 |
|---|---|
| 推送时过滤掉 `.github` | 技术可行（实测通过），但**未采纳** |
| 从仓库删除 `.github` | ❌ **不可行** —— 会同时失去 CI 与同步能力 |
| 保持现状 | ✅ **采纳** |

**未采纳过滤的原因**：
1. 大厂镜像仓（Vue/Redis/Spring Boot）都保留 `.github`，属普遍现象
2. 过滤需在每次同步时额外生成临时分支，增加同步逻辑复杂度
3. 收益仅是「目录列表好看一点」

**重要澄清**：**「过滤」与「删除」是两回事。**
- 过滤 = 只对 Gitee 隐藏，GitHub 保留 CI 能力
- 删除 = 两边都失去 CI 与同步

### 决策 2：暂不配置 Gitee 同步的 Secrets

**日期**：2026-10-09

**Secret 的作用**：让同步从「手动推两次」变成「推一次自动同步」。

| | 未配置（当前） | 已配置 |
|---|---|---|
| 同步方式 | 手动 `git push origin main && git push gitee main` | 自动 |
| 功能影响 | 无 | 无 |
| Token 攻击面 | 无 | 增加 |

**未配置的原因**：
1. 目前同步由协作的 AI Agent 负责，两边 commit 始终一致
2. 减少凭据数量 = 减少攻击面
3. 需要时可随时补配

**如需启用**，需在 GitHub 仓库添加两个 Secret：

| Secret 名 | 值 |
|---|---|
| `GITEE_USERNAME` | Gitee 用户名（URL 里的，非昵称），当前为 `zyzyzzy_admin` |
| `GITEE_TOKEN` | Gitee 私人令牌，仅需 `projects` 权限 |

添加位置：`https://github.com/zyzyzzy/aimanong/settings/secrets/actions/new`

**为什么不使用 Gitee 官方镜像功能**：

Gitee 官方「仓库镜像管理」文档标注「限时开放至 2022-08-31」，
据社区反馈现已设「开源评估指数」门槛，**个人项目基本不可用**。
当前用 GitHub Actions 推送的方案是社区推荐做法。

参考：
- [Gitee 官方镜像文档](https://help.gitee.com/repository/settings/sync-between-gitee-github)
- [GitHub 仓库备份到 Gitee 的 4 种方法](https://post.smzdm.com/p/avgwv409/)

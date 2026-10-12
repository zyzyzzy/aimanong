# 发布到 Packagist 操作指南

> **目标**：让 `composer require aimanong/framework` 这条命令对真实用户可用。
>
> 当前状态：**不可用**。文档教的这条命令跑不通（详见下方「背景」）。

---

## 背景：为什么现在跑不通

`composer require aimanong/framework` 失败，两层原因：

1. **Packagist 上没有这个包**（实测：`packagist.org/packages/aimanong/framework`
   跳转到 `search/?reason=package_not_found`）
2. **仓库根目录没有 composer.json** —— 用 VCS 方式也装不了
   （实测：`composer show --all` 报 `could not load a package from it`）

而 `scripts/setup-demo.sh` 能跑通，是因为它先注册了**本地 path 仓库**：

```bash
composer config repositories.aimanong path "$ROOT/packages/framework"
```

真实用户没有这个 path 仓库，所以照 README 第一步就失败。

> 这是宪法第六条的违规：**文档教了一条跑不通的命令**。

---

## 为什么不能直接发（monorepo 障碍）

本仓库是 monorepo，包在 `packages/framework` 子目录：

```
aimanong/
├── packages/
│   ├── framework/composer.json   ← 包在这里
│   └── region/composer.json
├── docs/  tests/  scripts/
└── （根目录没有 composer.json）
```

**Composer 的 VCS/GitHub 驱动只读仓库根目录的 composer.json，不支持子目录**
（官方文档明确：`package-path` 选项**只对 SVN 驱动**有效）。

已实测确认：直接拿 `github.com/zyzyzzy/aimanong` 当 VCS 仓库，
`composer show --all` 报 `could not load a package from it`。

---

## 方案：subtree split（已实测可行）

把 `packages/framework` 拆成一个**独立可发布单元**（根目录含 composer.json）。

本机已验证拆分结果：
- 文件数 **186**，与 `packages/framework` 受版本控制文件数**完全一致** ✓
- 拆分分支根目录含 `composer.json`，`name = aimanong/framework` ✓
- composer.json 必需字段齐全（name/description/license/require/autoload/extra）✓

---

## 你要做的（约 30 分钟）

### 第 1 步：在 GitHub 建一个专用仓库

打开 https://github.com/new

- **Repository name**: `aimanong-framework`
- **可见性**: Public
- **不要**初始化 README / .gitignore / license（保持空仓库）

### 第 2 步：告诉我仓库地址

创建完把完整地址发我，形如：

```
https://github.com/zyzyzzy/aimanon-framework.git
```

剩下的（拆分、推 tag、配 webhook、验证）我来做。

### 第 3 步（可选，推荐）：配 Packagist 自动更新

1. 打开 https://packagist.org ，用 GitHub 账号登录
2. 点右上角 **Submit** → 粘贴仓库地址
   `https://github.com/zyzyzzy/aimanong-framework`
3. 提交后 Packagist 会开始索引
4. 在 Packagist 包页面找到 **Webhook** 设置，按提示在 GitHub 仓库
   Settings → Webhooks 里加一条（Packagist 会给出具体的 URL 和 payload 版本）

> 配了 webhook 后，以后每次打 tag，Packagist 自动更新，无需手动点。

---

## 我这边会做的

拿到仓库地址后：

1. `git subtree split --prefix=packages/framework` 拆出可发布分支
2. 推送到新仓库，并**同步历史 tag**（v1.0.0 ~ v1.8.0 共 14 个）
3. 在隔离项目里实测 `composer require aimanong/framework:^1.8` 能装上
4. 把文档里的安装命令改回一条命令可用的形态
5. 在 `pre-release.sh` 里加一条**发布连通性检查**（防止文档再次失实）

---

## 常见问题

### 为什么不直接在主仓库加个根 composer.json？

可以，但那样根目录同时要描述「这是 monorepo」而不是「这是一个包」，
语义混乱，且 Packagist 会把它当成一个（错误的）包来索引。
subtree split 是社区标准做法。

### region 插件怎么办？

同样的流程再来一遍（建 `aimanong-region` 仓库）。
建议先把 framework 发通，再发 region。

### tag 会重复吗？

不会。新仓库是独立仓库，推 tag 是在那个仓库里建，
主仓库的 tag 不受影响。

### 拆分后怎么维护？

主仓库照常开发。**每次发版时**跑一次拆分 + 推送（我会写进 `pre-release.sh`，
一条命令搞定）。

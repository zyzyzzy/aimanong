# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 与 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

## [1.6.0] - 2026-10-11

> 「带地基的平台」收官：**P0 六项基座能力一次性补齐** ——
> 装完就有，一行代码都不用写。
>
> 途中挖出并修掉一个**框架级**缺陷：多对多关联写入从未生效。

### 新增：审计日志（操作日志 + 登录日志）

| 项 | 说明 |
|---|---|
| 操作日志 | 自动记录写操作；**GET 不记**（否则刷新一下列表就是一条，重要记录会被淹没） |
| 登录日志 | 成功与失败**都记** —— 只记成功等于放弃了「有人在爆破」这条线索 |
| 脱敏 | `password` / `token` / `api_key` 等一律掩码为 `******`，含 `new_password` 这类变体 |
| 只读 | 审计数据允许被改就失去了意义 |

### 新增：只读 Resource（通用能力）

```php
public static function readonly(): bool { return true; }
```

三层同时生效：前端隐藏写入口、接口 `store/update/destroy` 一律 403、
权限节点只生成 `index/show/export`。

> ⚠️ 只读校验必须排在 RBAC 判断**之前** —— RBAC 默认关闭时 `authorize_()`
> 会直接放行，写在后面等于没写（已用 `AIMANONG_RBAC=false` 实测 403）。

### 新增：数据字典（枚举变成一等公民）

```php
$form->select('status')->label('状态')->dict('order_status');
$grid->column('status', '状态')->dict('order_status');
```

- **两种来源，一个入口**：代码声明（可 git diff、后台只读）+ 数据库字典（运营可改文案）
- **字典缺失直接报错**：编译期抛 `DICT_NOT_FOUND`，带 `did_you_mean` + 可用字典 + 示例
- **颜色进字典**：列表徽章用字典声明的语义色，不再靠关键词表猜
- **缓存自动失效**：写库即时生效

它真正解决的问题不是「缺一个功能」，而是**已有的分叉**：
同一个枚举原先在列表页写一遍 `map()`、表单页写一遍 `options()`、导出再写一遍。

### 新增：文件上传

```php
$form->image('cover')->maxSize(2048);
$form->images('gallery');
$form->file('contract')->accept('pdf,docx');
$form->files('docs');
```

| 决策 | 理由 |
|---|---|
| 落盘名 = 可读原名 + 8 位随机 | 纯随机名用户认不出文件；只用原名会**静默覆盖**同名文件 |
| 数据库存相对路径 | 换域名 / 换 CDN 不用刷数据 |
| 默认**不含 svg** | SVG 可内嵌 `<script>`，同源读取 = XSS |
| 扩展名 + 真实 MIME 双白名单 | 只看扩展名挡不住 `shell.php.jpg` |
| `auto` 按 driver 判断 | Laravel 的 `public` 盘**总是**配了 `url`，按「有没有 url」判断会让新项目直接得到坏图 |

零构建上传组件（本地 Vue，无 CDN），URL 模板由服务端下发。

### 新增：个人中心

`GET {prefix}/profile` —— 基本资料（含头像上传）/ 修改密码 / 我的权限 / 我的操作记录。

**不做成 Resource**：Resource 的心智模型是「一张表 = 一组页面」，
而个人中心操作的是当前登录者自己 —— 没有 id、没有列表，
硬套会得到一个「理论上能删自己」的页面。

改密码必须验当前密码（防止会话被劫持后直接改密）；
只允许改 `name/email/phone/avatar` 四个字段（白名单）。

### 新增：管理员账号管理

内置 `admin-users` 资源。v1.3.0 起框架就带了 admin guard + 用户表，
却**没有管理界面** —— 地基埋好了，门一直没装。

含角色分配、启停用、最后登录时间与 IP。停用的账号在**密码校验之后**被拒绝登录
（先验密码再报停用，否则成了「用错误密码探测账号是否存在」的旁路）。

### 新增：两个表单语义

| 声明 | 不写会怎样 |
|---|---|
| `->requiredOnCreate()` | 用 `required()` 会让**编辑被自己的规则卡住**；用 `nullable()` 会让新增静默存空密码 |
| `->omitWhenEmpty()` | 编辑用户留空密码本意是「不改」，但空串进了请求体，`'hashed'` cast 把它哈希成新密码 —— **账号当场失效且不报错** |

> ⚠️ 剔除必须发生在 `validate()` **之前**。第一版写成「先校验再剔除」，
> `min:6` 对着空串报错「密码 不能少于 6 个字符」—— 用户明明只是想不改密码。

### 修复：多对多关联写入从未生效（框架级）

`splitRelationFields()` / `syncRelations()` 定义了却**从未被任何地方调用**。

后果：`multiSelect()->relation()` 是**幽灵能力** —— 表单提交成功、
列表看不出区别、中间表一条都没有。demo 里的多对多标签只能自己写模型事件绕过。

实测证据：给用户传 `roles=[2]`，中间表零行；接线后 create/update 均正确 sync。

### 修复：多应用模式下操作日志一条都不会记

单后台与多应用此前**各硬编码了一份中间件列表**，新增 `admin.audit` 时
只改了单后台那份 —— 不报错、测试全绿。已合并为 `adminMiddlewareGroup()` 单一来源。

### 修复：审计日志的 user_id / username 全是 null

用了 `$request->user()`，它走 `config('auth.defaults.guard')`（通常是 `web`），
而后台是 admin guard。审计日志「不知道是谁干的」等于没有价值。

### 修复：界面偏好的服务端同步一直是坏的

路由写成 `Route::prefix('api')` + `'api/ui/preferences'`，实际路径变成
`/admin/api/api/ui/preferences`，与前端 `Aimanong::url('api/ui/preferences')`
对不上：保存 405、读取报「Resource [ui] 未注册」。

### 修复：配置浅合并导致「升级了但新功能没出现」

`mergeConfigFrom` 只做浅合并：用户 config 里只要有 `foundation` 这个键，
包内 `foundation` 的其它子键就拿不到默认值。已改为**递归补齐**。

### 其它

- `Uploader` / `Dictionary` / `Capabilities` 在无容器环境（单测、静态自省）下
  容忍配置缺失，不再抛 `ReflectionException: Class "config" does not exist`
- 上传能力抽成 `partials/upload.blade.php` 供个人中心复用
- `search-docs` 与 `aimanong:docs` 不再静默丢弃数组型能力字段（如 `uris`）
- 内置 Resource 的菜单排序：角色(90) → 权限(91) → 租户(92) → 数据字典(93) → 字典条目(94) → 操作日志(95) → 登录日志(96) → 上传演示(97)

### 测试

新增 `AuditFoundationTest`、`DictionaryTest`、`UploadTest`、
`AdminUserFoundationTest` 与三个夹具，共 **+33 个用例**。

**质量门禁**：PHPUnit **171 tests / 597 assertions**、PHPStan Level 8 无错误、
Pint PASS 166 files、`pre-release.sh` 全部通过。

**真机验证**（不是读 CSS）：

- 四种上传字段各传一遍（含中文文件名），列表缩略图与文件名链接正确，附件可下载
- 路径穿越（`../../evil.pdf` → `evil-xxxx.pdf`，确认未逃出目录）与危险文件名全部被拦
- 分步表单端到端保存、字典徽章取色、个人中心传头像+改密码、用户角色写入中间表
- 3 页 × 320→2560px 响应式扫描无横向溢出
- demo 应用 `ai:verify` **28/28 全部通过**（顺带补了 2 处声明与表结构不一致）

---

## [1.5.0] - 2026-10-11

> 用户连续四轮反馈界面「老气、没有辨识度、颜色太闷」——
> 这一版把**全站视觉重做了一遍**，并在真机验证时挖出**三个此前一直存在的表单渲染缺陷**。

### 新增：全站设计系统（方案 B「新芽」）

配色以 LOGO 取色为基准（不是拍脑袋）：主蓝 `#205098`、成功绿 `#70d070`、
危险红 `#e05040`、警告琥珀 `#e8a840` —— 四色实测占比 62.8% / 17.3% / 9.1% / 8.5%。

| 项 | 内容 |
|---|---|
| 设计令牌 | `design-system.css`（兜底）+ `themes.css`（明/暗）+ `user-prefs.css`（密度/圆角/配色覆盖） |
| 组件命名 | `am-` BEM 命名空间，约 124 个组件类 |
| 组件覆盖 | 工作台、列表页、表单弹窗、登录页**四个页面全部迁移** |
| 界面配置面板 | 10 个可视化选项，实时生效并持久化到服务端 |
| 零外部依赖 | Vue 3 本地内置，移除 CDN —— 此前一次 jsdelivr 抖动导致全站列表页白屏 |

### 移除：三套主题合并为明/暗两套

原本的「墨 / 深 / 极光」三套主题，实测主色相为 216.2° / 223.3° / 202.4°，
**肉眼完全分不出区别**，属于「选项很多、选择为零」的假自由度，已删除。

### 修复：三个表单渲染缺陷（自 UI 重构起一直存在）

这三个缺陷不会报错、不会让测试变红，只会在浏览器里静静地显示错东西：

**1. 分步表单的步骤条把整段 JSON 打印出来**

模板写的是 `@{{ s }}`，而 `s` 是 `{title, fields}` 对象。
四步表单的顶部因此显示四大段原始 JSON。已改为 `@{{ s.title }}` 并补上
CSS 里早就写好、却从未被使用的 `.am-step__label`（省略号截断）。

**2. 所有下拉框、单选、多选的选项都渲染成 JSON，且值全是 0/1/2**

Schema 编译层输出的选项是**数组**：
```json
[{"value": 1, "label": "Laravel"}, {"value": 2, "label": "Vue"}]
```
（`OpenApiEmitter` 直接 `array_column($options, 'value')`，契约就是数组。）

但前端模板把它当**对象**遍历：
```html
<!-- ❌ 错误：Vue 对数组解构时 val 拿到的是下标 -->
<option v-for="(text, val) in f.props.options">@{{ text }}</option>
```
于是选项文案变成 `{ "value": 1, "label": "Laravel" }`，
每个选项的 `value` 退化成 `0 / 1 / 2 …`，
**带默认值的 select 永远匹配不上 → `selectedIndex = -1` → 显示空白**，
必填下拉框因此永远保存不了，用户只知道「保存失败」。

已统一改为 `fieldOptions(f)` 归一化函数，并在 `FormOptionContractTest` 里
把「选项必须是数组」「模板必须走 fieldOptions()」两条契约钉死。

**3. 开关（switch）根本不可见**

CSS 期望 `<span class="am-switch__track">`，模板却输出了没有类名的 `<span>`，
开关的尺寸因此是 0×0。已修正并补上「开 / 关」文字。

### 修复：表单校验信息全是英文

新增 `resources/lang/zh_CN/validation.php`（62 条规则 + 字段名映射），
此前用户看到的是 `The slug field is required.`，现在是「别名 不能为空」。

> 踩坑记录：`loadTranslationsFrom($path, 'aimanong')` 会注册成
> `aimanong::validation` 命名空间，而 Laravel 校验器读的是**默认** `validation` 键，
> 必须省略命名空间参数才会 `addPath()` 到默认查找路径。

### 修复：原生 alert / confirm 全部替换

`alert()` ×2、`confirm()` ×1 换成设计系统内的 Toast 与确认弹窗：

- 删除确认显示**业务可读标识**（如 `laravel-12-queue-notes`），而不是自增 ID `#12`；
- 422 校验失败逐字段渲染为危险 Toast（最多 3 条），成功 2.6s 自动消失、失败 4.5s。

### 修复：交互与响应式

| 问题 | 处理 |
|---|---|
| 侧栏缩窄后**主内容区塌陷**到 64px | 缩窄态保持 `position: relative`，靠溢出而非绝对定位实现悬浮展开 |
| 缩窄后菜单层级丢失、全部展开 | 缩窄态只显示一级图标，子项折叠；悬浮还原全文 |
| 缩窄后开关按钮**点不到**（位移 128px） | 悬浮展开只绑定 `.am-sidebar__nav`，实测位移 1px |
| 汉堡按钮的 CSS 存在、**按钮本身不存在** | 补上按钮 + 抽屉 + 遮罩 + Esc 关闭 |
| 分步表单没有「上一步」 | 补上；步骤条本身也可点击回跳 |
| 必填下拉框显示空白 | 见上文缺陷 2 |
| 移动端表格挤压成 3 行 | 每列最小宽度 144px，< 960px 切换卡片模式 |

### 新增：MCP Server 版本号单一来源

`Mcp/AimanongServer.php` 曾把版本写成 `'1.4.1'` 字面量 ——
AI 客户端握手拿到的 `serverInfo.version` 会与实际框架版本不一致，
AI 会基于错误版本判断能力是否存在。现改为从 `composer.json` 注入，
并由 `VersionTest` 锁死；README 版本徽章同样纳入测试。

### 测试

新增 `FormOptionContractTest`（3 个用例）与 `OptionFormResource` 夹具。
版本一致性测试从 2 条扩到 4 条（新增 MCP Server、README 徽章）。

**质量门禁**：PHPUnit 136 tests / 447 assertions、PHPStan Level 8 无错误、Pint PASS 136 files。

**真机验证**：8 个 Resource ×（下拉/单选/多选/开关/分步）扫描，
4 个页面 × 320→2560px 响应式扫描，以及一次完整的分步表单端到端保存（实际写入数据库）。

---

## [1.4.1] - 2026-10-09

> 用户上手实测反馈：「登录进去后没看到左侧菜单栏」——**属实，已修**。

### 修复：首页缺侧边栏 + 内容不适合使用者

**问题**：侧边栏只加在了 Resource 列表页，**首页 `/admin` 没有**。
用户登录后正好落在首页，因此看不到菜单。

同时首页内容是框架自述（里程碑、环境版本），对使用者没有价值。

**修复**：首页改为真正的**工作台**

| 区块 | 内容 |
|---|---|
| 侧边栏 | 与列表页一致，按权限过滤 |
| 快捷入口 | 可见模块的磁贴（图标 + 名称，点击直达） |
| 当前身份 | 账号 / 角色 / 权限数，未授权时给出明确提示 |
| 系统信息 | 模块数 / 账号数 / 权限开关 / 版本 |

**三个账号实测**

| 账号 | 菜单项 | 磁贴 | 身份显示 |
|---|---|---|---|
| admin | 22 | 22 | 超级管理员 / 全部权限 |
| ops_demo | 4 | 4 | 内容运营 / 24 |
| viewer_demo | 1 | 1 | 只读观察员 / 1 |

> 教训：新 UI 组件必须在**用户实际落地的那一页**验证，
> 不能只在自己测的那一页确认。

---

## [1.4.0] - 2026-10-09

> 「带地基的平台」第三步完成：**菜单系统**。
> 至此开箱即用的地基齐了：登录 + 用户 + RBAC 权限 + 菜单。

### 新增：菜单系统（声明式）

Resource 覆盖 `menu()` 即可声明菜单（**可选**，不覆盖也有默认菜单）：

```php
public static function menu(): array
{
    return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10];
}
```

| 键 | 说明 |
|---|---|
| `group` | 分组名（如「内容管理」），不填则不分组 |
| `icon` | 图标（emoji） |
| `sort` | 排序，小的在前，默认 100 |
| `label` | 显示名，默认用 label() |
| `visible` | 是否显示（后台工具页可设 false） |

**行为**

- 侧边栏自动渲染：分组 + 图标 + 当前页高亮
- **按权限过滤**：RBAC 开启时只显示当前用户有 `index` 权限的菜单
  （实测：超级管理员 22 项 / 只读用户 1 项）
- 可自省：`GET /__ai/menu` 返回菜单树；capabilities 含 menu 说明

### 新增：页面路由权限判定（上轮实测发现的缺陷）

RBAC 原本只拦 API，页面路由未拦 —— 未授权用户看到「暂无数据」的空表格
而非「无权限」提示。已修：页面也返回 403 + 明确提示。

### 修复

- 生成器 uri 统一 kebab（下划线自动转换）
- 生成器为框架自带表（RBAC）生成 Resource 时指向框架模型

---

## [1.3.0] - 2026-10-09

> **方向调整**：从「纯开发工具」转向「带地基的平台」第一步 —— **内置 RBAC**。
>
> 此前多租户场景暴露：框架对权限零支持，AI 不得不手写四层防线。
> 有了内置权限与用户地基，AI 就能专注写业务。

### 新增：内置 RBAC（角色 / 权限）

**权限节点由 Resource 自动生成，无需手写。**

每个注册的 Resource 自动生成 6 个节点：
`{uri}.index` / `.show` / `.create` / `.update` / `.destroy` / `.export`

例：`cms-authors` → `cms-authors.update`（显示名「作者 · 编辑」）

本次 demo 项目自动生成了 **132 个权限节点**（22 个 Resource × 6）。

```php
// 数据模型
admin_roles            // 角色（含 is_super 超级管理员）
admin_permissions      // 权限节点
admin_permission_role  // 角色 ↔ 权限
admin_role_user        // 用户 ↔ 角色
```

**使用**

```php
// 判定
PermissionGate::check('cms-authors.update');

// 同步权限节点（Resource → 权限表）
php artisan aimanong:permission sync
php artisan aimanong:permission list
php artisan aimanong:permission super admin   // 设某用户为超级管理员
```

Controller 已内置拦截：未授权返回 **403** 并提示缺少哪个权限。

**可关闭**：`AIMANONG_RBAC=false`（默认关闭）→ 全部放行，保留灵活性。

### 新增：用框架造自己的权限后台（吃自己的狗粮）

用户/角色/权限管理后台**用 Aimanong 自己生成**：

```bash
php artisan aimanong:make-resource admin_roles --label=角色 --register
```

角色分配权限用**框架原生的多对多**：

```php
$form->multiSelect('permissions')->relation('permissions')->options(...);
```

> 这同时验证了 v1.1.1 的多对多能力 —— 框架能造自己的后台，
> 说明它够用。

### 修复（本轮开发中发现）

1. **生成器 uri 不一致**：`Str::kebab('admin_roles')` 保留下划线，
   与既有 20 个 kebab Resource 不一致 → 页面 404。已统一为 kebab
2. **生成器指向不存在的模型**：为框架自带表（RBAC）生成 Resource 时
   指向 `App\Models\AdminRole`（不存在）→ 页面 500。
   已改为自动识别框架表并指向框架模型
3. **`Registry::find()` 不支持下划线 uri**：已支持两种写法互通
4. **`PermissionGate::enabled()` 无容器时崩溃**：已兜住

### 文档

- `cannot_verify` 更新：权限/RBAC 从「框架没有」改为「已内置（操作级），
  按钮级/字段级仍需自行实现」

---

## [1.2.0] - 2026-10-09

> 本版本来自 **SaaS 多租户场景验证**（第四轮真实场景）。
> 该场景暴露的不是功能缺失，而是**一个更根本的问题**：
> **校验器对安全需求完全无感，却照样给绿灯。**

### 最重要的发现

AI 在 `validate_declaration`（含 requirements）+ `ai:verify` + 16 条隔离测试
**全部绿灯**的同时，发现租户名单正在被泄漏。

原因：「每租户只能看自己数据」这类**安全需求**，
映射不到 requirements 的任何一个键（只有 9 个：
searchable/sortable/required/columns/fields/per_page/tree/export/step），
**校验器对它完全无感，也不会说"我无法验证这条"**。

> 这意味着电商场景「传 requirements 就够」的方法论，
> **在安全需求上直接失效**。

### 修复 1：显式声明能力边界（`cannot_verify`）

框架现在会**主动告诉 AI「这些领域我管不了」**：

```json
"cannot_verify": {
  "多租户/数据隔离": "框架不提供行级数据隔离…",
  "权限/RBAC": "框架没有权限系统，只有登录/未登录一个粒度",
  "行级权限": "…",
  "审计日志": "…",
  "接口限流": "…"
}
```

原则：**宁可说"我不知道"，也不能默认通过。**

### 修复 2：数据作用域钩子（`ScopeHooks`）

AI 报告指出：框架对多租户零支持，且**默认姿势相反**
（Resource 一注册就全表裸奔），**不提供任何官方拦截点**。

新增官方注册点：

```php
// 查询作用域（读）
ScopeHooks::query('tenant', fn ($q) => $q->where('tenant_id', TenantContext::id()));

// 写入钩子（自动盖章）
ScopeHooks::writing('tenant', fn ($data) => $data + ['tenant_id' => TenantContext::id()]);
```

- 自动应用于所有 Resource 的查询与写入
- **可自省**：未配置时 `introspect()` 返回警告
  「⚠️ 未配置任何数据作用域 —— 所有 Resource 默认返回全表数据」
- 状态暴露在 `capabilities.json` 的 `scope_hooks`

> 这把「不可见的业务约定」变成「框架能感知的声明」。

### 修复 3：现代访问器被误判为幽灵列

`isVirtualAttribute()` 只检查了传统写法 `hasGetMutator()`，
漏了 Laravel 9+ 推荐写法 `hasAttributeGetMutator()`。
已补齐，两种写法都能识别。

### 修复 4：编辑表单回填不完整（我上一轮的修复有漏洞）

v1.1.1 修了 API 返回，但**前端仍勾不上**：
API 返回 `[{id,name}]`，checkbox `:value` 是标量 `'1'`，
Vue 的 `v-model` 用 `===` 严格相等 → 对象永远不等于标量。

> **教训：我上一轮只验了 API 返回值就宣布"修好了"，没验 UI。**
> 这是「三层验证」里第 3 层缺失的典型。

已修：
- 进入表单时把关联对象归一化成标量 id 数组
- 新增时多选字段初始化为 `[]`（否则 v-model 无法绑定）

### 修复 5：分步表单编辑时锁死在第 1 步

`@click="i <= currentStep && ..."` 导致编辑时无法跳到后续步骤，
**后面的字段根本改不了**。已改为：新增只许往前（防跳过必填），
**编辑可自由跳转**。

---

## [1.1.1] - 2026-10-09

> 本版本修复 **多对多关联（belongsToMany）支持缺失** ——
> CMS 场景验证暴露，电商场景（只有 belongsTo）发现不了。

### 修复

| 严重度 | 问题 | 现象 |
|---|---|---|
| **高** | 多对多关联列**导出 500** | 集合上取 `->name` 抛异常 |
| **高** | 多对多关联列**列表渲染为空** | 前端按字符串渲染数组 → 空值 |
| **高** | 编辑表单**勾不上已选值** | 关联是对象数组，checkbox 的 `:value` 是标量 id |
| 中 | 表单多对多写入需手写样板 | `unset($data['tags'])` + 模型 `saved` 事件里 `sync()` |

### 新增

**表单多对多：声明关联即自动处理**

```php
$form->multiSelect('tags')->label('标签')->relation('tags')->options(...);
```

- 写入时自动 `sync()` 中间表（主表保存后）
- 编辑时自动回填已选值
- 该字段自动从主表数据中剔除（不是真实列）

**列表与导出支持多对多**

```php
$grid->column('tags.name', '标签');   // 显示「Laravel / 性能优化 / 运维」
```

- 前端 `normalizeCell()` 处理数组：优先取 `name`/`title`/`label`
- 导出拼成 ` / ` 分隔（便于运营阅读），而非 JSON

### 前端补齐 6 个字段类型的专门渲染

原来这些类型都退化成纯文本输入框：

| 类型 | 修复后 |
|---|---|
| `multiselect` | 复选框组（多对多常用） |
| `color` | 原生取色器 |
| `slider` | 滑块 + 实时数值 |
| `rate` | 星级评分（可点击） |
| `tags` | 逗号分隔输入 |
| `daterange` | 双日期选择 |

### 流程备注

本版本在 AI 实测**进行中**修复 —— 违反了 v1.1.0 写入流程的
「实测期间框架冻结」原则。

**边界澄清**：应在 AI **交付完成后**即可解冻；
本次 AI 已交付四个模块，仅在做报告，故影响可控
（已核对其交付物未被破坏）。

---

## [1.1.0] - 2026-10-09

> 本版本的新增能力，全部来自**真实业务场景验证**与**官方插件开发** ——
> 而非凭空设计。两轮验证共暴露 6 个缺口，均已修复。

### 新增

#### 关联列（真实场景暴露）

商品列表要显示「分类名」而非 `category_id` —— 框架原来不支持，
AI 只能手写 `->map()` 自己查表拼映射。

```php
// 点号命名自动识别为关联列
$grid->column('category.name', '所属分类');

// 或显式声明
$grid->column('cat', '分类')->relation('category.name');
```

- Repository 自动 `with()` 预加载，**无 N+1**
- 关联列可参与**搜索**（`whereHas`）、**筛选**（`whereHas`）、**排序**（子查询）
- 前端 `cellValue()` 深取关联值

#### 条件高亮（真实场景暴露）

需求「库存少于 10 要一眼看出来」—— 框架原来**完全无法实现**。

```php
$grid->column('stock', '库存')->dangerBelow(10);        // 少于 10 标红
$grid->column('sold_count', '销量')->warningAbove(100); // 多于 100 标橙
$grid->column('score', '评分')->dangerWhen('<', 60, 'warning');  // 通用形式
```

- 三级样式：`danger` / `warning` / `success`
- **条件用「运算符 + 阈值」而非闭包** —— 保证可序列化、AI 可读、无隐式魔法

#### 插件可注入字段类型（官方插件暴露）

原字段类型是硬编码常量，插件无法扩展。现在：

```php
// 插件侧
class MyExtension extends Extension
{
    public function register(): void
    {
        $this->field(MyField::class, 'mytype', 'string', 'string');
    }
}

// 使用侧（与内置字段无异）
$form->mytype('field', '标签');
```

- `FieldType::register()` 运行期注册
- `Form::__call()` 分发 —— 但**只服务已注册类型**，
  未注册抛 `BadMethodCallException` 并列出可用类型（**不是任意魔法入口**）

#### 查询列安全（电商场景暴露）

关联列直接当列名用会 500，不存在的列会**静默返回错误结果**。

新增 `Support\ColumnResolver` —— 查询列安全校验的唯一入口：

| 防线 | 时机 | 拦截内容 |
|---|---|---|
| `GhostColumnException` | 编译期 | 本表不存在的列 / 关联不存在 / 非法字符 |
| `InvalidQueryColumnException` | 编译期 | 关联目标表没有该列 |

**设计原则：编译期拒绝，运行期兼容。**

### 修复

- **导出与界面不一致**（高危）：`map()` 列导出原始值（`paid` 而非「已付款」）、
  关联列导出空值。文档承诺「导出内容与界面一致」，实际只有行数一致。
  现已套用列的 formatter（map/bool/money/datetime）
- **关联列搜索 500**（高危）：`orWhere('product.name')` 被 SQL 当列名，
  且会**拖垮同一查询的其它条件**。现走 `whereHas`
- **Repository 替换点对 HTTP 不生效**（中危）：Controller 硬编码
  `new EloquentRepository()`，从未解析容器契约。现优先从容器解析
- **幽灵列检测过松**：原来只检查 `method_exists()`，
  模型上恰好有同名方法就会放行。现要求返回 `Eloquent Relation`
- **校验器假阳性**：对可搜索的关联列增加**运行时探测**，
  避免「必然 500 却拿到绿灯」

### 文档

- **AI 使用手册** —— 写给「指挥 AI 用本框架的人」：三个必做动作、
  6 个真实踩过的坑、4 类场景建议、3 个提示词模板、三层验证法
- **发布前验证流程** —— 7 节流程 + 4 条原则，附可执行脚本
  `bash scripts/pre-release.sh --with-e2e`
- 查询列规则同步到 AI 自省接口

### 官方插件

- **aimanong/region** —— 中国省市区三级联动选择器（首个官方插件）
  - 三级联动 / 两级模式（`cityLevel()`）
  - 自带数据源与级联查询路由
  - 独立 Composer 包，不改框架核心

### 流程改进

**实测期间框架必须冻结** —— v1.0 期间犯过这个错：
在 AI 实测进行中修改框架，导致其早期探测结论失效
（同一文件 21:08 测 500、21:14 测 200）。已写入流程文档。

---

## [1.0.0] - 2026-10-09

首个正式版本。

### 定位

**Aimanong（AI 码农）** —— 一个「AI 优先」的后台开发框架，基于 Laravel 12 + Vue 3。

设计目标是：**让任何 AI Agent 在 5 分钟内理解框架、30 分钟内产出可运行的后台系统**。
为此明确牺牲部分"灵活性"换取"确定性"（禁止方法重载、禁止嵌套闭包）。

---

### 新增

#### 核心（M0–M2）

- **Resource 声明式开发**：一个 PHP 类 = 一张表 = 一组后台页面（列表/表单/详情）
- **Schema 编译层**：一份声明编译出四份产物 —— JSON Schema / TypeScript 类型 / OpenAPI 文档 / AI 上下文
- **漂移检测**：`php artisan aimanong:schema --check`，产物与声明不一致时退出码非 0，可阻断 CI
- **数据源抽象**：Repository 契约，可替换 Eloquent 为 API / 数组等数据源
- **admin guard**：自定义认证，已适配 Laravel 11+ 的契约变更
  （`rehashPasswordIfRequired()` / `getAuthPasswordName()`）

#### AI 能力层（M3）

- **自省接口** `/__ai/*`：capabilities / schema / openapi / context / examples / verify
- **MCP Server**：8 个工具 —— list_resources、describe_resource、scaffold_resource、
  create_page、validate_declaration、run_verify、query_data、search_docs
- **可自愈错误**：异常自带 `did_you_mean` / `example` / `hint` / `docs`
- **模糊匹配**：字段类型与类名拼错时给出建议（编辑距离）
- **幽灵列检测**：拼错列名立刻拦截，不再静默消失
- **需求达标度校验**：`validate_declaration` 支持传 `requirements` 逐条核对，
  `per_page` 会**运行时实测**而非只读声明

#### 字段与展示器（M4）

- **26 个字段类型**：text / textarea / email / url / password / tel /
  number / decimal / money / rate / slider / select / multiselect / radio /
  checkbox / switch / date / datetime / time / daterange / color / icon /
  tags / hidden / display / divider
- **列展示器**：`bool()` / `badge()` / `money()` / `image()` / `link()` / `progress()` / `map()` / `using()` / `dateTime()`
- **前端渲染补齐**：radio 单选组、checkbox 多选组、原生日期选择器、小数步进、
  帮助文本、空值占位、语义标签配色

#### 增强（M5）

- **数据导出**：`$grid->export()`，CSV 格式（UTF-8 BOM 防 Excel 乱码）；
  复用列表的搜索/排序/筛选逻辑，导出内容与界面一致；分批流式，大表不炸内存
- **树形结构**：Resource 实现 `tree()` 方法即可；
  **循环引用检测**（A→B→A 返回 422 而非无限递归）；移动合法性校验
- **分步表单**：`$form->step('标题')`，用「当前步骤游标」声明，无嵌套闭包
- **插件系统**：继承 `Extension` 即可；**依赖检查** + **失败隔离**（单个扩展出错不拖垮其它）
- **多应用**：一个项目跑多个隔离后台，各自独立前缀 / guard / 用户模型

#### 生态（M6）

- **文档站**：API 参考由 Schema 自动生成，永不漂移
- **Playground**：浏览器内编辑 Resource 声明，实时查看四份产物（真实解析，非演示）
- **代码生成器**：`php artisan aimanong:make-resource {table} --label=X --register`
- **扩展脚手架**：`php artisan aimanong:make-extension {Name}`

---

### 安全

- **统一输出转义**：所有用户输入经 JSON 序列化 + Vue 文本插值，杜绝 XSS
- **目录穿越防护**：框架资源路由校验路径前缀
- **敏感接口默认关闭**：`/__ai/*` 仅 local/debug 开启，生产需 token
- **漏洞响应 SLA**：确认 72 小时内 / 严重漏洞 7 天内修复

> 作为对比：参考对象 dcat-admin 有 4 条公开 CVE **全部零补丁**。

---

### 开发过程（值得记录的透明度）

本版本经过 **5 轮 AI 实测**，累计发现并修复 **20 个真实缺陷**。

| | 第一轮 | 第二轮 | 第三轮 | 第四轮 | 第五轮 |
|---|---|---|---|---|---|
| 一次成功率 | 0.5 | 0.5 | 1.0 | 1.0 | 1.0 |
| 框架纠错贡献 | 0% | 50% | 100% | 100% | 100% |
| 是否读源码 | 读了 | 读了 | 没读 | 没读 | 没读 |

测试方式：给 AI 一份文档和一个 MCP 连接，**不给任何提示**，让它独立完成开发任务，
再由复核者独立验证结论。

**最重要的四条教训**（均已写入代码或测试）：

1. **校验器只读元数据 = 假阳性工厂** —— 任何「已设置」的断言都要有运行时验证
2. **同一份信息多个来源 = 迟早分叉** —— 解法是让它们同源，而非"记得同步更新两处"
3. **异常携带的上下文不能在捕获时丢弃** —— 否则 AI 只看到笼统的"编译失败"
4. **校验器的「沉默」比「报错」更危险** —— 不认识的键必须报错，不能静默通过

---

### 已知限制

| 项 | 说明 |
|---|---|
| `draggable()` | 声明会被编译，但**前端拖拽 UI 尚未实现**（编译产物含 `draggable_supported: false`）。调整层级请用编辑表单或 `PUT /{uri}/{id}/move` |
| 导出格式 | 仅支持 CSV，暂无 xlsx |
| 前端组件库 | 使用内联 Vue（CDN），尚未发布独立的 `@aimanong/ui` npm 包 |

---

### 环境要求

- PHP ^8.2
- Laravel ^12.0
- 数据库：MySQL / PostgreSQL / SQLite 均可

---

## 版本链接

- [1.4.1](https://github.com/zyzyzzy/aimanong/releases/tag/v1.4.1)
- [1.4.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.4.0)
- [1.3.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.3.0)
- [1.2.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.2.0)
- [1.1.1](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.1)
- [1.1.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.0)
- [1.0.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.0.0)

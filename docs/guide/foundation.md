# 基座能力

> v1.6.0 起，Aimanong 不只是「画页面的工具」，而是**带地基的平台**。
>
> 登录日志、操作日志、数据字典、文件上传、个人中心、管理员账号 ——
> **装完就有，一行代码都不用写**，每一项都能用配置整体关掉。

---

## 为什么框架要自带这些

传统后台框架（dcat-admin、Filament 等）给你的是「工具」：
你能很快画出 CRUD 页面，但登录、用户、角色、日志、字典都得自己写。
每个项目重复实现一遍，每一遍的细节都不一样。

Aimanong 的选择：**这些属于地基，不该由每个项目重复造**。

判断标准只有两条：

1. **能否声明式表达？**（不要黑盒配置）
2. **AI 能否自省？**（AI 怎么知道它存在、怎么用对）

一个功能如果只能靠人读文档学会，就不适合这个框架。

---

## 一览

| 能力 | 入口 | 关掉它 |
|---|---|---|
| 操作日志 | `admin-operation-logs` | `foundation.operation_log.enable` |
| 登录日志 | `admin-login-logs` | `foundation.login_log.enable` |
| 数据字典 | `admin-dict-types` / `admin-dict-items` | `foundation.dict.enable` |
| 文件上传 | `POST {prefix}/api/upload` | `foundation.upload.enable` |
| 个人中心 | `GET {prefix}/profile` | `foundation.profile.enable` |
| 管理员账号 | `admin-users` | `foundation.admin_user.enable` |

关掉 = 整体消失：菜单没有、权限节点不生成、路由 404，**不会留下半开状态**。

---

## 审计日志

### 操作日志

自动记录写操作（`POST` / `PUT` / `PATCH` / `DELETE`），**GET 不记** ——
否则刷新一下列表就是一条日志，真正重要的「谁改了这条数据」会被淹没。

记录内容：账号、动作、资源、记录 ID、状态码、IP、User-Agent、
请求体（**密码类字段自动掩码**）、耗时。

```php
// config/aimanong.php
'foundation' => [
    'operation_log' => [
        'enable' => true,
        // 不想记的路径（不含路由前缀）
        'except' => ['api/ui/preferences'],
    ],
],
```

> **给 AI 的提示**：数据对不上时，先查 `admin-operation-logs`。
> 模型文件、代码、Schema 都只说明「应该怎样」，
> 只有操作日志能回答「谁、在什么时候、把哪个字段改成了什么」。

### 登录日志

成功与失败**都记**。只记成功等于放弃了「有人在爆破」这条线索。

### 只读资源

审计数据允许被改就失去了意义。任何 Resource 都能声明为只读：

```php
public static function readonly(): bool
{
    return true;
}
```

三层同时生效：

| 层 | 效果 |
|---|---|
| 前端 | 隐藏「新增 / 编辑 / 删除」按钮与「操作」列 |
| 接口 | `store` / `update` / `destroy` 一律 403 |
| 权限 | 只生成 `index` / `show` / `export`，不产生点了必然 403 的假权限 |

::: warning 只读校验与 RBAC 开关无关
它排在 RBAC 判断**之前**。否则 RBAC 默认关闭时 `authorize_()` 直接放行，
只读就形同虚设。
:::

---

## 数据字典

### 它解决的不是「缺功能」，而是**已有的分叉**

没有字典时，同一套枚举会在每个 Resource 里各写一遍：
列表页一套 `map()`、表单页一套 `options()`、导出又一套。
改一个文案要改 N 处。

### 用法

```php
// 表单
$form->select('status')->label('状态')->dict('order_status');

// 列表（自动取「值 → 文案」映射 + 徽章语义色）
$grid->column('status', '状态')->dict('order_status');
```

### 两种来源，一个入口

| 来源 | 位置 | 特点 |
|---|---|---|
| 代码声明 | `config/aimanong.php` 的 `foundation.dict.declarations` | 随代码走、可 git diff、AI 能读；后台**只读** |
| 数据库 | 后台「数据字典 / 字典条目」 | 运营可随时改文案 |

读取只走 `Dictionary` 一个入口 —— 因此两种来源并存**不构成分叉**。
构成分叉的是「有些地方读代码、有些地方读库」。

```php
'foundation' => [
    'dict' => [
        'declarations' => [
            'order_status' => [
                'name' => '订单状态',
                'items' => [
                    ['value' => 'pending', 'label' => '待付款', 'color' => 'warning'],
                    ['value' => 'paid',    'label' => '已付款', 'color' => 'success'],
                ],
            ],
        ],
    ],
],
```

### 字典缺失会**直接报错**

```php
$form->select('status')->dict('order_stauts');   // 拼错一个字母
```

编译期抛 `DICT_NOT_FOUND`，并给出：

- `did_you_mean`：最接近的字典 code
- `available_dicts`：项目里所有可用的字典
- `example`：可以直接抄的正确写法

::: danger 为什么不给一个空下拉框
拼错 code 的后果是「一个永远选不了值的必填框」——
页面照常渲染、保存照常成功、测试照常全绿，
用户只会觉得「这个框点不动」，AI 会以为任务完成。
:::

---

## 文件上传

```php
$form->image('cover')->maxSize(2048);          // 单图
$form->images('gallery');                       // 多图（存 JSON 数组）
$form->file('contract')->accept('pdf,docx');    // 单文件
$form->files('docs')->accept('pdf,zip');        // 多文件
```

### 三个关键决策

**1. 落盘名 = 可读原名 + 8 位随机后缀**

纯随机名用户认不出文件（传了《2026年采购合同.pdf》，后台显示 `T7FJgPtROo9L4NXF1Fkf2y5x.pdf`）；
只用原名又会**静默覆盖**同名文件。客户端文件名不参与路径生成。

**2. 数据库存相对路径，不存完整 URL**

```
uploads/2026/10/2026年采购合同-8dpa23n2.pdf
```

换域名、换 CDN 都不用刷数据。用户手工填的外链会被识别并原样透出。

**3. 默认不含 SVG**

SVG 可以内嵌 `<script>`，而框架的文件读取路由是**同源**的 ——
打开一个恶意 SVG 就等于同源 XSS。

### 文件怎么被读出来

```php
'serve' => 'auto',   // auto | route | url
```

| 值 | 行为 |
|---|---|
| `auto`（默认） | 本地磁盘走框架读取路由（免 `storage:link`，需登录）；有公开 URL 的云盘走 `Storage::url()` |
| `route` | 始终走框架路由（需要登录才能读，适合私有附件） |
| `url` | 始终走 `Storage::url()`（CDN / S3 场景，本地盘需先 `php artisan storage:link`） |

::: tip 为什么 auto 按 driver 判断，而不是「有没有配 url」
Laravel 骨架的默认 `public` 盘**总是**配了 `url`（取自 `APP_URL`）。
按「有没有 url」判断会让每个新项目直接得到 `http://localhost/storage/...` 的坏图。
:::

---

## 个人中心

`GET {prefix}/profile` —— 顶栏的用户名就是入口。

四个标签页：

| 标签 | 内容 |
|---|---|
| 基本资料 | 头像（上传）、姓名、邮箱、手机号；账号只读 |
| 修改密码 | 必须验证当前密码 |
| 我的权限 | 我的角色 + 我实际持有的权限节点（超管单独提示） |
| 我的操作记录 | 读操作日志，最近 15 条 |

**它不是 Resource。** Resource 的心智模型是「一张表 = 一组页面」，
而个人中心操作的是当前登录者自己 —— 没有 id 参数、没有列表、不该有删除。
硬套会得到一个「理论上能删自己」的页面。

安全约束：

- 只允许改 `name` / `email` / `phone` / `avatar` 四个字段（**白名单**，
  将来模型新增敏感字段不会自动变成可改）
- 改密码必须验当前密码（防止会话被劫持后直接改密）

---

## 管理员账号

内置 `admin-users` 资源：账号、姓名、邮箱、手机、密码、头像、角色、启用，
以及最后登录时间与 IP。

### 密码字段的三件套

```php
$form->password('password')
    ->requiredOnCreate()   // 仅新增必填
    ->omitWhenEmpty()      // 编辑留空 = 不提交这个键 = 不改密码
    ->min(6);
```

三个声明**缺一不可**：

| 少了谁 | 后果 |
|---|---|
| `required()` 代替 `requiredOnCreate()` | 编辑时被自己的规则卡住，改不了名字 |
| `nullable()` 代替 `requiredOnCreate()` | 新增时静默存进空密码，账号进不去 |
| 少了 `omitWhenEmpty()` | `'hashed'` cast 把空串哈希成新密码，**账号当场失效且不报错** |

::: warning 剔除必须发生在 validate() 之前
先校验再剔除的话，`min:6` 会对着空串报错「密码 不能少于 6 个字符」——
而用户的意思明明是「不改密码」。
:::

### 停用的账号

停用后无法登录。校验顺序是**先验密码、再报停用** ——
反过来就成了「用错误密码探测账号是否存在」的旁路。

---

## 这些能力对 AI 意味着什么

`GET /__ai/capabilities.json` 会明确告诉 AI：

```jsonc
{
  "foundation": {
    "operation_log": { "enabled": true, "uri": "admin-operation-logs", ... },
    "dict":          { "enabled": true, "declare": "$form->select('status')->dict('order_status');" },
    "upload":        { "enabled": true, "field_types": ["image", "images"] }
  },
  // 项目里**实际存在**的字典与可选值
  "dictionaries": {
    "order_status": {
      "source": "code",
      "items": { "pending": "待付款", "paid": "已付款" }
    }
  }
}
```

于是 AI 能做到三件它原本做不到的事：

1. **数据对不上时先查操作日志** —— 而不是瞎猜或重写代码
2. **枚举不必猜** —— 项目里有哪些状态值，自省一次就拿到
3. **不重复造轮子** —— 知道日志/字典/上传已经存在

能力面由三个地方共同提供，`SingleSourceOfTruthTest` 锁死一致性：

| 出口 | 给谁 |
|---|---|
| `GET /__ai/capabilities.json` | AI 自省 |
| MCP `search-docs` | AI 客户端（Claude / Cursor 等） |
| `php artisan aimanong:docs` | 人 + 生成的 API 参考 |

新增能力漏接任何一处，测试会直接失败。

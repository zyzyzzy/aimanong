<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# 列展示器与选项

> 由 `php artisan aimanong:docs` 自动生成。

## 全部选项

| 方法 | 说明 |
|---|---|
| `sortable()` | 允许排序 |
| `searchable()` | 加入快捷搜索 |
| `filter()` | 加入筛选器 |
| `dateTime()` | 日期时间格式化，参数: 格式字符串（默认 Y-m-d H:i:s） |
| `bool()` | 布尔→是否标签，参数: (trueLabel, falseLabel)，默认「是/否」 |
| `badge()` | 徽章样式（适合状态类字段） |
| `money()` | 金额千分位，参数: 货币符号（默认 ¥） |
| `image()` | 图片缩略图，参数: 高度像素（默认 32） |
| `link()` | 超链接，参数: 显示文本 |
| `progress()` | 进度条（适合百分比/完成度） |
| `using()` | 枚举值→标签映射，参数: 枚举类名 |
| `map()` | 值映射，参数: 关联数组 |
| `relation()` | 显式声明关联列，参数: 关联路径（如 category.name）。支持 belongsTo / hasOne（单值）与 belongsToMany（多值，显示为「A / B / C」，导出同样处理） |
| `dangerWhen()` | 条件高亮，参数: (运算符, 阈值, 级别)。运算符支持 < <= > >= == != |
| `dangerBelow()` | 小于阈值时高亮（如库存不足），参数: (阈值, 级别) |
| `warningAbove()` | 大于阈值时高亮，参数: (阈值, 级别) |
| `width()` | 列宽，参数: 像素 |
| `label()` | 列标题，参数: 字符串 |
| `perPage()` | 每页条数，参数: int。写法: $grid->perPage(15); 不传则用框架默认 20 |
| `actions()` | 是否显示行操作按钮，参数: bool |
| `batchActions()` | 批量操作，参数: 动作名数组。支持 delete（批量删除，复用 destroy 权限）与 export（导出选中项，需先 export()）。写法: $grid->batchActions(['delete', 'export']); 未声明的动作名会在编译期报错 |
| `export()` | 开启 CSV 导出，写法: $grid->export(); 未开启时导出接口返回 403 |
| `exportExcept()` | 导出时排除的列，参数: 数组。如 exportExcept(['cover_url']) |
| `exportChunkSize()` | 导出分批查询条数，参数: int（默认 1000，大表用） |

## 导出

在 `grid()` 中调用 `$grid->export();` 开启 CSV 导出。
导出复用列表的搜索/排序/筛选参数 —— 导出的内容与界面看到的一致。

```php
$grid->export();                        // 开启导出
$grid->exportExcept(['cover_url']);     // 排除某些列
$grid->exportChunkSize(2000);          // 分批查询（大表）
```

> 未开启导出的 Resource 请求导出接口会返回 403，并提示如何开启。

## 树形结构

在 Resource 中实现 `tree()` 方法即可获得树形页面：

```php
public static function tree(Tree \$tree): void
{
    $tree->parentColumn('parent_id')
        ->titleColumn('name')
        ->orderColumn('sort')
        ->draggable();
}
```

> **循环引用会被自动检测** —— 数据存在 A→B→A 时返回 422 而非无限递归。
> 移动节点时会校验目标位置，不允许把节点移到自己的子孙下。

## 分步表单

用 `$form->step('步骤标题')` 声明步骤，**后续字段自动归属该步骤**：

```php
public static function form(Form \$form): void
{
    $form->step('基本信息');
    $form->text('name')->required();
    $form->text('email')->required();

    $form->step('联系方式');
    $form->text('phone');
}
```

> 字段归属由**声明顺序**决定 —— 扁平、无嵌套闭包（框架铁律）。
> 提交时所有步骤一起校验，规则与单页表单一致。

## 只读资源

审计日志、监控、报表快照这类数据「只应该由系统写入」，
在后台能随手改就失去了意义。覆盖 `readonly()` 即可：

```php
public static function readonly(): bool
{
    return true;
}
```

| 层 | 效果 |
|---|---|
| 前端 | 隐藏「新增 / 编辑 / 删除」按钮与「操作」列 |
| 接口 | `store` / `update` / `destroy` 一律 403（**与 RBAC 开关无关**） |
| 权限 | 只生成 `index` / `show` / `export`，不产生点了必然 403 的假权限 |

## 数据字典

把枚举变成一等公民：**声明一次，列表 / 表单 / 徽章配色共用**。

```php
$form->select('status')->label('状态')->dict('order_status');
$grid->column('status', '状态')->dict('order_status');
```

字典不存在或没有任何启用条目时**编译期直接报错**（DICT_NOT_FOUND + 模糊匹配建议），
而不是给你一个永远选不了值的空下拉框。

两种来源，读取只走 `Dictionary` 一个入口：

| 来源 | 位置 | 特点 |
|---|---|---|
| 代码声明 | `config/aimanong.php` 的 `foundation.dict.declarations` | 随代码走、可 diff、后台只读 |
| 数据库 | 后台「数据字典 / 字典条目」 | 运营可随时改文案 |

### 当前项目的字典

| code | 名称 | 来源 | 可选值 |
|---|---|---|---|
| `article_status` | 文章状态 | 代码声明 | `draft`=草稿 / `pending`=待审 / `published`=已发布 / `archived`=已归档 |
| `crm_level` | 客户等级 | 代码声明 | `A`=A 级（重点） / `B`=B 级（常规） / `C`=C 级（观察） |
| `crm_industry` | 所属行业 | 代码声明 | `manufacturing`=制造业 / `internet`=互联网 / `finance`=金融 / `education`=教育 / `medical`=医疗 / `retail`=零售 / `other`=其他 |
| `crm_customer_status` | 客户状态 | 代码声明 | `potential`=潜在 / `following`=跟进中 / `signed`=已签约 / `lost`=已流失 |
| `crm_source` | 客户来源 | 代码声明 | `web`=官网 / `referral`=转介绍 / `exhibition`=展会 / `cold`=陌生拜访 / `ad`=广告投放 |
| `crm_stage` | 商机阶段 | 代码声明 | `lead`=线索 / `qualified`=已确认 / `proposal`=方案报价 / `negotiation`=商务谈判 / `won`=赢单 / `lost`=输单 |
| `crm_followup_type` | 跟进方式 | 代码声明 | `call`=电话 / `visit`=拜访 / `meeting`=会议 / `email`=邮件 / `wechat`=微信 |
| `crm_contract_status` | 合同状态 | 代码声明 | `draft`=草稿 / `reviewing`=审核中 / `signed`=已签署 / `executing`=执行中 / `finished`=已完成 / `terminated`=已终止 |
| `priority` | 优先级 | 代码声明 | `low`=低 / `normal`=普通 / `high`=高 / `urgent`=紧急 |
| `pay_channel` | 支付渠道 | 数据库 | `wechat`=微信支付 / `alipay`=支付宝 |
| `equipment_repair_status` | 报修状态 | 数据库 | `pending`=待处理 / `processing`=处理中 / `done`=已完成 |
| `equipment_repair_urgency` | 报修紧急程度 | 数据库 | `normal`=一般 / `urgent`=紧急 / `critical`=非常紧急 |

## 基座功能（开箱即用）

以下能力由框架内置，**用户一行代码都不用写**，且可用配置整体关掉。

| 能力 | 状态 | 说明 |
|---|---|---|
| `operation_log` | 已启用 | 操作日志：谁在什么时候改了哪条数据 |
| `profile` | 已启用 | 个人中心：改资料 / 改密码 / 看我的角色权限与操作记录 |
| `admin_user` | 已启用 | 管理员账号管理界面（内置 Resource：admin-users） |
| `upload` | 已启用 | 文件上传：单图/多图/单文件/多文件四种字段，落盘与 URL 解析统一走 Uploader |
| `dict` | 已启用 | 数据字典：把枚举变成一等公民，AI 能自省有哪些可选值 |
| `login_log` | 已启用 | 登录日志：成功与失败都记 |
| `login_throttle` | 已启用 | 登录限流：每 IP 与「每 IP×每账号」双维度计数 |

### operation_log

- **uri**：admin-operation-logs
- **auto**：框架自动记录写操作（POST/PUT/PATCH/DELETE），无需声明
- **excludes**：GET/HEAD/OPTIONS 不记；密码类字段自动掩码为 ******
- **config**：config('aimanong.foundation.operation_log')
- **use_when**：数据对不上时，**先查这张表** —— 它是唯一的客观依据

### profile

- **security**：只允许改 name/email/phone/avatar 四个字段（白名单）；改密码必须验当前密码，防止会话被劫持后直接改密
- **route**：GET {prefix}/profile（顶栏用户名即入口）
- **api**：PUT api/profile、PUT api/profile/password
- **note**：它不是 Resource —— Resource 的心智模型是「一张表 = 一组页面」，而个人中心操作的是当前登录者自己，没有 id 也没有列表

### admin_user

- **uri**：admin-users
- **auto**：框架内置，装完就有；关掉只影响界面，不影响认证
- **password**：密码框三件套：requiredOnCreate() + omitWhenEmpty() + min(6)。编辑留空 = 不改密码；少了 omitWhenEmpty 会被空串哈希成新密码，账号当场失效
- **fields**：账号/姓名/邮箱/手机/密码/头像/角色/启用；停用的账号在密码校验之后被拒绝登录

### upload

- **use_when**：需要图片/附件字段时 —— 不要自己写上传控制器
- **declare**：$form->image('cover'); $form->images('gallery'); $form->file('contract')->accept('pdf'); $form->files('docs');
- **store_form**：数据库里存**相对路径**（uploads/2026/10/合同-ab12cd34.pdf），不是完整 URL —— 换域名/换 CDN 不用刷数据；多图/多文件存 JSON 数组
- **naming**：落盘名 = 可读原名 + 8 位随机后缀。纯随机名用户认不出文件；只用原名会静默覆盖同名文件。文件名全部服务端生成，客户端名不参与路径
- **serve**：config('aimanong.foundation.upload.serve')：auto（本地磁盘走框架路由，免 storage:link）/ route（需登录）/ url（CDN）
- **security**：扩展名 + 真实 MIME 双白名单；默认不含 svg（SVG 可内嵌 script，同源读取等于 XSS）
- **field_types**：image、images

### dict

- **uris**：admin-dict-types、admin-dict-items
- **use_when**：同一个枚举要在列表、表单、导出多处出现时 —— 不要在各处重复写 options()/map()
- **declare**：$form->select('status')->dict('order_status');  $grid->column('status', '状态')->dict('order_status');

### login_log

- **uri**：admin-login-logs
- **auto**：框架在登录控制器内自动记录，无需声明
- **config**：config('aimanong.foundation.login_log')
- **use_when**：排查「登不上」或「有人在爆破」

### login_throttle

- **auto**：框架在登录控制器内自动生效，无需声明
- **config**：config('aimanong.auth.throttle')
- **use_when**：担心后台被爆破密码；或用户反馈「登录总提示尝试过于频繁」

## 扩展（插件）

一个扩展 = 一个继承 `Aimanong\Extend\Extension` 的类：

```bash
php artisan aimanong:make-extension Seo   # 生成骨架
php artisan aimanong:extensions           # 查看状态
```

| 方法 | 用途 |
|---|---|
| `name()` | 唯一标识（必需） |
| `dependencies()` | 依赖的其他扩展，缺失会被跳过而非崩溃 |
| `register()` | 注册阶段：登记 Resource（勿访问数据库） |
| `boot()` | 启动阶段：注册路由、视图 |

> **失败隔离**：单个扩展出错会被记录并跳过，不影响其它扩展与框架本身。

## 多应用（多后台）

在 `config/aimanong.php` 声明多个后台，各自有独立的前缀、guard 与用户模型：

```php
'applications' => [
    'admin' => [
        'title' => '运营后台',
        'route' => ['prefix' => 'admin'],
        'auth' => ['guard' => 'admin', 'model' => Administrator::class],
    ],
    'merchant' => [
        'title' => '商家后台',
        'route' => ['prefix' => 'merchant'],
        'auth' => ['guard' => 'merchant', 'model' => Merchant::class],
    ],
],
```

> 当前应用：`Aimanong::application()->current()`（用 Laravel 12 Context 做请求级隔离，并发安全）

## 示例

```php
public static function grid(Grid $grid): void
{
    $grid->column('id', 'ID')->sortable();
    $grid->column('name', '名称')->searchable();
    $grid->column('status', '状态')->badge();
    $grid->column('enabled', '是否启用')->bool('启用', '停用');
    $grid->column('amount', '金额')->money();
    $grid->column('progress', '进度')->progress();
    $grid->column('avatar', '头像')->image();
    $grid->column('homepage', '主页')->link('访问');
    $grid->column('created_at', '创建时间')->dateTime()->sortable();
}
```

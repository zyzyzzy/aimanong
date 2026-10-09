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
| `width()` | 列宽，参数: 像素 |
| `label()` | 列标题，参数: 字符串 |
| `perPage()` | 每页条数，参数: int。写法: $grid->perPage(15); 不传则用框架默认 20 |
| `actions()` | 是否显示行操作按钮，参数: bool |
| `batchActions()` | 批量操作按钮，参数: 数组 |
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

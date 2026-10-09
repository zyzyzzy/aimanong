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

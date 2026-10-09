<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# 字段类型

> 由 `php artisan aimanong:docs` 自动生成。

## 通用链式方法

所有字段类型都支持：

| 方法 | 说明 |
|---|---|
| `label(string)` | 字段标签（中文名） |
| `required(bool = true)` | 必填，自动生成 `required` 校验规则 |
| `default(mixed)` | 默认值 |
| `readonly(bool = true)` | 只读 |
| `hidden(bool = true)` | 隐藏 |
| `rules(string\|array)` | 追加 Laravel 验证规则 |
| `max(int)` / `min(int)` | 长度或数值限制 |
| `placeholder(string)` | 占位文本 |
| `help(string)` | 字段下方帮助文本 |
| `dateTime(string)` | 展示为日期时间 |
| `map(array)` | 值 → 标签映射 |
| `using(class-string)` | 枚举值 → 标签映射 |

## 全部类型

### `text`

- JSON 类型：`string`

```php
$form->text('name')->label('名称')->required()->max(255);
```

### `textarea`

- JSON 类型：`string`
- 专属方法：`rows(int)` 行数

```php
$form->textarea('body')->label('内容')->rows(4);
```

### `email`

- JSON 类型：`string`

```php
$form->email('email')->label('邮箱')->rules('email');
```

### `url`

- JSON 类型：`string`

```php
$form->url('homepage')->label('主页');
```

### `password`

- JSON 类型：`string`

```php
$form->password('pwd')->label('密码');
```

### `tel`

- JSON 类型：`string`

```php
$form->tel('phone')->label('电话');
```

### `number`

- JSON 类型：`integer`
- 专属方法：`step(int|float)` 步进、`range(min, max)` 范围

```php
$form->number('sort')->label('排序')->default(0);
```

### `decimal`

- JSON 类型：`number`
- 专属方法：`decimals(int)` 小数位

```php
$form->decimal('price')->label('价格')->decimals(2);
```

### `money`

- JSON 类型：`number`
- 专属方法：`decimals(int)` 小数位、`symbol(string)` 货币符号

```php
$form->money('amount')->label('金额')->symbol('¥');
```

### `rate`

- JSON 类型：`integer`
- 专属方法：`max(int)` 星数、`allowHalf(bool)` 半星

```php
$form->rate('score')->label('评分')->max(5);
```

### `slider`

- JSON 类型：`integer`
- 专属方法：`range(min, max)`、`step(int)`

```php
$form->slider('progress')->label('进度')->range(0, 100);
```

### `select`

- JSON 类型：`string`
- 专属方法：`options(array)` 选项

```php
$form->select('status')->label('状态')->options(['a' => '甲']);
```

### `multiselect`

- JSON 类型：`array`
- 专属方法：`options(array)` 选项

```php
$form->multiSelect('tags')->label('标签')->options(['a' => '甲']);
```

### `radio`

- JSON 类型：`string`
- 专属方法：`options(array)` 选项

```php
$form->radio('type')->label('类型')->options(['a' => '甲']);
```

### `checkbox`

- JSON 类型：`array`
- 专属方法：`options(array)` 选项

```php
$form->checkbox('favs')->label('偏好')->options(['a' => '甲']);
```

### `switch`

- JSON 类型：`boolean`

```php
$form->switch('enabled')->label('是否启用');
```

### `date`

- JSON 类型：`string`
- 专属方法：`format(string)` 格式

```php
$form->date('published_at')->label('发布日期');
```

### `datetime`

- JSON 类型：`string`
- 专属方法：`format(string)` 格式

```php
$form->datetime('started_at')->label('开始时间');
```

### `time`

- JSON 类型：`string`
- 专属方法：`format(string)` 格式

```php
$form->time('open_at')->label('营业时间');
```

### `daterange`

- JSON 类型：`array`

```php
$form->dateRange('period')->label('周期');
```

### `color`

- JSON 类型：`string`

```php
$form->color('theme')->label('主题色');
```

### `icon`

- JSON 类型：`string`

```php
$form->icon('ico')->label('图标');
```

### `tags`

- JSON 类型：`array`
- 专属方法：`separator(string)` 分隔符

```php
$form->tags('labels')->label('标记');
```

### `hidden`

- JSON 类型：`string`

```php
$form->hidden('token');
```

### `display`

- JSON 类型：`string`

```php
$form->display('info')->label('说明');
```

### `divider`

- JSON 类型：`null`

```php
$form->divider('分组标题');
```

### `region`

- JSON 类型：`string`

```php
$form->region('field')->label('标签');
```

# Aimanong · 省市区扩展

> Aimanong 的官方扩展：中国省市区三级联动字段类型（`->region()`）。

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## ⚠️ 先看这条：内置数据**只有 3 个省**

**这个包不包含完整的中国省市区数据。**

内置的只有 **5 个省**（北京 / 上海 / 广东 / 浙江 / 四川，共 37 个节点），
作用是**让字段能跑起来、能演示**。
生产环境请通过 `Region::useData()` 注入完整数据集（约 3400 条）。

> 这组数字是**跑出来的**，不是估的：
> `count(Region::tree())` = 5，展开全部节点 = 37。
> 写文档时我第一版写成「3 个省 / 约 16 条」（凭印象数的），差点发出去。

```php
use Aimanong\Region\Region;

// 从你自己的数据源加载完整树，形如：
// [['code' => '110000', 'name' => '北京市', 'children' => [...]]]
Region::useData(require base_path('data/regions.php'));
```

> 为什么不在包里塞完整数据：那会让所有用户都背着一份可能过时的
> 行政区划表（每年都在变），且包体积翻几倍。
> 数据由使用方自己掌控版本，更合适。

## 安装

```bash
composer require aimanong/region
```

## 注册

扩展**必须显式登记**，框架不会自动发现 —— 加到 `config/aimanong.php`：

```php
'extensions' => [
    \Aimanong\Region\RegionExtension::class,
],
```

> 不登记的话 `RegionExtension` 根本不会 boot，
> `FieldType::fieldViewFor('region')` 会是 null，字段渲染不出来。

## 用法

```php
public static function form(Form $form): void
{
    $form->region('province_code')->label('省份');
    $form->region('area_code')->label('所在地区');
}
```

三级联动（省 → 市 → 区），提交的是**编码**（如 `110108`），不是名称。

### 辅助函数

```php
region_name('110108');   // '北京市/市辖区/海淀区'  ← 返回**完整路径**，不是末级名称
region_name('110101');   // '北京市/市辖区/东城区'
region_provinces();      // [['code' => '110000', 'name' => '北京市'], ...]
```

> ⚠️ `region_name()` 返回的是**从省到区县的完整路径**（斜杠分隔）。
> 只要末级名称的话，得自己 `explode('/', $name)` 取最后一段。

### 数据源 API

```php
Region::tree();                          // 完整树
Region::provinces();                     // 省级列表
Region::cities('110000');                // 某省下的市
Region::useData($tree);                  // 注入自定义数据（生产用这个）
```

## 环境要求

- PHP ^8.2
- `aimanong/framework`（会一并装上）

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。

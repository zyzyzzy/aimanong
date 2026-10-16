# Aimanong · 省市区扩展

> Aimanong 的官方扩展：中国省市区三级联动字段类型（`->region()`）。

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](https://github.com/zyzyzzy/aimanong/blob/main/LICENSE)

## ⚠️ 先看这条：内置数据**只有 5 个省**

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

三级联动（省 → 市 → 区）。

### 存进去的是什么

**默认存名称路径**（如 `广东省/深圳市/南山区`）。
想要编码路径（如 `440000/440300/440305`）就加 `->storeCode()`：

```php
$form->region('area')->label('所在地区');            // 存「广东省/深圳市/南山区」
$form->region('area_code')->label('地区编码')->storeCode();  // 存「440000/440300/440305」
```

> ⚠️ **这里原先写的是「提交的是编码（如 `110108`），不是名称」—— 是错的。**
> `storeCode()` 当时**没有任何消费方**：字段类设了 prop，前端视图从不读它，
> 于是无论加不加 `->storeCode()`，库里存的都是名称路径。
> 不报错、也从界面上看不出来。
> 2026-10-16 补 region 门禁时发现并修好，同一天加了
> `RegionPropConsumerTest` 防止再出现「声明了但没人消费」。
>
> 另外：编辑器字段的两种路径**都认得**（按「首段是不是纯数字」判断），
> 所以改默认值不会让历史数据打不开。

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
Region::districts('110100');             // 某市下的区县
Region::fullName('110108');              // '北京市/市辖区/海淀区'
Region::useData($tree);                  // 注入自定义数据（生产用这个）
Region::reset();                         // 恢复内置数据
```

> ⚠️ **`useData([])` 之后必须用 `reset()` 才能拿回内置数据。**
> 因为 `tree()` 是 `$custom ?? builtin()`，而空数组不是 `null`，
> `??` 不会回落 —— 传了空数组，内置数据就永久消失且无法恢复。
> （这个坑是补单测时踩出来的：`tearDown` 里用 `useData([])` 复位，
> 结果第一个用例之后全是空数据。真实场景里「配置读失败 / 缓存为空 /
> 远程返回 `[]`」都会撞上它，症状是下拉框一个省都没有、且无报错。）

## 测试

```bash
cd packages/region && composer install   # 首次
composer test        # PHPUnit
composer phpstan     # Level 8
composer pint -- --test
```

`RegionExtension` 的注册链路（`FieldType` 注册、`composer.json` 里声明的
扩展类存在性）也在单测范围内；视图渲染由 `scripts/setup-demo.sh` 建的
demo + e2e 覆盖。

## 环境要求

- PHP ^8.2
- `aimanong/framework`（会一并装上）

## 许可

Apache License 2.0 —— 允许免费商用，含明文专利授权。

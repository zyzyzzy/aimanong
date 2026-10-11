# packages/ui 独立包：要不要做？

> 2026-10-11 · 决策分析 · 结论：**不建议做**，但发现一个更该优先修的问题

---

## 一、结论先行

| 问题 | 结论 |
|---|---|
| 有必要另立目标做 `@aimanong/ui` 独立包吗？ | **没有。当前做它收益接近零，成本不小** |
| 那 `packages/ui` 空目录怎么处理？ | **删掉**（或保留但明确标注「暂不规划」） |
| 分析过程中发现了什么更该做的？ | **框架核心硬依赖 region 插件，未装插件的用户后台直接 Fatal error** ← 这个才是该优先修的 |

---

## 二、先说「独立包」到底解决什么问题

「做个 npm 组件库」听起来是标配，但必须先问：**谁消费它？** 我逐个场景验了：

| 场景 | 需要 npm 包吗 | 原因 |
|---|---|---|
| **① 用 Aimanong 做后台的项目** | ❌ 不需要 | 他们只写 PHP Resource，前端由 Schema 自动渲染。给他 npm 包反而增加负担 |
| **② 想复用设计系统但不用本框架** | ⚠️ 只要 CSS | 他们要的是 token 和 332 个 `am-` 类，不是 Vue 组件 |
| **③ 给框架写插件、要注入前端 UI** | ✅ **确实需要** | 这是唯一真实需求 |

**所以真问题是场景 ③**：插件怎么把前端 UI 注入进来。

而这个问题**现在已经有答案了**（见下节），只是答案不在 `packages/ui`。

---

## 三、场景 ③ 的现状：一个架构缺陷

`aimanong/region` 是官方插件，它的字段 UI 长这样：

```php
// packages/region/src/RegionExtension.php
public function register(): void
{
    $this->field(RegionField::class, 'region', 'string', 'string');   // 只注册了「字段类型」
}
```

它**没有注入任何前端代码**。真正渲染它的 UI 在框架核心里：

```php
{{-- packages/framework/resources/views/resource.blade.php:191 --}}
<div v-else-if="f.type === 'region'" class="am-flex am-gap-2">
    <select v-model="ensureRegion(f.name).province" ...>
```

```js
// resource.blade.php:540 —— 无条件调用！
const regionProvinces = @json(\Aimanong\Region\Region::provinces());
const regionTree      = @json(\Aimanong\Region\Region::tree());
```

### 这带来两个问题

**问题 A：插件无法自带前端**

框架核心的模板里**写死了 region 的 UI**。第三方插件想加自己的字段类型，
除了「注册字段类」，没有任何途径注入前端渲染逻辑 ——
只能来改框架源码，而这正是「插件」要避免的事。

**问题 B：框架核心硬依赖了插件，但 composer 没声明** ⚠️

```bash
$ grep -n "region" packages/framework/composer.json
（无输出 —— framework 没有依赖 region）

$ grep -rn "Aimanong\\\\Region" packages/framework/resources/
resource.blade.php:540:  @json(\Aimanong\Region\Region::provinces());
resource.blade.php:541:  @json(\Aimanong\Region\Region::tree());
```

**实测验证**（静态判定，无守卫）：

```
=== 模板中对 Region 的引用 ===
  第 540 行: Region::provinces()
  第 541 行: Region::tree()

=== 这些调用是否有守卫 ===
  ❌ 无任何守卫 —— 未安装 region 插件时模板必然抛 Class not found
```

**影响面**：`resource.blade.php` 是**所有 Resource 的列表/表单页**。

> 一个只执行 `composer require aimanong/framework` 的用户
> （按 README 的「快速开始」照做），打开任意列表页就会
> **PHP Fatal error → 整个后台不可用**。
>
> 而 `aimanong:install` 也不会帮他装 region。

**这是 P0 级缺陷。** 一个「带地基的平台」，装完打不开，地基就无从谈起。

---

## 四、为什么「做独立包」不是这个问题的解

即使做了 `@aimanong/ui`，也解决不了上面两个问题：

| 想解决的问题 | 独立包能解决吗 | 为什么 |
|---|---|---|
| 插件自带前端 UI | ❌ | 插件前端要注入**框架的页面运行时**，而 npm 包是构建期依赖。使用者是零构建的，装不了 npm 包 |
| 框架不硬依赖插件 | ❌ | 这是模板里少了个 `class_exists` 守卫，与包结构无关 |
| 复用设计系统 | ⚠️ 部分 | 想复用的是 CSS，单独发一个 `aimanong/design-system` 更直接 |

**独立包要解决的是「构建期组件复用」，而本项目的使用者根本不构建。**
包结构再漂亮，也接不到那个运行时的注入口上。

---

## 五、成本 vs 收益

### 做独立包的成本（按现状估算）

| 项 | 工作量 | 说明 |
|---|---|---|
| 建 npm 包骨架 + 发布流程 | 1-2 天 | package.json / tsconfig / 构建配置 / CI 发布 |
| 把 332 个 CSS 类抽成可分发形式 | 3-5 天 | 现在它依赖 blade 里的 CSS 变量注入 |
| 把内联 Vue 组件抽成 SFC | **5-10 天** | `upload` 组件现在依赖 `window.AimanongUpload` 全局契约；`resource.blade.php` 944 行是页面不是组件 |
| 引入构建步骤本身 | — | **与硬约束冲突**（见下） |
| 维护双份产物 | 持续 | 内联版（运行时用）+ npm 版（对外发）——**必然漂移** |

最后一条是致命的：项目已经在治理「数据源分叉」，
再造一个「同一个组件两份实现」的分叉源，与 ADR-004 直接冲突。

### 硬约束检查

`internal-docs/UI重做需求.md` 里明确写着：

> ## 二、重做的硬约束（不可违反）
> | 约束 | 原因 |
> | **零构建步骤** | 不能引入 Tailwind 编译 / npm build |
> | **零外部 CDN** | 今天刚踩过：jsdelivr 挂了导致后台全废 |

**注意区分**：这条约束管的是「**使用者的项目**不能需要构建」，
不禁止框架**自己**在开发期用构建（`docs/` 就有 VitePress 构建）。

但即便如此，如果框架内部改成 SFC + 构建，
产出的依然是「给使用者直出的 JS/CSS」——
那和现在**内联在 blade 里的效果完全一样**，
只是多了一套构建工具链要维护。

**换句话说：构建只改变了框架作者的开发体验，没有改变使用者的任何东西。**
而这套工具的收益（组件写法更舒服）远小于它的成本（构建链 + 双份产物 + 漂移风险）。

---

## 六、我的建议：按优先级重排

### 🔴 P0：先修「未装插件导致后台全废」（1 小时内可完成）

```php
// resource.blade.php —— 加守卫
$regionAvailable = class_exists(\Aimanong\Region\Region::class);
// 模板里：
const regionProvinces = @json($regionAvailable ? \Aimanong\Region\Region::provinces() : []);
const regionTree      = @json($regionAvailable ? \Aimanong\Region\Region::tree() : []);
```

并把 region 从「示例插件」提升为**可选依赖**，
在 composer.json 的 `suggest` 里声明，`install` 命令给出提示。

**这个必须马上做** —— 它直接影响「新人装完能不能用」。

### 🟡 P1：给插件机制补一个前端注入口（真正该做的「组件复用」）

不是做 npm 包，而是让插件能自带渲染：

```php
// 插件侧（示意）
$this->fieldsView('aimanong-region::field');   // 插件自带 blade 片段
$this->transpile('resources/js/region.js');    // 或直接注入一段 JS
```

框架侧在渲染字段时 `@include` 插件的片段。
**这才是场景 ③ 的正解**，而且完全符合零构建约束。

### 🟢 P2：`packages/ui` 目录

**删掉**。留着空目录会让人（和 AI）以为「这里应该有东西」。

如果将来真要对外发设计系统，正确的形态是
`aimanong/design-system`（一个 CSS 包，含 token + 332 个 `am-` 类），
**不是 Vue 组件库** —— 因为唯一想复用它的人要的是样式，不是组件逻辑。

---

## 六点五、执行结果（2026-10-11 当天完成）

| 顺序 | 事项 | 状态 | 提交 |
|---|---|---|---|
| 1 | 修 region 硬依赖（守卫 + `suggest`） | ✅ | `d34c894` + `14228b5` |
| 2 | e2e 断言「未装 region 时仍可用」 | ✅ | 同上（框架 58 → 59 项） |
| 3 | 删 `packages/ui` 空目录 + 更新表述 | ✅ | `36d11e1` |
| 4 | **插件前端注入口**（真正的组件复用） | ✅ | `b080689` |
| 5 | 独立 UI 包 | ⬜ 不做（本文结论） |

### 第 4 项落地形态

```php
// 插件侧：自带 UI
$this->views(__DIR__.'/../resources/views', 'aimanong-region');
$this->fieldView('region', 'aimanong-region::field');   // 长什么样
$this->fieldScript('aimanong-region::script');          // 逻辑怎么算
```

region 插件据此带走了自己的 61 行 UI 代码，框架核心不再有任何
region 专用逻辑 —— **清空插件注册后重新渲染页面，无任何残留**。

第三方插件现在也能做到同样的事，不必再改框架源码。

---

## 七、建议的执行顺序

| 顺序 | 事项 | 工作量 |
|---|---|---|
| 1 | 修 region 硬依赖（加守卫 + suggest 声明） | ~1 小时 |
| 2 | 补一条 e2e：**不装 region 时列表页仍 200** | ~1 小时 |
| 3 | 删 `packages/ui` 空目录，更新 README/AGENTS 表述 | ~10 分钟 |
| 4 | 给插件机制补前端注入口（P1，可另立目标） | 2-3 天 |
| 5 | （暂不做）独立 UI 包 | — |

---

## 八、一句话总结

> **「做个独立组件包」是个看起来专业、实际接不上线的动作。**
> 本项目的使用者零构建，npm 包到不了他们的运行时；
> 而真正需要「组件复用」的地方是插件机制，
> 它需要的是**模板注入点**，不是 npm 包。
>
> 分析过程中反而发现一个更急的问题：
> **框架核心硬依赖 region 插件且没有守卫 —— 只装 framework 的用户，
> 后台一打开就是 Fatal error。** 这个必须先修。

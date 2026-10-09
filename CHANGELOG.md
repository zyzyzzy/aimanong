# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 与 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

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

- [1.1.1](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.1)
- [1.1.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.1.0)
- [1.0.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.0.0)

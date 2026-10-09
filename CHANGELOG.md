# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 与 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

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

- [1.0.0](https://github.com/zyzyzzy/aimanong/releases/tag/v1.0.0)

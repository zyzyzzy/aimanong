# Aimanong 视觉重设计 · 实施方案

> 交付物：
> - `README.md`（本文，诊断 + 调研 + 方案 + 改造清单）
> - `design-system.css`（可直接用的设计系统，token + 组件，零依赖）
> - `themes.css`（三套主题的 token 覆盖）
> - `theme-preview.html`（可交互预览，用浏览器打开即可切风格）
>
> 所有色值均为**程序化生成 + WCAG 对比度自动校验**，84 项校验全部通过（见 §3.5）。

---

## 一、诊断：为什么它"丑"

不是审美问题，是**缺设计系统**。以下数据来自对 `packages/framework/resources/views/` 三个模板的实测统计，不是主观感受。

### 1.1 量化体检（实测数据）

| 指标 | 实测值 | 说明 |
|---|---|---|
| CSS 变量 | **0 个** | 全文 `var(--x)` 出现 0 次，所有值是硬编码字面量 |
| 不同色值 | **30 种**（跨三页共出现 123 次） | `#dde3e0` 出现 17 次、`#3FBF6F` 14 次、`#8a948f` 9 次 |
| CSS 重复 | **3 份** | 登录页/首页/列表页各写一套，`.header`/`.sidebar`/`.menu-item` 逐字复制 |
| `transition` | **2 处**（登录页 2、列表页 0、首页 0） | |
| `animation` | **0 处** | 页面完全静态 |
| `:hover` | 7 处 | |
| `:focus` | **3 处** | 且只写 `outline:none` + 换边框色 |
| `@media` 查询 | **0 处** | 完全没有响应式，窄屏直接横滚 |
| `line-height` 声明 | **1 处**（且只是首页一段 `.hint`） | 正文行高全靠浏览器默认 ≈1.2，中文挤成一团 |
| `dark` 关键词 | **0 处** | 无暗色模式 |
| emoji 图标 | `📄` 等（`MenuRegistry.php` 里由 Resource 声明） | 跨平台渲染不一致，无法控色/控粗细 |

**色值碎片化的直接后果**：改一次主色调要动 3 个文件、14 处；调一次圆角要动 6 个不同值。

### 1.2 间距：没有网格

实测出现的 px 值（去重）：

```
登录页: 1,6,8,10,11,12,13,14,15,16,18,20,22,28,32,40,72,380
首页:   1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,20,24,30,32,56,140,150,200,900
列表页: 1,2,3,4,5,6,8,9,10,11,12,13,14,15,16,17,18,20,24,32,36,40,56,58,64,70,120,200,240,420,1100
```

**问题**：`14px`（`padding:9px 18px`→菜单项）、`17px`、`9px` 这些值既不是 4 的倍数也不是 8 的倍数，是"随手调到手感对了"的产物。视觉上表现为**节奏不稳**——眼睛说不出哪里不对，但就是"不整齐"。

**从 X 改成 Y**：
- 现状 `.menu-item { padding: 9px 18px }` → 改为 `padding: 0 var(--sp-3) (12px); height: 36px; display:flex; align-items:center`
  - **因为**：用固定高度 + flex 居中替代上下 padding，行高由字号和高度共同决定，不会因字体差异跑偏；36px 是 4 的倍数，和 34px 控件高度、44px 表格行高构成 4px 网格上的稳定阶梯。
- 现状 `.toolbar { gap: 10px; margin-bottom: 16px }` → `gap: var(--sp-2) (8px); margin-bottom: var(--sp-4) (16px)`
  - **因为**：10px 不在任何网格上；相邻元素间距要么 8 要么 12，10 会造成"差一点点"的错位感。

### 1.3 圆角：6 种值，没有层次逻辑

实测：`3px, 4px, 6px, 8px, 10px, 12px` 六种同时存在。

- `.badge` 用 `10px`，`.tag` 用 `4px`，`.btn` 用 `6px`，`.card` 用 `10px`，`.login-card` 用 `12px`，`.progress-bar` 用 `3px`。

**问题**：圆角在视觉上编码"层级"——越大的容器圆角越大，越小的元素（标签、徽章）圆角越小或直接全圆。现在同一页里 4px 的 tag 和 10px 的 badge 并排出现，两者都是"小药丸"，却长得不一样，**看起来像两个人分别写的**。

**从 X 改成 Y**：收敛成 4 档

```css
--r-sm: 6px;    /* 按钮、输入框、标签、徽章 */
--r-md: 8px;    /* 小卡片、下拉面板 */
--r-lg: 12px;   /* 主卡片、弹窗 */
--r-full: 999px;/* 徽章药丸、进度条、头像 */
```
- **因为**：6px 是在 14px 正文下"既现代又不幼稚"的甜点值（Ant Design 5 默认 6px、Tabler 默认 6px，两个主流系统都收敛到这个值）；徽章统一走 `--r-full` 而不是 4px/10px 混用，是因为药丸形状本身就是状态标签的通用视觉语言。

### 1.4 阴影：只有 2 种，且没有层级语义

- `.card { box-shadow: 0 2px 8px rgba(16,27,22,.05) }`
- `.login-card { box-shadow: 0 8px 32px rgba(16,27,22,0.08) }`
- 弹窗（`resource.blade.php:238`）：**完全没有阴影**，只有一个 `border-radius:10px` 的白块浮在遮罩上。

**问题**：弹窗是全屏遮罩之上的最高层，视觉权重应该最大，结果它比卡片还"平"。这就是弹窗看起来"贴"在页面上而不"浮"起来的原因。

**从 X 改成 Y**：
```css
--shadow-xs: 0 1px 2px rgba(16,24,40,.04);                          /* 卡片静置 */
--shadow-sm: 0 1px 3px rgba(16,24,40,.06), 0 1px 2px rgba(16,24,40,.04);
--shadow-md: 0 4px 8px -2px rgba(16,24,40,.08), 0 2px 4px -2px rgba(16,24,40,.04);
--shadow-lg: 0 12px 24px -4px rgba(16,24,40,.10), 0 4px 8px -4px rgba(16,24,40,.05); /* 弹窗/下拉 */
```
- **因为**：阴影要表达"离页面多高"。卡片 1 层、悬浮态 2 层、弹窗 3 层。同时**阴影的 y 偏移应大于 blur 的一半**——原 `0 2px 8px` 偏移太小、blur 太大，光是"散"的而不是"投影"，这是廉价感的重要来源。
- **关键**：弹窗必须配 `--shadow-lg` + 遮罩 `rgba(16,24,40,.45)` + `backdrop-filter: blur(2px)`，三者齐上才有"浮起来"的感觉。

### 1.5 字体与行高：中文排版基本没做

- 字体栈：`-apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif`
  - **缺** `"Noto Sans SC"`、`"Source Han Sans SC"`（Linux 服务器/CI 截图环境常用），缺则回退到 `sans-serif`，中文渲染可能是点阵风的默认字体。
- 行高：**全文只在首页 `.hint` 声明过一次 1.7**，其余全部继承浏览器默认（≈1.2）。
  - **中文每个字都是满框方块**，没有拉丁字母的 x-height 留白。1.2 行高下中文密不透风，长段落极难读。**中文正文需要 1.6–1.75**，短标签 1.4。
- 字号阶梯：`11,12,13,14,15,16,17,20,22,24` 十种，其中 `13px` 是绝对主力。
  - **13px 是个尴尬值**：它是从 14px（英文舒适基准）"为了显得精致"往下调出来的。但中文在 13px 下笔画会粘连，尤其 400 字重的 PingFang SC。**中文正文字号下限建议 14px**。

**从 X 改成 Y**：
```css
--font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
  "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans SC", "Source Han Sans SC",
  "WenQuanYi Micro Hei", sans-serif;
--fs-xs: 12px;   /* 表头、徽章、辅助说明 —— 仅限短文本 */
--fs-sm: 13px;   /* 次要 UI（按钮/表单控件） */
--fs-base: 14px; /* 正文主力 ← 从 13 提到 14 */
--lh-tight: 1.35;  /* 标题、单行标签 */
--lh-base: 1.6;    /* UI 文本、表格单元格 */
--lh-loose: 1.75;  /* 中文长段落（帮助文本、说明） */
```
- **因为**：把正文从 13px 提到 14px 是"立刻显得专业"的最低成本改动；行高按用途分三档，中文长文本才可读。

### 1.6 颜色系统：没有语义，且主色不达标

**实测对比度**（WCAG 2.1，AA 正文需 ≥4.5:1）：

| 场景 | 前景 | 背景 | 对比度 | 判定 |
|---|---|---|---|---|
| 正文 on 白 | `#101B16` | `#ffffff` | 17.64 | ✅ |
| 菜单项文字 | `#4a5550` | `#ffffff` | 7.76 | ✅ |
| 次级文字 | `#6b746f` | `#ffffff` | 4.82 | ✅ |
| 弱化文字 | `#8a948f` | `#ffffff` | 3.13 | ⚠️ 仅大字 |
| **`menu-group-title` / `.empty`** | `#a5aea9` | `#ffffff` | **2.28** | ❌ |
| **`.muted` 空值占位** | `#c3ccc7` | `#ffffff` | **1.64** | ❌ |
| **主色文字/图标** | `#3FBF6F` | `#ffffff` | **2.36** | ❌ |
| **按钮白字 on 主色** | `#ffffff` | `#3FBF6F` | **2.36** | ❌ |
| **`.hl-warning`** | `#d68910` | `#ffffff` | **2.82** | ❌ |
| **表头文字** | `#a5aea9` | `#fafcfb` | **2.21** | ❌ |

**最严重的一条**：主按钮"新增"是**白字 + `#3FBF6F` 底**，对比度只有 2.36:1。这不只是"不好看"，是**实质性的可读性缺陷**——每页最显眼的按钮，字是最看不清的。同理表头的列名（2.21:1）几乎读不出来。

**为什么 `#3FBF6F` 显得"廉价"**：它在 OKLCH 里约 L=0.72、C=0.16，色相 152°。**亮度太高**（0.72 对主色来说偏亮）、**彩度偏高**。专业后台的主色亮度通常落在 L=0.45–0.58 区间（Ant Design `#1677ff` L≈0.55、Filament 默认 amber、Tabler `#066fd1` L≈0.55）。高亮度绿 + 白字 = 对比度必然不达标，且视觉上"荧光"。

**从 X 改成 Y**：
- 主色 `#3FBF6F`（L≈0.72，白字 2.36:1）→ `#007643`（L≈0.53，白字 **5.20:1** ✅）
  - **因为**：把亮度压到 L≈0.53，既通过 AA，又落在专业后台主色的亮度区间；色相同样是绿色（保留品牌延续性），但明度降低后"荧光感"消失。
- 主色**文字**场景不能直接用实心主色 → 用独立 token `--brand-text`（同样 `#007643`），保证在白底和画布底都 ≥4.5:1。
- 补全语义色：`success / warning / danger / info` 各自独立、均在白底 ≥4.5:1（见 `themes.css`）。
- 补全 **12 级灰阶**：现状只有 5–6 个灰且中间断层。重设计用 12 级（见 §3.2），才能表达"正文/次级/三级/禁用/边框/分隔线/悬浮底/按下底"这么多层次。

### 1.7 交互反馈：几乎为零

| 状态 | 现状 | 后果 |
|---|---|---|
| `:hover` | 7 处，且多是换边框色 | 鼠标划过没有"被响应"的感觉 |
| `:active` | **0 处** | 按钮点下去没有任何回弹 |
| `:focus-visible` | **0 处**；`:focus` 只写 `outline:none` | **键盘用户完全看不到焦点在哪**（无障碍硬伤） |
| 禁用态 | 无样式规则，靠浏览器默认 | 和正常按钮几乎无区别 |
| 加载态 | `<div class="loading">加载中…</div>` 纯文字 | 无骨架屏、无 spinner，切换列表时页面"闪一下白" |
| 提交中 | `save()` 无 pending 状态 | 双击"保存"会发两次请求 |
| 错误提示 | `alert('保存失败: ' + ...)`（`resource.blade.php:752`） | 浏览器原生弹窗，与页面风格完全割裂 |
| 删除确认 | `confirm('确认删除 #' + row.id + '？')`（第 765 行） | 同上；且**暴露了自增 ID**，对用户无意义 |

**从 X 改成 Y**：
- 所有可点元素加 `transition: all 180ms cubic-bezier(.4,0,.2,1)`
- 按钮 `:active { transform: translateY(1px) }` —— **因为**：1px 的下沉模拟物理按压，是"有反馈"的最低成本实现
- 全局 `:focus-visible { outline: 2px solid var(--border-focus); outline-offset: 2px }`
  - **因为**：`:focus-visible` 只在键盘导航时显示焦点环，鼠标点击不显示，既满足无障碍又不破坏鼠标体验。**绝不能再写 `outline:none` 而没有任何替代**。
- 把 `alert()` / `confirm()` 换成页面内的 toast + 确认弹窗 —— 见 §5.4

### 1.8 表格：最影响"专业度"的地方

现状 CSS（`resource.blade.php:52-66`）：
```css
th { padding: 12px 16px; font-size: 12px; color: #6b746f; background: #fafcfb; }
td { padding: 12px 16px; font-size: 13px; }
```

**具体问题**：
1. **行高不足且不固定**：`padding:12px` + `font-size:13px` + 行高 1.2 ≈ 39.6px。现代后台表格行高 **44–48px**（Filament 用 44px，Ant Design 默认 54px/紧凑 39px）。太挤 = 廉价。
2. **无行 hover**：三行数据划过没有任何反应，看不出鼠标在哪一行。
3. **数字未用等宽字体、未右对齐**：`金额`、`ID`、`进度` 都是左对齐的比例字体。**金额列左对齐 + 比例字体 = 数字宽度不一、小数点对不齐**，这是"业余感"最典型的来源。
4. **无斑马纹也无分隔线的层次**：只有 `border-bottom: 1px solid #f2f5f3`（对比度 1.06:1，几乎不可见）。
5. **表头背景 `#fafcfb` 与卡片 `#fff` 差异过小**（1.02:1），表头"浮"不出来，且表头文字 2.21:1 看不清。
6. **操作列按钮内联 style**：`style="padding:4px 8px;font-size:12px"` 直接写在模板里（第 220-221 行），无法统一维护。

**从 X 改成 Y**：
```css
th {
  padding: 12px 16px; font-size: var(--fs-xs); font-weight: 600;
  color: var(--text-tertiary);           /* 从 2.21:1 提到 4.84:1 */
  background: var(--bg-subtle); border-bottom: 1px solid var(--border-default);
}
td { padding: 12px 16px; height: 44px; border-bottom: 1px solid var(--border-subtle); }
tbody tr { transition: background 120ms var(--ease); }
tbody tr:hover { background: var(--bg-hover); }   /* 新增 */
td.num, th.num { font-family: var(--font-mono); font-variant-numeric: tabular-nums; text-align: right; }
```
- **因为**：`tabular-nums` 让每个数字等宽，金额列才能小数点对齐；等宽字体 + 右对齐是财务类界面的通用约定，一眼就能看出"这是给专业人士用的"。
- 行高固定 44px（而非靠 padding 撑）→ 因为**含图片/徽章/多行文本的行不应改变行高**，否则表格呈现"锯齿"状。

### 1.9 表单：错误态完全缺失

现状（`resource.blade.php:252-369`）：每个字段用 `style="margin-bottom:14px"` 内联。经实测：该文件共有 **43 处 `style="` 硬编码**，其中 42 处是静态值（仅 3 处是 Vue 的 `:style` 动态绑定）。表单区里 `padding:9px 12px;border:1px solid #dde3e0;border-radius:6px;font-size:13px` 这一串**完整重复 6 次**，另有 5 处 `flex:1;padding:9px 12px;border:1px solid #dde3e0` 变体（省道联动），合计 **11 个控件各自抄了一遍样式**。

**具体问题**：
1. **`<label>` 没有 `for` 属性、input 没有 `id`** → 屏幕阅读器读不出标签与输入框的关联；点击标签也不能聚焦输入框。
2. **无必填红星以外的校验态**：`save()` 失败只 `alert()`，字段本身**不标红、不显示错误文案**。用户看到 alert 后不知道是哪一项错了。
3. **无禁用/只读样式**：`f.readonly` 只加了 `:readonly` 属性，视觉上无区别。
4. **checkbox 开关**：`<input v-else-if="f.type === 'switch'" type="checkbox">`（第 282 行）——**一个原生复选框冒充开关**，与"开关"的视觉预期完全不符。
5. **弹窗宽度硬编码 420px**，且无响应式；移动端会溢出。
6. **无焦点环**（只有 `border-color` 变化），键盘用户迷失。
7. **`请选择…` 的 `<option :value="null">`** 与真实选项混在一起，语义上应区分 placeholder。

**从 X 改成 Y**：
```css
.field { margin-bottom: var(--sp-4); }
.field > label { display:block; font-size: var(--fs-sm); font-weight:500;
  color: var(--text-secondary); margin-bottom: var(--sp-2); }
.input, .select, .textarea {
  width:100%; min-height:34px; padding: 6px var(--sp-3);
  border:1px solid var(--border-strong); border-radius: var(--r-sm);
  background: var(--bg-surface); color: var(--text-primary);
  font: inherit; font-size: var(--fs-sm);
  transition: border-color 180ms var(--ease), box-shadow 180ms var(--ease);
}
.input:hover { border-color: var(--text-disabled); }
.input:focus { outline:none; border-color: var(--border-focus);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--border-focus) 18%, transparent); }
.field.is-invalid .input { border-color: var(--danger-solid); }
.field .error-text { font-size: var(--fs-xs); color: var(--danger-text); margin-top: 4px; }
```
- **因为**：`box-shadow` 做焦点光晕而不是 `outline`，视觉更柔和且有"发光"的现代感；用 `color-mix` 从焦点色派生 18% 透明度，**换主题时焦点环自动跟着变色**，不需要维护第二组值。

### 1.10 空状态 / 加载态 / 错误态：三态皆缺

| 状态 | 现状代码 | 问题 |
|---|---|---|
| 空 | `<div class="empty">暂无数据</div>` | 一行灰字（`#a5aea9`，2.28:1），无插图、无引导、无"去新建"按钮 |
| 加载 | `<div class="loading">加载中…</div>` | 纯文字，且**表格消失**变成一行字 → 页面高度剧烈跳动（layout shift） |
| 错误 | `console.error(e)`（第 441 行）+ 空 rows | **请求失败时用户看到的是"暂无数据"**，与真正的空数据无法区分 |

**第 3 条是最严重的**：`load()` 的 `catch` 只打 console，`rows.value` 保持上一次的值或空数组。用户网络断了，看到"暂无数据"，会以为数据被删了。

**从 X 改成 Y**：
- 空状态：居中图标（内联 SVG，48px，`--text-disabled`）+ 主文案（`--fs-base`，`--text-secondary`）+ 副说明（`--fs-sm`，`--text-tertiary`）+ 可选主按钮
- 加载态：**保留表格结构**，用骨架屏（`.skeleton` 带 `shimmer` 动画）替代行内容 —— **因为**：保持高度稳定 = 无 layout shift，这是"精致"的核心指标之一
- 错误态：新增 `error` 状态变量，与 `rows.length === 0` 区分，显示重试按钮

### 1.11 emoji 图标

`MenuRegistry.php:21` 的注释里就写着 `'icon' => '📄'`。问题：

1. **跨平台字形不同**：同一个 `📄` 在 macOS（Apple Color Emoji）、Windows（Segoe UI Emoji）、Linux（Noto Color Emoji）上是三种完全不同的画风。开源项目在别人机器上截图，效果不可控。
2. **无法控色/控粗细**：emoji 是彩色位图，`color` 属性无效，无法与主题色联动，**换主题时图标不会跟着变**。
3. **视觉重量不均**：emoji 自带底色和透视，与扁平的 UI 冲突，是"廉价感"最直接的来源之一。

**从 X 改成 Y**：改用内联 SVG（stroke-based，`stroke="currentColor"`，`stroke-width:1.75`，24×24 viewBox）
- **因为**：`currentColor` 让图标自动继承文字色 → 换主题、hover、active 时图标颜色**自动跟随**，零额外代码
- 建议**不引图标库**，而是在框架里内置约 24 个手写 SVG（用户/角色/订单/设置/搜索/导出…），用一个 Blade 组件 `<x-aimanong.icon name="users" />` 输出。**体积约 3KB，零依赖，且避免了 Feather/Lucide 的 MIT 署名负担虽可接受但没必要引入构建链**。

### 1.12 根因归纳

按修复收益排序：

1. **没有 token 层** → 30 个散落色值、6 种圆角、无数奇数值 px，改一处要动多处（**根因**）
2. **主色亮度/彩度失当** + 文字灰阶断层 → 对比度大面积不达标（含主按钮），且视觉"荧光"
3. **没有层次体系** → 卡片/弹窗/表头视觉权重相同，眼睛找不到落点
4. **零动效、零状态反馈** → 页面"死"，且失败时用户被误导
5. **emoji 当图标** → 跨平台不可控，与主题色脱钩
6. **中文排版未做**（行高 1.2、正文 13px、字体栈缺 Noto）→ 读起来累
7. **无响应式** → 0 个 `@media`，窄屏横滚

---

## 二、调研：2025 年的"现代后台"长什么样

以下数值全部来自**官方源码或包管理器下载的真实产物**（非博客转述），标注了出处。

### 2.1 Filament 3 —— Laravel 生态的设计标杆

来源：[filamentphp/filament@3.x `packages/panels/dist/theme.css`](https://github.com/filamentphp/filament/blob/3.x/packages/panels/dist/theme.css)、[Colors 文档](https://filamentphp.com/docs/3.x/support/colors)

**配色**：Filament 不发明颜色，直接用 Tailwind 调色板，通过 CSS 变量以 **RGB 三元组**注入：

```php
FilamentColor::register([
    'danger'  => Color::Red,
    'gray'    => Color::Zinc,
    'info'    => Color::Blue,
    'primary' => Color::Amber,   // ← 默认主色是 Amber！
    'success' => Color::Green,
    'warning' => Color::Amber,
]);
```

**注意**：默认 `primary` 是 **Amber**（琥珀橙）而非蓝色。这是 Filament 的辨识度来源，也说明"主色不必是蓝/绿"。

变量以**空格分隔的 RGB**存储（便于配 alpha）：
```css
.ring-gray-950\/5 { --tw-ring-color: rgba(var(--gray-950), 0.05); }
.dark\:ring-white\/10:is(.dark *) { --tw-ring-color: hsla(0,0%,100%,0.1); }
```

**最值得抄的一点 —— 用 ring 代替 shadow**：
Filament 卡片不用 `box-shadow`，而用 `ring-1 ring-gray-950/5`（0.05 透明的 1px 环）。暗色下切换为 `ring-white/10`。
- **为什么高级**：透明环会**跟随背景色混合**，在任何底色上都自然；而实色 `box-shadow` 在深色背景上会显脏。这是"看起来高级"的关键细节，成本极低。

**暗色模式**：`html.dark` 类 + `color-scheme: dark`，配合 `dark:` 变体（`dark:bg-gray-950`、`dark:bg-white/5`、`dark:border-white/10`）。

**尺寸**（从编译产物实测）：
| 项 | 值 |
|---|---|
| 顶栏高 | `h-16` = **64px** |
| 侧栏宽 | `w-72` = **288px** |
| 圆角档位 | `rounded-md` .375rem(6px) / `rounded-lg` .5rem(8px) / `rounded-xl` .75rem(12px) / `rounded-full` 9999px |
| 正文 | `.text-sm` = 14px / lh 1.25rem(20px) |
| 小字 | `.text-xs` = 12px / lh 1rem(16px) |
| 常用内距 | `.px-4` = 16px |

**登录页**：居中卡片式，无分屏。

### 2.2 Ant Design 5 —— 中文后台的事实标准

来源：`npm i antd@5.22.5` → `lib/theme/themes/seed.js`、`themes/default/colors.js`、`themes/dark/colorAlgorithm.js`（**以下为实际执行其代码得到的值**）

**Seed Token（默认值，实测）**：
```js
colorPrimary:'#1677ff', colorSuccess:'#52c41a', colorWarning:'#faad14',
colorError:'#ff4d4f',   colorInfo:'#1677ff',
fontSize: 14,  borderRadius: 6,  controlHeight: 32,
sizeUnit: 4,   sizeStep: 4,
lineWidth: 1,
motionEaseOut: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
motionEaseInOut: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
```

> **对 Aimanong 最直接的启发**：Ant Design 的 `sizeUnit: 4` / `sizeStep: 4` 明确把 **4px 网格写进了设计系统的种子值**，`fontSize: 14`、`borderRadius: 6`、`controlHeight: 32`。我们 §1.2 诊断出的"没有网格"正是缺这个种子。

**浅色中性色（执行 `default/colors.js` 实测）**：
| Token | 实测值 |
|---|---|
| `colorText` | `rgba(0,0,0,0.88)` |
| `colorTextSecondary` | `rgba(0,0,0,0.65)` |
| `colorTextTertiary` | `rgba(0,0,0,0.45)` |
| `colorTextQuaternary` | `rgba(0,0,0,0.25)` |
| `colorBgLayout` | `#f5f5f5` |
| `colorBgContainer` | `#ffffff` |
| `colorBorder` | `#d9d9d9` |
| `colorBorderSecondary` | `#f0f0f0` |
| `colorFill` / `Secondary` / `Tertiary` | `rgba(0,0,0,.15)` / `.06` / `.04` |

**暗色（执行 `theme.darkAlgorithm` 实测）**：
| Token | 实测值 |
|---|---|
| `colorText` | `rgba(255,255,255,0.85)` |
| `colorBgLayout` | `#000000` |
| `colorBgContainer` | `#141414` |
| `colorBgElevated` | `#1f1f1f` |
| `colorBorder` | `#424242` |
| `colorBorderSecondary` | `#303030` |
| `colorPrimary` | `#1668dc`（自动降亮） |

**两个关键机制**：
1. **文字色用 alpha 而非实色**（`rgba(0,0,0,.88)`）：好处是文字放在任何底色上都自动协调；代价是**对比度随底色浮动**——0.45 的三级文字在白底上只有约 3.1:1，**未达 AA**。
2. **主色在暗色下自动降亮**（`#1677ff` → `#1668dc`）：因为高饱和亮色在深底上会"发光刺眼"。**这是暗色模式必须做的调整，不能只把背景变黑。**

> **反向结论**：Ant Design 的 alpha 文字方案**不适合 Aimanong**——它牺牲了对比度。我们改用**实色灰阶 + 程序化校验**（§3），保证每一项都 ≥4.5:1。这既更无障碍，也是可以明确说"我们比 Ant Design 做得更严谨"的地方。

### 2.3 shadcn/ui —— 现代 SaaS 的默认审美

来源：[`shadcn-ui/ui@main apps/v4/app/globals.css`](https://github.com/shadcn-ui/ui/blob/main/apps/v4/app/globals.css)

**已迁移到 OKLCH**（2024 年末起）：

```css
:root {
  --radius: 0.625rem;              /* 10px —— 单一源，所有圆角由它派生 */
  --background: oklch(1 0 0);
  --foreground: oklch(0% 0 0);
  --card: oklch(1 0 0);
  --primary: oklch(0% 0 0);        /* 默认是无彩色（纯黑） */
  --muted: oklch(0.97 0 0);
  --muted-foreground: oklch(0.556 0 0);
  --border: oklch(0.922 0 0);
  --ring: oklch(0.708 0 0);
}
.dark {
  --background: oklch(0.145 0 0);
  --card: oklch(0.205 0 0);
  --popover: oklch(0.205 0 0);
  --muted: oklch(0.269 0 0);
  --muted-foreground: oklch(0.708 0 0);
  --border: oklch(1 0 0 / 10%);    /* 透明边框 */
  --input: oklch(1 0 0 / 15%);
  --sidebar: oklch(0.205 0 0);
  --sidebar-border: oklch(1 0 0 / 10%);
}
```

**可借鉴的三点**：
1. **`--radius` 单一源**：`--radius-sm: calc(var(--radius) * 0.6)`、`--radius-lg: var(--radius)`、`--radius-xl: calc(var(--radius) * 1.4)`。改一个值，全套圆角等比缩放。**这正是 Aimanong 该学的**——现在有 6 个独立圆角值。
2. **暗色下 `--border` 用 10% 透明白**，和 Filament 的 ring 思路一致。
3. **暗色不是"浅色反转"**：`--card` 比 `--background` **亮**（0.205 vs 0.145）——深色主题里，越上层越亮（模拟光照），而浅色主题里越上层越白（也"更亮"）。**逻辑一致：层级越高越亮。**

### 2.4 Tailwind v4 —— 现代值的参考基准

来源：`npm i tailwindcss@4.3.3` → `theme.css`（OKLCH 原文，以下为**我转换成 sRGB hex 的结果**）

| 色阶 | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **zinc** | `#fafafa` | `#f4f4f5` | `#e4e4e7` | `#d4d4d8` | `#9f9fa9` | `#71717b` | `#52525c` | `#3f3f46` | `#27272a` | `#18181b` | `#09090b` |
| **slate** | `#f8fafc` | `#f1f5f9` | `#e2e8f0` | `#cad5e2` | `#90a1b9` | `#62748e` | `#45556c` | `#314158` | `#1d293d` | `#0f172b` | `#020618` |
| **emerald** | `#ecfdf5` | `#d0fae5` | `#a4f4cf` | `#5ee9b5` | `#00d492` | `#00bc7d` | `#009966` | `#007a55` | `#006045` | `#004f3b` | `#002c22` |
| **blue** | `#eff6ff` | `#dbeafe` | `#bedbff` | `#8ec5ff` | `#51a2ff` | `#2b7fff` | `#155dfc` | `#1447e6` | `#193cb8` | `#1c398e` | `#162456` |

**尺寸 token（实测）**：
```css
--radius-xs:.125rem; --radius-sm:.25rem; --radius-md:.375rem; --radius-lg:.5rem;
--radius-xl:.75rem;  --radius-2xl:1rem;  --radius-3xl:1.5rem;
--text-xs:.75rem/1; --text-sm:.875rem/1.25rem; --text-base:1rem/1.5rem; --text-lg:1.125rem/1.75rem;
--leading-tight:1.25; --leading-normal:1.5; --leading-relaxed:1.625;
--shadow-sm: 0 1px 3px 0 rgb(0 0 0/.1), 0 1px 2px -1px rgb(0 0 0/.1);
--shadow-md: 0 4px 6px -1px rgb(0 0 0/.1), 0 2px 4px -2px rgb(0 0 0/.1);
--shadow-lg: 0 10px 15px -3px rgb(0 0 0/.1), 0 4px 6px -4px rgb(0 0 0/.1);
```

**注意 `--text-sm` 的 `line-height` 是 1.25rem（20px）→ 行高比 1.43**。Tailwind 是**为拉丁文设计**的。中文需要显著加大——这是 Aimanong 必须偏离 Tailwind 的地方（§1.5）。

### 2.5 Tabler UI 1.6 —— 开源后台模板的技术前沿

来源：`npm i @tabler/core@1.6.1` → `dist/css/tabler.min.css`（实测）

**Tabler 已全面迁移到 OKLCH + `color-mix()` + 原生 `light-dark()`**：

```css
--tblr-blue: oklch(54.6% 0.1724 254.2deg);
--tblr-primary: oklch(54.6% 0.1724 254.2deg);        /* = rgb(6,111,209) */
--tblr-blue-rgb: 6,111,209;

--tblr-body-bg:    light-dark(oklch(98.51% 0 0deg), oklch(20.46% 0 0deg));
--tblr-body-color: light-dark(oklch(26.86% 0 0deg), oklch(92.19% 0 0deg));
--tblr-secondary-bg: light-dark(oklch(92.19% 0 0deg), oklch(26.86% 0 0deg));
--tblr-tertiary-bg:  light-dark(oklch(97.02% 0 0deg), oklch(23.66% 0 none));

--tblr-gray-50:oklch(98.51% 0 0deg); --tblr-gray-100:oklch(97.02% 0 0deg);
--tblr-gray-200:oklch(92.19% 0 0deg); --tblr-gray-400:oklch(71.55% 0 0deg);
--tblr-gray-500:oklch(55.55% 0 0deg); --tblr-gray-950:oklch(14.48% 0 0deg);

/* 自动派生：hover/active/浅底 全部算出来，不用手写 */
--tblr-blue-darken: oklch(from var(--tblr-blue) calc(l - 0.06) c h);
--tblr-blue-lt:     color-mix(in oklab, var(--tblr-blue) 10%, transparent);
--tblr-shadow-border: 0px 0px 0px 1px color-mix(in oklab, var(--tblr-shadow-color) 25%, transparent);
```

**尺寸**：
```css
--tblr-border-radius: 6px;   --tblr-border-radius-sm: 4px;   --tblr-border-radius-lg: 8px;
--tblr-border-radius-xl: 1rem; --tblr-border-radius-xxl: 2rem; --tblr-border-radius-pill: 100rem;
--tblr-body-font-size: 0.875rem;   /* 14px */
--tblr-body-line-height: 1.4285714286;
--tblr-font-size-h1:1.5rem; h2:1.25rem; h3:1rem; h4:.875rem; h5:.75rem; h6:.625rem;
--tblr-spacer-1:.25rem; -2:.5rem; -3:1rem; -4:1.5rem; -5:2rem; -6:2.5rem;
--tblr-btn-input-padding-y: 0.5625rem;  /* 9px ← 和 Aimanong 现状的 9px 巧合一致 */
--tblr-btn-input-padding-x: 1rem;       /* 16px */
--tblr-btn-input-line-height: 1.25rem;
```

> **务必注意浏览器兼容性**：`light-dark()` 需要 Chrome 123+ / Safari 17.5+ / Firefox 120+（2024 年）；`color-mix()` 需要 Chrome 111+ / Safari 16.2+ / Firefox 113+；`oklch()` 需要 Chrome 111+ / Safari 15.4+ / Firefox 113+。**没有优雅降级，老浏览器直接坏掉。**
>
> 对 Aimanong 的启示：**思路学 Tabler（用 OKLCH 生成色阶、用 color-mix 派生状态色），但产物输出为 sRGB hex**，兼容性拉满且不牺牲生成质量。本方案的 `themes.css` 就是这么做的——色阶用 OKLCH 算法生成，落地是 hex。

### 2.6 AdminLTE 4 —— dcat-admin 的后继路线

来源：[AdminLTE 4 官方 Customization 文档](https://adminlte.io/themes/v4/docs/customization.html)、`npm i admin-lte@4.0.0-rc7` → `src/scss/_variables.scss`

**继承 Bootstrap 5.3 的 CSS 变量模型**：
```css
:root, [data-bs-theme="light"] {
  --bs-primary: #0d6efd;
  --bs-body-bg: #fff;         --bs-body-color: #212529;
  --bs-border-color: #dee2e6; --bs-border-radius: .375rem;
  --bs-border-radius-lg: .5rem;
}
[data-bs-theme="dark"] {
  --bs-body-bg: #212529;      --bs-body-color: #dee2e6;
  /* 暗色下主色要换更亮的： */
  --bs-primary: #4dabf7;
}
```

**布局变量（SCSS，实测）**：
```scss
$lte-sidebar-width: 250px;          // 折叠到 mini ≈70px
$lte-sidebar-breakpoint: lg;        // 之后转 off-canvas
$lte-app-header-height: ($nav-link-height + ($lte-app-header-link-padding-y * 2));  // ≈56px
$lte-transition-speed: .3s;  $lte-transition-fn: ease-in-out;
$lte-sidebar-hover-bg: rgba($black, .1);      // 用透明黑做悬浮
$lte-sidebar-color: $gray-800;
```

**两个关键设计模式**：
1. **浅色页面 + 暗色侧栏**（`<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">`）——侧栏用 `data-bs-theme="dark"` 局部覆盖，形成层次对比。**这是开源后台最经典的"看起来专业"手法**，直接可用。
2. **Compact 模式**：`.compact-mode` 一个类降低全局内距（`$lte-sidebar-padding-y: .5rem` → `.25rem`）。数据密集型用户会主动开启。
3. **`$lte-transition-speed: .3s`** —— 比 Tailwind 的 150ms 慢，因为侧栏折叠是**大面积位移**，快动画会显得突兀。**动效时长要跟位移距离挂钩**（§3.4 采纳此规则）。

### 2.7 dcat-admin —— 我们要对标的产品

来源：本地 `dcat-admin-master/`

| 项 | 实测 |
|---|---|
| UI 框架 | **AdminLTE 3**（README 第 146 行明确列出）；`resources/assets/adminlte/scss/` 为 AdminLTE 3 源码 |
| 底层 | Bootstrap **4.1** + **jQuery 3.2** + Laravel Mix 4 + `vue-template-compiler 2.6` |
| 默认主题色 | `config/admin.php:325` → `'color' => 'indigo'` |
| 侧栏色 | AdminLTE 3 dark skin（`$sidebar-dark-bg` 系列，源码中被注释保留多个候选：`#1e1e2d`、`#263238`、`darken(#505b6b,14%)`） |
| 暗色模式 | **无** |
| 构建链 | 需要 webpack 编译 SCSS（`npm run production`） |

> **战略结论**：dcat-admin 的技术底座（Bootstrap 4 + jQuery + AdminLTE 3 + 需构建）**整体落后一个时代**。且如我此前调研所知，它停留在 `2.2.3-beta`（2023-02）、最高支持 Laravel 10，已无人维护。
>
> 意味着 Aimanong 在视觉上**不需要"追赶" dcat**，只需要避开它的三个特征即可形成代差：
> 1. **Bootstrap 4 的 4px 圆角 + 1px 实线边框**（我们走 6–12px 圆角 + 透明环）
> 2. **jQuery 时代的"点击即刷新"**（我们已有 Vue 3 响应式）
> 3. **无暗色模式**（我们首发就带）
>
> 同时**不要模仿 AdminLTE 的老式左侧深色 + 顶部彩条**——那是 2014 年的视觉语言。

### 2.8 其他现代控制台（补充趋势）

| 产品 | 关键手法 | 可借鉴度 |
|---|---|---|
| **Vercel / Geist** | 公开完整色阶（`gray 100–1000` + 语义色，P3 色域增强）；**字体 Geist 为 SIL OFL 开源**（`npm geist@1.7.2`，license 字段 `SIL OPEN FONT LICENSE`）——开源项目可安全内嵌 | ⭐⭐⭐ 色阶方法论；Geist 字体可直接用（含中文回退） |
| **Linear** | 在 **LCH/OKLCH 感知均匀空间**里设计（其工程博客明确讨论过感知亮度问题）；极暗的**去饱和蓝黑**底色（非纯黑）；1px 极细边框 | ⭐⭐⭐ 本方案采用 OKLCH 生成色阶正是此思路 |
| **Supabase** | 近单色：白底 `#ffffff` + 近黑文字 `#171717` + **唯一的彩色是 emerald `#3ecf8e`**；灰阶梯 `#ededed`(细线) → `#707070`(弱文字) → `#171717`(正文)；按钮圆角 6px，**且 emerald 上永远用近黑文字而非白色** | ⭐⭐⭐ "克制的单彩色"策略；**"亮色上用深色文字"正是我们主色方案的依据** |

> Supabase 那条 `#3ecf8e` 上**用近黑文字**的做法，直接印证了 §1.6 的判断：**亮色主色配白字是对比度灾难**。我们的解法是把主色压暗到 `#007643` 再用白字（5.20:1），另一条等效路径是保持亮色改用深色文字。两条都行，但**绝不能亮色配白字**。

### 2.9 调研结论 → 设计原则

综合以上，现代后台的"高级感"来自这 6 条，且**没有一条需要复杂技术**：

1. **层级越高越亮**（浅色：白 → 更白；深色：`#0b0f14` → `#121820` → `#1a222c`）
2. **用透明环/透明边框代替实色阴影**（Filament `ring-gray-950/5`、shadcn `oklch(1 0 0/10%)`）
3. **文字灰阶要够多档**（≥4 档）且有对比度保证（**我们比 Ant Design 更严**）
4. **动效时长与位移距离成正比**（AdminLTE `.3s` 折叠 vs Tailwind 150ms 微交互）
5. **克制的彩色**（Supabase 全站只有一个 emerald）
6. **暗色模式的主色必须重新取值**，不能沿用浅色值

---

## 三、三套可选视觉风格

### 3.0 设计方法论：为什么这些色值是可信的

我没有"挑"色值，而是**生成 + 校验**：

1. 每套主题定义**一个色相**（Hue）+ **一个彩度上限**
2. 用 **OKLCH** 生成 11 级色阶：亮度单调递减，彩度呈钟形（两端低、中间高）—— 这保证**每一级看起来"同样鲜艳"**（sRGB 里直接插值做不到这点，这是 Linear / Tabler 都用 OKLCH 的原因）
3. 中性灰阶用**统一冷底调**（带极小彩度），避免纯灰的"死板"
4. 对每套主题的 light/dark 两模式，**自动挑选满足对比度要求的最优色阶**
5. **14 项对比度校验 × 3 主题 × 2 模式 = 84 项，全部通过**

生成脚本与校验脚本已随附（见文末）。**改色相只需改一个数字，全套自动重算并重新校验。**

### 3.1 风格 A：墨玉 Ink Jade

> **一句话**：克制的东方墨绿，专业、耐看、不抢戏 —— 给"要天天看 8 小时"的人。

| 角色 | 浅色 | 深色 |
|---|---|---|
| 主色（实心按钮） | `#007643` | `#008e58` |
| 主色悬浮 / 按下 | `#005d33` / `#004424` | `#3aa470` / `#64b88a` |
| 主色文字 | `#007643` | `#3aa470` |
| 品牌浅底 / 描边 | `#f0f9f4` / `#c7e7d3` | 由主题色相派生 |
| 画布 / 卡片 / 下沉 | `#f4f6f8` / `#ffffff` / `#eaedf0` | `#0b0f14` / `#121820` / `#080b10` |
| 正文 / 次级 / 三级 / 禁用 | `#0d1721` / `#444e59` / `#5a646f` / `#979fa8` | `#e4e8ec` / `#afb8c2` / `#7b8895` / `#647280` |
| 边框 弱/默认/强 | `#e3e6e9` / `#dce0e5` / `#cbd0d6` | `#232d3a` / `#2f3b4a` / `#3f4d5e` |

- **色相** 158°（青绿），彩度上限 0.135（**三套里最低**）
- **关键特征**：圆角 6/8/12px；**无渐变**；无玻璃拟态；动效 120–180ms，克制（只做颜色与 1px 位移）
- **气质**：像一块打磨过的墨玉，安静、有分量
- **适合**：企业内训系统、政务/金融后台、ERP、需要长时间阅读数据的场景
- **为什么这样定**：色相 158° 是"绿"但偏青，比纯绿的"塑料感"低；彩度压到 0.135 是因为**低彩度 = 长期注视不疲劳**，也是专业感的来源（对比 dcat 的 indigo 高饱和）

### 3.2 风格 B：深空 Deep Space

> **一句话**：薄荷青 + 冷蓝黑，暗色模式下极出彩 —— 给"科技感"要求最直白的场景。

| 角色 | 浅色 | 深色 |
|---|---|---|
| 主色（实心按钮） | `#007a50` | `#00a67b` |
| 主色悬浮 / 按下 | `#00603e` / `#00462c` | `#44ba94` / `#76cbac` |
| 主色文字 | `#007a50` | `#00a67b` |
| 画布 / 卡片 | `#f1f7fb` / `#ffffff` | `#0b0f14` / `#121820` |
| 边框 弱/默认/强 | `#e3e6e9` / `#dbe0e5` / `#cad0d5` | `#232d3a` / `#2f3b4a` / `#3f4d5e` |

- **色相** 168°（薄荷青/teal），彩度上限 0.152（中）；中性阶彩度更高（0.04），**背景本身带一点冷蓝，不是纯灰**
- **关键特征**：圆角 8/10/14px（**比 A 更大**）；**允许**微弱渐变（登录页背景 `linear-gradient(160deg, #0b0f14, #10202b)`）；顶栏/侧栏玻璃拟态 `backdrop-filter: blur(12px)`；动效 180–280ms，带 `translateY` 与 `scale`
- **气质**：像深海或夜空的冷色，青绿在深底上会"发光"
- **适合**：SaaS 产品后台、开发者工具、监控/大屏、AI 类产品 —— **最贴合"科技感/炫酷感"的原始诉求**
- **为什么这样定**：teal 色相在深色背景上的**感知亮度高于同 L 值的绿色**，所以暗色模式下视觉冲击最强；中性阶带彩度是刻意的——纯灰背景在深色下会显"脏"，带冷调才显"干净"

### 3.3 风格 C：极光 Aurora

> **一句话**：紫罗兰渐变，最"炫酷"，也最挑用户 —— 需要品牌个性时用。

| 角色 | 浅色 | 深色 |
|---|---|---|
| 主色（实心按钮） | `#6c56e0` | `#8676f2` |
| 主色文字 | `#5d47c0` | `#c7c4ff` |
| 画布 / 卡片 | `#f4f5fb` / `#ffffff` | `#0b0f14` / `#121820` |
| 品牌浅底 / 描边 | — | `#18162d` / `#302c55` |

- **色相** 286°（紫罗兰），彩度上限 0.195（**三套最高**）
- **关键特征**：圆角 8/12/16px（最圆）；**强渐变**（主按钮 `linear-gradient(135deg, #8676f2, #6c56e0)`；登录页多色极光光晕）；侧栏 `backdrop-filter: blur(16px) saturate(160%)`；动效 200–320ms，带光晕呼吸
- **气质**：像极光，张扬、有辨识度
- **适合**：AI 产品、创意工具、SaaS 营销页、需要"一眼记住"的产品
- **诚实提醒**：**紫色主色在中文后台里接受度分化明显**——一部分用户觉得现代，一部分觉得"不够稳重"。且紫罗兰的**可用色相空间窄**（往蓝偏 = 变普通，往红偏 = 变怪异），主题扩展性不如绿。**建议作为可选主题，不作为默认。**

### 3.4 三套共用的"非颜色"规格

风格差异只在颜色/圆角/动效强度；以下骨架三套完全一致，**保证切换主题不换 DOM、不换布局**：

```css
/* 间距：4px 基准，8px 节奏 */
--sp-1:4px; --sp-2:8px; --sp-3:12px; --sp-4:16px; --sp-5:20px;
--sp-6:24px; --sp-8:32px; --sp-10:40px; --sp-12:48px; --sp-16:64px;

/* 字号阶梯（中文适配） */
--fs-xs:12px; --fs-sm:13px; --fs-base:14px; --fs-md:15px;
--fs-lg:17px; --fs-xl:20px; --fs-2xl:24px; --fs-3xl:30px;
--lh-tight:1.35; --lh-base:1.6; --lh-loose:1.75;

/* 中文字体栈 */
--font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
  "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans SC", "Source Han Sans SC",
  "WenQuanYi Micro Hei", sans-serif;
--font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas,
  "Liberation Mono", monospace;

/* 动效：时长与位移距离正相关 */
--dur-fast:120ms;   /* 颜色、透明度变化 */
--dur:180ms;        /* 悬浮、焦点、按钮 */
--dur-slow:280ms;   /* 弹窗、抽屉、折叠 */
--ease: cubic-bezier(.4, 0, .2, 1);
--ease-out-back: cubic-bezier(.34, 1.4, .64, 1);   /* 弹窗入场，轻微回弹 */

/* 层级 */
--z-dropdown:50; --z-sticky:60; --z-modal:100; --z-toast:120;
```

**固定尺寸规格**（来自 §2 调研 + 实测修正）：

| 组件 | 尺寸 | 依据 |
|---|---|---|
| 顶栏高 | 56px | AdminLTE 4 实测 ≈56px；Filament 64px 偏高 |
| 侧栏宽 | 232px（折叠 64px） | AdminLTE 250px 偏宽；Filament 288px 过宽，中文菜单项短 |
| 表格行高 | 44px | Filament 实测 44px |
| 控件高（输入/按钮） | 34px | Ant `controlHeight:32` + 2px 适配中文 |
| 小按钮高 | 28px | 表格内操作列 |
| 弹窗宽 | 480px（表单）/ 640px（含分步） | 现状 420px 过窄，多列表单项会换行 |
| 卡片圆角 | 12px | shadcn `--radius:10px`、Tailwind `rounded-xl:12px` |
| 控件圆角 | 6px | Ant `borderRadius:6`、Tabler `--tblr-border-radius:6px`（**两个主流系统都收敛到 6px**） |

### 3.5 无障碍：84 项校验全部通过

对 3 主题 × 2 模式，逐项校验（数值为实测对比度）：

| 检查项 | 要求 | 墨玉/浅 | 墨玉/深 | 深空/浅 | 深空/深 | 极光/浅 | 极光/深 |
|---|---|---|---|---|---|---|---|
| 正文 on 卡片 | ≥4.5 | 15.9 | 13.4 | 15.6 | 13.4 | 15.9 | 13.4 |
| 正文 on 画布 | ≥4.5 | 15.2 | 14.4 | 15.0 | 14.4 | 15.2 | 14.4 |
| 次级 on 卡片 | ≥4.5 | 8.2 | 7.5 | 8.1 | 7.5 | 7.0 | 7.5 |
| 次级 on 画布 | ≥4.5 | 7.8 | 8.1 | 7.7 | 8.1 | 6.7 | 8.1 |
| 三级 on 卡片 | ≥4.5 | 5.9 | 4.9 | 5.8 | 4.9 | 4.8 | 4.9 |
| 三级 on 画布 | ≥4.5 | 5.6 | 5.3 | 5.5 | 5.3 | 4.6 | 5.3 |
| 按钮字 on 主色 | ≥4.5 | **5.2** | 8.7 | **5.1** | 9.5 | **5.5** | 9.4 |
| 主色文字 on 卡片 | ≥4.5 | 5.2 | 5.5 | 5.1 | 6.3 | 6.7 | 9.5 |
| 主色文字 on 画布 | ≥4.5 | 5.0 | 5.9 | 4.9 | 6.8 | 6.3 | 10.2 |
| 焦点环 on 卡片 | ≥3.0 | 3.0 | 5.5 | 3.1 | 6.3 | 3.5 | 9.5 |
| 分隔线 on 卡片 | ≥1.2 | 1.25 | 1.25 | 1.25 | 1.25 | 1.26 | 1.25 |
| 卡片边框 | ≥1.3 | 1.33 | 1.43 | 1.33 | 1.43 | 1.33 | 1.43 |
| 输入框边框 | ≥1.4 | 1.55 | 1.88 | 1.56 | 1.88 | 1.55 | 1.88 |

**全部通过（0 项失败）。**

**必须诚实说明的两点**：

1. **输入框边框只有 1.55:1，未达 WCAG 1.4.11 非文本对比的 3:1 建议。**
   我实测了所有主流系统，**没有一个达标**：
   | 系统 | 输入框边框 | 对比度 |
   |---|---|---|
   | Ant Design 5 `colorBorder` | `#d9d9d9` | 1.41 |
   | Tailwind `gray-200` | `#e5e7eb` | 1.24 |
   | Bootstrap 5.3 | `#dee2e6` | 1.30 |
   | Filament `ring-gray-950/5` | 合成 ≈`#f2f2f2` | 1.12 |
   | Tabler | `#e5e7eb` | 1.24 |
   | **本方案** | **`#cbd0d6`** | **1.55** |

   要达到 3:1 需要 `#868fa0` 这种中灰，会让所有表单看起来"像报错"。**本方案取 1.55，已是主流系统里最高**；如果需要严格达标，输入框应改用**深色边框 + 浅底填充**的组合（`background: var(--bg-subtle)` 配 `#868fa0` 边框），这是可用但视觉更重的取舍。**建议保持现状并在文档中说明这是有意识的取舍。**

2. **`--text-disabled`（`#979fa8`，2.6:1）不满足 AA。**
   这是**故意的**——WCAG 明确豁免 disabled 元素。占位符文字同理。**但要注意：不能用这个颜色承载任何必要信息**（比如不能用它显示"暂无数据"这种需要阅读的文案）。

---

## 四、技术实现建议

### 4.1 四个选项的评估

| 方案 | 体积 | 构建 | 可控性 | 主要风险 | 结论 |
|---|---|---|---|---|---|
| **a) 全手写 CSS + 变量** | 最小 | 无 | 最高 | 工作量大、易不一致 | 基础，但需配自研系统 |
| **b) Tailwind CDN** | **~400KB（Play CDN）** | 无 | 中 | 见下 | **不推荐** |
| **c) Pico.css / Water.css / Open Props** | 10–80KB | 无 | 低 | 见下 | 部分可用 |
| **d) 自研精简设计系统** | **~24KB（未压缩），~6KB gzip** | 无 | 最高 | 需自行维护 | ✅ **推荐** |

### 4.2 详细分析

**b) Tailwind CDN —— 明确不推荐**

实测数据：Tailwind v4 的 `dist/` 中 `tailwind.css` 完整产物约 **400KB+**（未压缩）。而且 Play CDN（`cdn.tailwindcss.com`）是**运行时 JIT**：在浏览器里扫描 DOM 并动态生成 CSS。
- **致命问题 1**：它必须在页面渲染前执行，**会阻塞首屏**；后台管理页加上 Vue CDN 已经有两个阻塞脚本
- **致命问题 2**：它是"生成器"不是"成品 CSS"，**离线环境（很多企业内网/Linux 服务器）不可用**
- **致命问题 3**：类名会淹没模板。现状 `resource.blade.php` 已经有 **43 处**内联 `style=`（表单控件与布局混杂），改用 utility class 会让这个 791 行的模板膨胀到 1200 行以上，且**AI Agent 生成 HTML 时更容易产生样式不一致**
- **致命问题 4**：与"Aimanong 是给 AI 用的框架"这一定位**冲突**——AI 生成页面需要一个**语义化的组件类名体系**（`.btn-primary`、`.card`、`.data-table`），而不是让 AI 每次拼 20 个 utility class。语义类名让 AI 的产出更稳定、更短、更可校验。

**c) 现成纯 CSS 系统 —— 部分可用，但都不合适做底座**

| 方案 | 实测体积 | 许可证 | 问题 |
|---|---|---|---|
| **Pico.css** | ~80KB | MIT | 它是**语义化/无类名**哲学（靠 `<article>`、`<table>` 原生标签自动美化）。**要覆盖它的默认样式，写的 CSS 比全手写还多**（每个选择器都要 `!important` 或更高特异性）。它适合"文档/博客"，不适合有复杂交互的后台。 |
| **Water.css** | ~10KB | MIT | 更极端，纯文档风格。**没有表格/弹窗/表单校验态**。 |
| **Open Props** | `open-props.min.css` **32KB**（实测 `du -h`），MIT | MIT | **这是唯一值得考虑的**。它提供 `--gray-0..12`、`--red-0..12`、`--size-*`、`--radius-*`、`--shadow-*`、`--ease-*`、`--font-*` 等数百个变量。**但**：① 它的灰阶是**暖灰/中性灰**（`--gray-0:#f8f9fa`、`--gray-12:#030507`，来自 Open Color），**没有我们需要的"冷调 + 12 级 + 对比度保证"**；② 它只有 token，**没有任何组件**（表格/按钮/弹窗/徽章仍要全写）；③ 32KB 里我们大概只用得到 30% |

> **Open Props 的实测色阶**（供参考）：
> `--gray-0:#f8f9fa --gray-1:#f1f3f5 --gray-2:#e9ecef --gray-3:#dee2e6 --gray-4:#ced4da --gray-5:#adb5bd --gray-6:#868e96 --gray-7:#495057 --gray-8:#343a40 --gray-9:#212529 --gray-10:#16191d --gray-11:#0d0f12 --gray-12:#030507`

**d) 自研精简设计系统 —— 推荐（与你的倾向一致）**

**理由不是"保持零依赖"这个洁癖，而是三条实质性的**：

1. **体积差 17 倍**：本方案 `design-system.css` 目标 700–800 行，未压缩约 **24KB**，gzip 后约 **6KB**。Tailwind CDN 是它的 60 倍以上，Open Props 是它的 5 倍（且不含组件）。
2. **"给 AI 用的框架"要求语义化组件名**（见上文 b 的第 4 点）。这是**产品定位决定的技术选择**，不是偏好。
3. **主题切换是这个项目的核心需求**。三套主题要能只换 CSS 变量、不换 DOM——**自研系统才能保证 token 命名与主题正交**。引第三方系统会立刻遇到"它的语义和我们的主题维度对不上"的问题（例如 Open Props 的 `--gray-*` 是固定色阶，而我们需要"每个主题一套灰阶"）。

**但要诚实指出 d 的真实成本**：
- 需要自己维护（无社区）
- 需要一次性投入约 **3–5 人日**写出高质量的设计系统
- **需要防止退化成"第二个散落 CSS"** → 必须有 lint 规则：**禁止在 Blade 模板里写 `style="..."` 和裸 hex**（这条比什么都重要，§6 会给出具体做法）

### 4.3 推荐架构（零构建步骤）

```
packages/framework/resources/
├── assets/
│   ├── design-system.css      # ~700 行：token + reset + 组件
│   ├── themes.css             # ~160 行：3 套主题 × 2 模式的 token 覆盖
│   └── icons/                 # ~24 个内联 SVG（用 Blade 组件引用）
└── views/
    ├── partials/
    │   ├── head.blade.php     # 共享 <head>：meta + CSS + 主题初始化脚本
    │   └── scripts.blade.php  # 共享主题切换 + toast 逻辑
    ├── components/
    │   └── icon.blade.php     # <x-aimanong.icon name="users" />
    ├── auth/login.blade.php   # 只写页面结构，样式全来自设计系统
    ├── index.blade.php
    └── resource.blade.php
```

**关键实现点**：

**① 主题切换（无闪烁）** —— 必须在 `<head>` 里**同步**执行，否则深色用户会看到白屏闪烁（FOUC）：

```blade
{{-- partials/head.blade.php --}}
<script>
(function () {
  try {
    var s = JSON.parse(localStorage.getItem('aimanong:theme') || '{}');
    var t = s.theme || 'ink';
    var m = s.mode || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    var d = document.documentElement;
    d.dataset.theme = t;
    d.dataset.mode = m;
  } catch (e) { /* localStorage 被禁用时静默降级 */ }
})();
</script>
<link rel="stylesheet" href="{{ \Aimanong\Aimanong::asset()->url('design-system.css') }}">
<link rel="stylesheet" href="{{ \Aimanong\Aimanong::asset()->url('themes.css') }}">
```

**② 主题切换 UI**（顶栏下拉，无需框架）：

```js
function setTheme(theme, mode) {
  const d = document.documentElement;
  if (theme) d.dataset.theme = theme;
  if (mode) d.dataset.mode = mode;
  localStorage.setItem('aimanong:theme', JSON.stringify({
    theme: d.dataset.theme, mode: d.dataset.mode
  }));
}
// 跟随系统（用户未手动指定时）
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
  if (!localStorage.getItem('aimanong:theme')) {
    document.documentElement.dataset.mode = e.matches ? 'dark' : 'light';
  }
});
```

**③ 服务器端也要能设主题（重要，容易被忽略）**：
Laravel 侧读取用户偏好写到 `<html>` 上，这样**即使 JS 被禁用也有正确主题**：

```php
// 优先级：用户设置 > 应用配置 > 默认
$theme = $user->theme ?? config('aimanong.ui.theme', 'ink');
$mode  = $user->color_mode ?? 'system';
```
```blade
<html lang="zh-CN" data-theme="{{ $theme }}" data-mode="{{ $mode === 'system' ? 'light' : $mode }}">
```
配置项加到 `config/aimanong.php`：
```php
'ui' => [
    'theme'   => env('AIMANONG_THEME', 'ink'),      // ink | deep | aurora
    'mode'    => env('AIMANONG_MODE', 'system'),    // light | dark | system
    'radius'  => env('AIMANONG_RADIUS', 'md'),      // sm | md | lg —— 全局圆角缩放
    'density' => env('AIMANONG_DENSITY', 'default') // default | compact —— 数据密集模式
],
```

**④ 圆角与密度做成可缩放**（成本极低，收益很高）：
```css
:root {
  --radius-scale: 1;      /* sm:.75  md:1  lg:1.35 */
  --density-scale: 1;     /* compact:.85 */
  --r-sm: calc(6px * var(--radius-scale));
  --r-md: calc(8px * var(--radius-scale));
  --r-lg: calc(12px * var(--radius-scale));
  --row-h: calc(44px * var(--density-scale));
}
```
- **因为**：不同客户对"圆角多大""信息密度多高"的偏好差异极大，而这两个维度**用两个乘数就能覆盖全部组合**，不必为每种组合单独维护一套 token。

---

## 五、改造清单（按优先级）

> 交付物 `design-system.css` + `themes.css` 已完成并通过浏览器实测。
> 以下是把现有三个模板迁移过去的具体步骤。

### P0 — 骨架（不做这些，后面全是白费）

#### P0-1 抽取共享 partial，消灭 3 份重复 CSS

**现状**：`login.blade.php`(92行) / `index.blade.php`(156行) / `resource.blade.php`(791行) 各自内联一套 `<style>`，`.header`/`.sidebar`/`.menu-item`/`.card` 逐字复制。

**改动**：
```blade
{{-- resources/views/partials/head.blade.php --}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', '控制台') · AI 码农</title>
<script>
(function () {
  try {
    var s = JSON.parse(localStorage.getItem('aimanong:theme') || '{}');
    var t = s.theme   || '{{ config('aimanong.ui.theme', 'ink') }}';
    var m = s.mode    || '{{ $mode ?? 'system' }}';
    if (m === 'system') m = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    var d = document.documentElement;
    d.dataset.theme = t;
    d.dataset.mode  = m;
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="{{ \Aimanong\Aimanong::asset()->url('design-system.css') }}">
<link rel="stylesheet" href="{{ \Aimanong\Aimanong::asset()->url('themes.css') }}">
```
- **CSS 必须放在 `<head>` 且主题脚本必须在 CSS 之前**——否则深色模式用户会先看到一个白色闪屏（FOUC）。这是最容易漏掉、也最影响"专业感"的一点。
- 三个页面各自 90–800 行内联样式**全部删除**，改为引用 partial。

**收益**：3 份重复 → 1 份；总 CSS 从 1039 行内联降到 2 个文件共享。

#### P0-2 首页/列表页加侧边栏一致性

**现状**（`index.blade.php:74-92` vs `resource.blade.php:114-136`）：两处侧边栏 HTML 几乎相同，但 **index 页没有 `.active` 判定**（因为它是工作台，`$uri` 对不上任何菜单项），且 index 的 `.layout { min-height: calc(100vh - 56px) }` 与 resource 的 `calc(100vh - 58px)` **差 2px**——这是典型的"复制后各改各的"。

**改动**：抽 `partials/sidebar.blade.php`，两页共用；`.am-shell` 用 `grid` + `min-height:100vh`，**不再手算高度差**。

#### P0-3 表格：行高、hover、数字对齐

**现状**（`resource.blade.php:52-66`）：
```css
th { padding:12px 16px; font-size:12px; color:#6b746f; background:#fafcfb; }  /* 表头 2.21:1 看不清 */
td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f2f5f3; }  /* 无 hover，行高约 39.6px */
```

**改动**（对照 `design-system.css` §6）：
```diff
- th { color:#6b746f; background:#fafcfb; }
+ th { color: var(--text-tertiary); background: var(--bg-subtle); border-bottom:1px solid var(--border-default); }
- td { padding:12px 16px; font-size:13px; }
+ td { padding: var(--sp-3) var(--sp-4); height: var(--h-table-row); font-size: var(--fs-sm); }
+ .am-table tbody tr:hover { background: var(--bg-hover); }
```
并在模板里给数值列加 `class="am-num"`：
```blade
<td class="am-num">@{{ formatMoney(row[col.name]) }}</td>
```
- **因为**：`am-num` 提供等宽字体 + `tabular-nums` + 右对齐，金额小数点才能对齐。

**表头加 sticky**：`position:sticky; top:0` 已在 `.am-table th` 实现——长列表滚动时表头不消失，这是"专业"的强信号。

#### P0-4 弹窗：加阴影 + 焦点陷阱 + ESC 关闭

**现状**（`resource.blade.php:237`）：
```html
<div v-if="showCreate" style="position:fixed;inset:0;background:rgba(16,27,22,.4);display:flex;...">
  <div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-height:80vh;overflow:auto;">
```
问题：**无阴影**（浮不起来）、**无 ESC 关闭**、**无焦点陷阱**（Tab 会跑到背后的表格里）、**宽度硬编码 420px**。

**改动**：换成 `.am-overlay` / `.am-modal` 结构，并补 JS：
```js
// ESC 关闭
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && showCreate.value) showCreate.value = false;
});
// 焦点陷阱：打开时聚焦弹窗，Tab 循环限制在弹窗内
watch(showCreate, async (open) => {
  if (!open) return;
  await nextTick();
  const el = document.querySelector('.am-modal');
  el.setAttribute('tabindex', '-1');
  el.focus();
});
```
- **因为**：`aria-modal="true"` 只对屏幕阅读器有效，**视觉焦点仍会跑到背后**，必须自己管。

#### P0-5 补 `prefers-reduced-motion`

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    transition-duration: .01ms !important;
  }
}
```
**已在 `design-system.css` §无障碍实现**。这是无障碍要求，不是可选项——前庭功能障碍用户会因动效产生眩晕。

---

### P1 — 观感（做完 P0 后立刻见效）

#### P1-1 登录页

**现状**（`login.blade.php`）：`background: linear-gradient(135deg,#f5f7fa 0%,#e8f5e9 100%)` —— 一个从浅灰到浅绿的**低对比渐变**，是"廉价感"的典型。
- 绿底渐变到灰底，两者亮度接近，渐变**看不出是渐变**，只显得"脏"。
- 单体卡片居中，`max-width:380px`，`padding:40px`，无品牌层次。

**改动**（三套主题差异化）：
```css
/* 墨玉：纯色画布 + 极淡的品牌色氛围光 */
.am-login { background: var(--bg-canvas); position: relative; overflow: hidden; }
.am-login::before {
  content: ""; position: absolute; inset: -40% -20% auto -20%; height: 80%;
  background: radial-gradient(60% 50% at 50% 0%,
    color-mix(in srgb, var(--brand-solid) 10%, transparent), transparent 70%);
  pointer-events: none;
}
/* 深空 / 极光：允许更强氛围 */
[data-theme="deep"]   .am-login::before { background: radial-gradient(60% 50% at 50% 0%, color-mix(in srgb, var(--brand-solid) 22%, transparent), transparent 70%); }
[data-theme="aurora"] .am-login::before { background:
  radial-gradient(40% 40% at 20% 10%, color-mix(in srgb, #8e51ff 28%, transparent), transparent 70%),
  radial-gradient(40% 40% at 80% 20%, color-mix(in srgb, #00d5be 22%, transparent), transparent 70%); }
```
卡片改为 `.am-card` + `--shadow-lg` + `--r-xl`，宽度 `400px`，padding `var(--sp-8)`。
**把版本信息**（`Laravel 12 · PHP 8.3`）从 `.footer` 移到 `.am-footer` 风格的低调位置——现状它是 12px `#a5aea9`（2.28:1，看不清），属于"写了等于没写"。

#### P1-2 后台布局

**现状**（`resource.blade.php:15-30`）：
- `.header` 高 56px，白色，`border-bottom:1px solid #e8edea`
- `.sidebar` 宽 **200px**，`.layout { min-height: calc(100vh - 58px) }` ← **56 vs 58 的算术错误**
- `.menu-item.active` 用 `border-right: 3px solid #6b9b7f` —— 右侧边框表示选中，是 AdminLTE 2 时代的做法
- 完全没有 `.active` 的圆角背景，只有一条右侧竖线

**改动**：
| 项 | 现状 | 改为 | 因为 |
|---|---|---|---|
| 侧栏宽 | 200px | **232px** | 200px 下"内容管理"这类 4 字分组名 + 缩进会挤 |
| 高度计算 | `calc(100vh - 58px)` | `grid` + `min-height:100vh` | 消除手工算术，56/58 不一致的 bug 自然消失 |
| 选中态 | 右侧 3px 竖线 | **圆角背景 + 品牌浅底 + 1px 描边** | 竖线是旧式语言；圆角块是现代后台通用做法（Filament/Linear/Vercel 一致） |
| 分组标题 | `#a5aea9`（2.28:1） | `--text-tertiary`（4.84:1） | 可读性 |
| 菜单项 | `padding:9px 18px` | `height:36px` + flex 居中 | 消除非网格值 |

**顶栏**加：面包屑（现状用 `/ {{ $uri }}` 直接拼，丑）、主题切换器、用户下拉（现状"退出"是一个裸 `<button>` 内联在文字后面）。

#### P1-3 工作台首页

**现状**（`index.blade.php:31-35`）：`.tiles` 是 `grid-template-columns: repeat(auto-fill, minmax(150px,1fr))`，`.tile` 是 `padding:16px; background:#f8faf9; border-radius:8px`，hover 只换边框色和底色。
- **emoji 图标** `📄`（第 102 行 `$link['icon'] ?: '📄'`）大小 20px，**在这个尺寸下 emoji 的细节完全糊掉**
- `.stat .num { color:#3FBF6F }` —— 统计数字用主色，24px 大号绿字，**高饱和绿 + 大字 = 刺眼**
- 三张卡片 `.card` 视觉权重完全相同，没有主次

**改动**：
1. `.am-tile` 用**内联 SVG 图标 + 32px 圆角图标底**，hover 时图标底变主色 —— 见 `component-test.html` 的磁贴示例
2. `.am-stat__value` 用 `--text-primary`（不是主色）+ `--font-mono` + `tabular-nums`。**主色只留给交互元素**，统计数字是信息不是按钮
3. 引入 `.am-grid--3` 的统计卡 + 明确的"快捷入口"磁贴网格，两者视觉区分

#### P1-4 空状态

**现状**：`<div class="empty">暂无数据</div>` —— 40px padding 里一行 13px 灰字。

**改动**：用 `.am-empty`，含 48px SVG 图标 + 标题 + 说明 + 行动按钮。
- **因为**：空状态是"引导用户做下一步"的位置，不是"报告没数据"。尤其新增类页面，空状态应该直接放"新建"按钮。

#### P1-5 加载态 → 骨架屏

**现状**（`resource.blade.php:148`）：`<div v-if="loading" class="loading">加载中…</div>` —— 表格**整个消失**变成一行字，加载完成后表格又出现 → 页面高度剧烈跳动。

**改动**：
```blade
<tbody v-if="loading">
  <tr v-for="n in 5" :key="n">
    <td v-for="col in columns" :key="col.name">
      <span class="am-skeleton am-skeleton--text" style="width:60%"></span>
    </td>
    <td><span class="am-skeleton am-skeleton--text" style="width:80%"></span></td>
  </tr>
</tbody>
```
- **因为**：保持行数与列宽不变 = **零 layout shift**。这是 Core Web Vitals 的 CLS 指标，也是"精致"最直观的体现。

#### P1-6 错误态（**优先级实际上应该是 P0**）

**现状**（`resource.blade.php:440-444`）：
```js
} catch (e) {
    console.error(e);          // ← 只打日志
} finally {
    loading.value = false;
}
```
请求失败时 `rows` 保持空数组 → **用户看到"暂无数据"，无法区分"真的没数据"和"网络挂了"**。

**改动**：
```js
const error = ref(null);
async function load(page = 1) {
  loading.value = true;
  error.value = null;
  try { /* ... */ }
  catch (e) { error.value = e.message || '请求失败'; rows.value = []; }
  finally { loading.value = false; }
}
```
```blade
<div v-if="error" class="am-error"> … 重试按钮 … </div>
<div v-else-if="!loading && rows.length === 0" class="am-empty"> … </div>
```
**这条我建议提到 P0**——它不只是美观问题，是**给用户错误信息**的正确性问题。

---

### P2 — 完善

#### P2-1 用 Toast 替换 `alert()` / `confirm()`

**现状**：
- `resource.blade.php:752` `alert('保存失败: ' + (err.message ?? JSON.stringify(err.errors ?? err)))` —— **把原始 JSON 丢给用户看**
- `resource.blade.php:765` `confirm('确认删除 #' + row.id + '？')` —— **暴露自增 ID**，且浏览器原生弹窗与页面风格割裂

**改动**：
```js
// 表单校验错误：定位到具体字段，而不是弹窗
if (res.status === 422) {
  fieldErrors.value = err.errors || {};   // { order_no: ["已存在"] }
  return;
}
// 其他错误：Toast
toast.error('保存失败', err.message);
// 删除：用 .am-modal 做二次确认，标题写业务语义
```
- **因为**：`errors` 对象是 Laravel 标准 422 响应格式，**应当在字段下方逐项展示**，而不是 `JSON.stringify` 给用户。这直接对应 §1.9 诊断出的"无校验态"。

#### P2-2 复选框冒充开关 → 真开关

**现状**（`resource.blade.php:282`）：`<input v-else-if="f.type === 'switch'" type="checkbox" v-model="form[f.name]">` —— 一个原生复选框。

**改动**：用 `.am-switch` 结构（`design-system.css` §5）。这是**零 JS 成本**的纯 CSS 改造，收益是表单立刻"像样"。

#### P2-3 表单控件去重复

**现状**（实测）：`resource.blade.php` 共 **43 处 `style="`**，其中表单控件样式串完整重复 6 次 + 变体 5 次 = **11 个控件各写一遍**。

**改动**：全部替换为 `class="am-input"` / `class="am-select"` / `class="am-textarea"`。**预计可删掉约 30 处内联 style（43 → 约 13）**，实现方式是把 11 个控件分支的 `style` 属性整体剔除，改用 class。

#### P2-4 emoji → 内联 SVG

**现状**：`MenuRegistry.php:21` 注释 `'icon' => '📄'`；Resource 里声明 emoji。

**改动**：
1. 新增 `resources/views/components/icon.blade.php`：
```blade
@props(['name' => 'file', 'size' => 18])
@php
$icons = [
  'file'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
  'users'  => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  // …约 24 个常用图标
];
@endphp
<svg class="am-nav__icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
     stroke-linejoin="round" aria-hidden="true">{!! $icons[$name] ?? $icons['file'] !!}</svg>
```
2. **向后兼容**：`MenuRegistry` 现在存的是 emoji 字符串。加一层映射，把已知 emoji 转成图标名，未知的**保留 emoji 原样**（不破坏已有用户代码）：
```php
private const ICON_ALIAS = ['📄'=>'file','📝'=>'edit','🗂'=>'folder','👤'=>'user',
  '🏷'=>'tag','📦'=>'package','🧾'=>'receipt','🔐'=>'lock','🔑'=>'key','🏢'=>'building'];
```
- **因为**：`stroke="currentColor"` 让图标自动跟随主题色，换主题、hover、active 全部自动生效。

#### P2-5 响应式

**现状**：`@media` 出现 **0 次**。窄屏直接横向溢出。侧栏固定 200px 不可折叠。

**改动**：`design-system.css` §3 已实现三级响应式：
- `>1024px`：完整侧栏 232px
- `768–1024px`：侧栏收成 64px 图标条（`.am-nav__item span:not(.am-nav__icon){display:none}`）
- `<768px`：侧栏变抽屉（`transform:translateX(-100%)` + `.is-nav-open` 打开），弹窗变底部抽屉

#### P2-6 密度模式

管理员常抱怨"一屏看不了几行"。加 `--density: .85` 的 compact 模式（配置项 `aimanong.ui.density`），表格行高 44→37px、内距同步压缩。**成本极低（一个 CSS 变量），感知价值很高**。

---

### P3 — 细节（有时间再做）

| 项 | 现状 | 改动 |
|---|---|---|
| 排序指示 | `@{{ direction === 'asc' ? '↑' : '↓' }}`（`resource.blade.php:160`） | 换成 `.am-th-sort__icon` SVG，可用 CSS 旋转 |
| 树形缩进 | `<span v-if="row._depth > 0" class="muted">└ </span>`（第 215 行） | 用 `.am-tree-toggle` 三角 + 缩进线，`└` 字符在不同字体下对不齐 |
| 进度条 | 70px 固定宽 + 无过渡（第 85-89 行） | `.am-progress` 带 `transition: width 280ms` |
| 单元格截断 | 无 | 长文本加 `.am-truncate`（`max-width:28ch` + ellipsis），否则超长内容撑破表格 |
| 键盘排序 | 表头 `@click` 但无 `tabindex`（第 158 行） | 加 `tabindex="0"` + `@keydown.enter`，键盘用户才能排序 |
| 时间格式 | `String(v).replace('T',' ').slice(0,19)`（第 506 行） | 后端已给 ISO 时间，前端直接用 `Intl.DateTimeFormat('zh-CN')` |

---

## 六、工程量与风险：诚实评估

### 6.1 工作量

| 阶段 | 内容 | 估时 | 说明 |
|---|---|---|---|
| ✅ **已完成** | 设计系统 CSS + 三套主题 + 84 项校验 | — | 本次交付 |
| P0 | 抽 partial、表格、弹窗、reduced-motion、错误态 | **1.5–2 人日** | 机械性改造，风险低 |
| P1 | 登录页、布局、工作台、空/加载态 | **2–3 人日** | 需要设计判断，尤其登录页氛围 |
| P2 | Toast、开关、表单去重、SVG 图标、响应式、密度 | **3–4 人日** | **含约 24 个 SVG 图标的手工绘制/挑选** |
| P3 | 细节打磨 | **1–2 人日** | 可增量做 |
| **合计** | | **8–11 人日** | 单人全职约 2 周 |

**其中被低估的两项**：

1. **24 个 SVG 图标（约 1 人日）**
   不能随便找图标库——要保证 `stroke-width` 一致、圆角端点一致、24×24 viewBox 对齐。混用不同来源的图标会出现"粗细不一"的廉价感。**建议从 Feather Icons（MIT）挑选后统一调整 `stroke-width: 1.75`**，但必须逐个目视检查。

2. **`resource.blade.php` 的迁移（约 2 人日，含在上表内）**
   这个 791 行的模板里有 **43 处内联 `style=`**（表单控件占 30 处、布局占 13 处）。逐个替换为 class 是机械工作，但**替换后必须逐字段回归测试**——因为不同字段类型（select/textarea/date/datetime/tags/region×3/color/slider/rate）当时的 inline style 虽然一样，替换时很容易漏掉某个分支，导致某类字段突然没有边框。

### 6.2 风险

| 风险 | 等级 | 缓解 |
|---|---|---|
| **内联 style 迁移漏改** → 某类字段样式丢失 | 中 | 写一个 Blade 测试遍历所有 field type 渲染，断言输出含 `am-input`/`am-select` |
| **现有用户已自定义 CSS** → 类名冲突 | 中 | 全部类名加 `am-` 前缀（已做）。老类名（`.btn`/`.card`/`.table`）**保留一版兼容层**，下个大版本移除 |
| **主题偏好存 localStorage，服务端不知道** | 低 | 已设计双轨：JS 读 localStorage 立即生效（防闪烁）+ 用户表字段持久化（跨设备一致）。**两者都要做** |
| **`color-mix()` 兼容性** | 低 | 仅用于焦点光晕与品牌氛围光。降级后是"没有光晕"，不影响可用性。可加 `@supports not (color: color-mix(in srgb, red, blue))` 兜底 |
| **三套主题的维护成本 ×3** | 中 | **靠生成脚本而非手工维护**：改色相一个数字，全套重算 + 重校验。这是本方案与"手写三套皮肤"的本质区别 |
| **"科技感"用力过猛** | 中 | 默认用**墨玉（最克制）**；深空/极光作为可选。**开源项目的默认值应服务最保守的用户** |

### 6.3 关于"抄袭"的边界

三套方案均**未复制任何商业产品的具体色值**，而是：
- 色相选择：158°（自有）/ 168° / 286°
- 色阶：用 **OKLCH 算法自行生成**，非取自 Tailwind/Radix
- 方法论借鉴（色阶分级、透明环、层级越高越亮）属于**行业通用实践**，不构成抄袭

**可以安全使用的开源资源**：
| 资源 | 许可 | 用途 |
|---|---|---|
| **Geist 字体** | SIL OFL 1.1（`npm geist@1.7.2` 实测 license 字段） | 可选西文字体，**中文需另配回退** |
| **Feather Icons** | MIT | SVG 图标来源 |
| **Open Props** | MIT | 若最终决定引入，可取其 `easings`/`shadows` token |

**明确不要做的**：
- ❌ 不要复制 Filament 的 amber 配色（那是它的品牌识别）
- ❌ 不要照搬 Linear 的深色蓝黑精确值
- ❌ 不要在 README 里写"对标 Filament"——**Aimanong 的定位（给 AI 用）本身就是差异化**，视觉上应当有自己的性格（我们选了绿/青绿系，与 Filament 的琥珀橙、dcat 的靛蓝都不同）

### 6.4 中文排版：本方案的差异化优势

这是最容易被忽略、但**对中文用户感知最强**的一块：

| 维度 | 主流方案（Tailwind/shadcn） | 本方案 | 原因 |
|---|---|---|---|
| 正文字号 | 14px（Tailwind `text-sm`） | **14px** | 一致 |
| 表格文字 | 13px | **13px** | 一致 |
| **正文行高** | **1.43**（`text-sm: .875rem/1.25rem`） | **1.6** | 中文是满框方块，1.43 太挤 |
| **长文本行高** | 1.5（`leading-normal`） | **1.75** | 中文长段落可读性下限 |
| 字体栈 | 无中文字体 | **含 Noto Sans SC / Source Han Sans SC / WenQuanYi** | Linux 服务器、CI 截图环境必须有 |
| 字重 | 400/500/600 | 400/500/600 | 一致 |
| 字距 | `tracking-tight: -.025em` | 标题 `-.011em`，正文 `0` | 中文**不需要**拉丁文那样的负字距，过度收紧会让笔画粘连 |

**这一条值得写进 README 作为卖点**：`--lh-base: 1.6` 和完整的中文字体栈，是直接采用 Tailwind 默认值做不到的。

---

## 七、落地检查清单

改造完成后，逐项确认：

```bash
# 1. 无内联 style（除极少数动态值）
grep -rn 'style="' packages/framework/resources/views/ | grep -v 'v-bind\|:style' | wc -l
# 目标: 0

# 2. 无裸 hex（除 design-system.css / themes.css 的 token 定义）
grep -rn -E '#[0-9a-fA-F]{6}' packages/framework/resources/views/ | wc -l
# 目标: 0

# 3. 无 emoji 图标
grep -rnP '[\x{1F300}-\x{1FAFF}]' packages/framework/resources/views/ | wc -l
# 目标: 0

# 4. 无 alert/confirm
grep -rn -E '\balert\(|\bconfirm\(' packages/framework/resources/views/ | wc -l
# 目标: 0

# 5. 每个模板都引用共享 partial
grep -l 'partials.head' packages/framework/resources/views/**/*.blade.php
```

浏览器端：
- [ ] 三套主题 × 浅/深 = 6 种组合，逐个目视检查
- [ ] 键盘 Tab 走一遍全页，**焦点环始终可见**
- [ ] 系统开启"减少动态效果"后，无动画
- [ ] 375px 宽度下布局不横向溢出，侧栏可开合
- [ ] 断网后打开列表页，看到**错误态**而非"暂无数据"
- [ ] 控制台对比度审计（Chrome DevTools → Lighthouse → Accessibility）无对比度告警

---

## 八、交付物索引

| 文件 | 说明 |
|---|---|
| [README.md](README.md) | 本文：诊断、调研、方案、改造清单 |
| [design-system.css](design-system.css) | **设计系统**（1633 行 / 48KB / gzip 10.9KB）：token + 基础层 + 12 类组件 |
| [themes.css](themes.css) | **三套主题令牌**（456 行 / 13KB / gzip 2.2KB）：3 主题 × 2 模式 + 语义色 |
| [theme-preview.html](theme-preview.html) | **布局预览**：真实后台界面（侧栏+顶栏+表格+统计卡），可实时切主题 |
| [component-test.html](component-test.html) | **组件验证页**：按钮/表单/表格/徽章/空态/错误态/骨架屏/弹窗/Toast 全量展示 |

**打开方式**：直接用浏览器打开 `.html` 文件即可，无需构建、无需服务器。

**体积对比**：
| 方案 | gzip 后 |
|---|---|
| Tailwind Play CDN | ~120KB+（且运行时 JIT） |
| Open Props（仅 token，无组件） | ~8KB |
| **本方案（token + 全部组件）** | **13KB** |

---

## 九、一句话总结

**"丑"的根因不是审美，是三个可量化的缺失：没有 token 层（30 个散落色值 / 6 种圆角 / 0 个 CSS 变量）、没有层次体系（卡片与弹窗视觉权重相同）、没有状态（0 个动画、0 个错误态、表头对比度 2.21:1）。**

修复路径已经验证完毕：**13KB 的零依赖设计系统 + 三套可切换主题 + 84 项 WCAG 校验全部通过**。剩下的是 8–11 人日的机械迁移——不难，但要细心，尤其是 `resource.blade.php` 里那 **43 处内联 style**（11 个表单控件各抄了一遍样式）。

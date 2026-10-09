<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# Resource 详情

> 由 `php artisan aimanong:docs` 自动生成。

## SEO 元数据

- URI：`seo-meta`
- 模型：`App\Models\Course`
- 类：`App\Aimanong\Extensions\Seo\SeoMetaResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID |  |  | `badge` |
| `title` | 页面标题 |  | ✓ |  |
| `course_no` | 关联编号 |  |  |  |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|

## 用户

- URI：`users`
- 模型：`App\Models\User`
- 类：`App\Aimanong\UserResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 姓名 |  | ✓ |  |
| `email` | 邮箱 |  | ✓ |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 姓名 | `text` | ✓ | `required`, `max:255` |
| `email` | 邮箱 | `email` | ✓ | `required`, `email` |
| `status` | 状态 | `select` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

## 订单

- URI：`orders`
- 模型：`App\Models\Order`
- 类：`App\Aimanong\OrderResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `order_no` | 订单号 |  | ✓ |  |
| `customer_name` | 客户名称 |  | ✓ |  |
| `amount` | 金额 | ✓ |  |  |
| `status` | 状态 |  |  |  |
| `paid_at` | 支付时间 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `order_no` | 订单号 | `text` | ✓ | `required`, `max:255` |
| `customer_name` | 客户名称 | `text` | ✓ | `required`, `max:255` |
| `amount` | 金额 | `decimal` | ✓ | `required` |
| `status` | 状态 | `text` | ✓ | `required`, `max:255` |
| `paid_at` | 支付时间 | `datetime` |  |  |

## 工单

- URI：`tickets`
- 模型：`App\Models\Ticket`
- 类：`App\Aimanong\TicketResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `ticket_no` | 工单号 |  | ✓ |  |
| `subject` | 主题 |  | ✓ |  |
| `priority` | 优先级 | ✓ |  | `map` |
| `assignee` | 处理人 |  |  |  |
| `resolved` | 已解决 |  |  | `map` |
| `closed_at` | 关闭时间 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `ticket_no` | 工单号 | `text` | ✓ | `required`, `max:255` |
| `subject` | 主题 | `text` | ✓ | `required`, `max:255` |
| `body` | 内容 | `textarea` |  |  |
| `priority` | 优先级 | `select` |  |  |
| `assignee` | 处理人 | `text` |  | `max:255` |
| `resolved` | 已解决 | `switch` |  |  |
| `closed_at` | 关闭时间 | `datetime` |  |  |

## 字段演示

- URI：`demo-fields`
- 模型：`App\Models\Ticket`
- 类：`App\Aimanong\DemoResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID |  |  | `badge` |
| `ticket_no` | 工单号 |  | ✓ |  |
| `priority` | 优先级 |  |  | `map` |
| `resolved` | 是否解决 |  |  | `bool` |
| `closed_at` | 关闭时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `基本信息` | 基本信息 | `divider` |  |  |
| `ticket_no` | 工单号 | `text` | ✓ | `required` |
| `subject` | 主题 | `text` | ✓ | `required` |
| `priority` | 优先级 | `radio` |  |  |
| `body` | 内容 | `textarea` |  |  |
| `处理信息` | 处理信息 | `divider` |  |  |
| `assignee` | 处理人 | `text` |  |  |
| `closed_at` | 关闭日期 | `date` |  |  |
| `resolved` | 是否解决 | `switch` |  |  |

## 课程

- URI：`courses`
- 模型：`App\Models\Course`
- 类：`App\Aimanong\CourseResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `course_no` | 课程编号 |  | ✓ |  |
| `title` | 课程标题 |  | ✓ |  |
| `summary` | 课程简介 |  |  |  |
| `price` | 价格 | ✓ |  | `money` |
| `lessons` | 课时数 | ✓ |  |  |
| `level` | 难度级别 |  |  | `map` |
| `published` | 是否发布 |  |  | `bool` |
| `cover_url` | 封面链接 |  |  | `link` |
| `teacher_email` | 讲师邮箱 |  |  |  |
| `published_at` | 发布时间 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `course_no` | 课程编号 | `text` | ✓ | `required`, `max:255` |
| `title` | 课程标题 | `text` | ✓ | `required`, `max:255` |
| `summary` | 课程简介 | `textarea` |  |  |
| `level` | 难度级别 | `select` |  |  |
| `price` | 价格 | `money` | ✓ | `required` |
| `lessons` | 课时数 | `number` | ✓ | `required` |
| `published` | 是否发布 | `switch` |  |  |
| `cover_url` | 封面链接 | `url` |  |  |
| `teacher_email` | 讲师邮箱 | `email` |  | `email` |
| `published_at` | 发布时间 | `datetime` |  |  |

## 分类

- URI：`categories`
- 模型：`App\Models\Category`
- 类：`App\Aimanong\CategoryResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID |  |  | `badge` |
| `name` | 名称 |  | ✓ |  |
| `parent_id` | 父级ID | ✓ |  |  |
| `sort` | 排序 | ✓ |  |  |
| `enabled` | 是否启用 |  |  | `bool` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 名称 | `text` | ✓ | `required`, `max:64` |
| `parent_id` | 父级ID | `number` |  |  |
| `sort` | 排序 | `number` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

## 部门

- URI：`departments`
- 模型：`App\Models\Department`
- 类：`App\Aimanong\DepartmentResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `code` | 部门编码 |  | ✓ |  |
| `name` | 部门名称 |  | ✓ |  |
| `parent_id` | 上级部门 |  |  |  |
| `sort` | 排序 | ✓ |  |  |
| `manager_email` | 负责人邮箱 |  |  |  |
| `headcount` | 编制人数 | ✓ |  |  |
| `status` | 状态 |  |  | `badge` |
| `remark` | 备注 |  |  |  |
| `enabled` | 是否启用 |  |  | `bool` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `code` | 部门编码 | `text` | ✓ | `required`, `max:64` |
| `name` | 部门名称 | `text` | ✓ | `required`, `max:128` |
| `parent_id` | 上级部门ID | `number` |  |  |
| `sort` | 排序 | `number` |  |  |
| `manager_email` | 负责人邮箱 | `email` |  | `email` |
| `headcount` | 编制人数 | `number` |  |  |
| `status` | 状态 | `select` |  |  |
| `remark` | 备注 | `textarea` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# Resource 详情

> 由 `php artisan aimanong:docs` 自动生成。

## 操作日志

- URI：`admin-operation-logs`
- 模型：`Aimanong\Foundation\Audit\OperationLog`
- 类：`Aimanong\Foundation\Resources\OperationLogResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `created_at` | 时间 | ✓ |  | `datetime` |
| `username` | 账号 |  | ✓ |  |
| `action` | 动作 |  |  | `badge` |
| `resource_uri` | 资源 |  | ✓ |  |
| `target_id` | 记录 ID |  |  |  |
| `method` | 方法 |  |  |  |
| `status` | 状态码 | ✓ |  |  |
| `ip` | IP |  |  |  |
| `duration_ms` | 耗时(ms) | ✓ |  |  |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|

## 登录日志

- URI：`admin-login-logs`
- 模型：`Aimanong\Foundation\Audit\LoginLog`
- 类：`Aimanong\Foundation\Resources\LoginLogResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `created_at` | 时间 | ✓ |  | `datetime` |
| `username` | 账号 |  | ✓ |  |
| `status` | 结果 |  |  | `map` |
| `reason` | 原因 |  |  |  |
| `ip` | IP |  | ✓ |  |
| `user_agent` | User-Agent |  |  |  |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|

## 数据字典

- URI：`admin-dict-types`
- 模型：`Aimanong\Foundation\Dict\DictType`
- 类：`Aimanong\Foundation\Resources\DictTypeResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `code` | 字典 code |  | ✓ |  |
| `name` | 名称 |  | ✓ |  |
| `description` | 说明 |  |  |  |
| `is_locked` | 来源 |  |  | `bool` |
| `sort` | 排序 | ✓ |  |  |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `code` | 字典 code | `text` | ✓ | `required`, `max:100` |
| `name` | 名称 | `text` | ✓ | `required`, `max:190` |
| `description` | 说明 | `textarea` |  |  |
| `sort` | 排序 | `number` |  |  |

## 字典条目

- URI：`admin-dict-items`
- 模型：`Aimanong\Foundation\Dict\DictItem`
- 类：`Aimanong\Foundation\Resources\DictItemResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `type_code` | 所属字典 |  | ✓ |  |
| `value` | 值 |  | ✓ |  |
| `label` | 文案 |  | ✓ |  |
| `color` | 徽章颜色 |  |  | `map` |
| `sort` | 排序 | ✓ |  |  |
| `enabled` | 启用 |  |  | `bool` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `type_code` | 所属字典 | `select` | ✓ | `required` |
| `value` | 值 | `text` | ✓ | `required`, `max:190` |
| `label` | 文案 | `text` | ✓ | `required`, `max:190` |
| `color` | 徽章颜色 | `select` |  |  |
| `sort` | 排序 | `number` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

## 管理员

- URI：`admin-users`
- 模型：`Aimanong\Models\Administrator`
- 类：`Aimanong\Foundation\Resources\AdminUserResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `username` | 账号 |  | ✓ |  |
| `name` | 姓名 |  | ✓ |  |
| `email` | 邮箱 |  | ✓ |  |
| `roles.name` | 角色 |  |  |  |
| `enabled` | 状态 |  |  | `bool` |
| `last_login_at` | 最后登录 | ✓ |  | `datetime` |
| `last_login_ip` | 登录 IP |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `username` | 账号 | `text` | ✓ | `required`, `max:190` |
| `name` | 姓名 | `text` | ✓ | `required`, `max:190` |
| `email` | 邮箱 | `email` |  | `max:190` |
| `phone` | 手机号 | `tel` |  |  |
| `password` | 密码 | `password` |  | `min:6` |
| `avatar` | 头像 | `image` |  |  |
| `roles` | 角色 | `multiselect` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

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

## 商品分类

- URI：`shop-categories`
- 模型：`App\Models\ShopCategory`
- 类：`App\Aimanong\ShopCategoryResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 分类名称 |  | ✓ |  |
| `parent_id` | 上级分类 |  |  | `map` |
| `sort` | 排序 | ✓ |  |  |
| `enabled` | 是否启用 |  |  | `bool` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 分类名称 | `text` | ✓ | `required`, `max:64` |
| `parent_id` | 上级分类 | `select` |  |  |
| `sort` | 排序 | `number` |  |  |
| `enabled` | 是否启用 | `switch` |  |  |

## 商品

- URI：`shop-products`
- 模型：`App\Models\ShopProduct`
- 类：`App\Aimanong\ShopProductResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `sku` | SKU |  | ✓ |  |
| `name` | 商品名称 |  | ✓ |  |
| `category_name` | 所属分类 |  |  |  |
| `price` | 售价 | ✓ |  | `money` |
| `market_price` | 市场价 |  |  | `money` |
| `stock` | 库存 | ✓ |  |  |
| `sold_count` | 销量 | ✓ |  |  |
| `status_text` | 状态 |  |  | `badge` |
| `is_recommended` | 是否推荐 |  |  | `bool` |
| `listed_at` | 上架时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `sku` | SKU | `text` | ✓ | `required`, `max:64` |
| `name` | 商品名称 | `text` | ✓ | `required`, `max:128` |
| `category_id` | 所属分类 | `select` | ✓ | `required` |
| `status` | 状态 | `select` |  |  |
| `is_recommended` | 是否推荐 | `switch` |  |  |
| `price` | 售价 | `money` | ✓ | `required` |
| `market_price` | 市场价 | `money` |  |  |
| `stock` | 库存 | `number` |  |  |
| `sold_count` | 销量 | `number` |  |  |
| `description` | 商品描述 | `textarea` |  |  |
| `cover_image` | 封面图 | `text` |  | `max:255` |
| `listed_at` | 上架时间 | `datetime` |  |  |

## 收货地址（插件验证）

- URI：`shop-addresses`
- 模型：`App\Models\ShopAddress`
- 类：`App\Aimanong\ShopAddressResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID |  |  |  |
| `name` | 联系人 |  | ✓ |  |
| `phone` | 电话 |  |  |  |
| `area` | 省市区 |  |  |  |
| `city_only` | 仅到市 |  |  |  |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 联系人 | `text` | ✓ | `required` |
| `area` | 所在地区 | `region` |  |  |
| `city_only` | 仅到市 | `region` |  |  |
| `phone` | 电话 | `text` |  | `max:32` |
| `detail` | 详细地址 | `textarea` |  |  |

## 订单

- URI：`shop-orders`
- 模型：`App\Models\ShopOrder`
- 类：`App\Aimanong\ShopOrderResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `order_no` | 订单号 |  | ✓ |  |
| `product_name` | 商品 |  | ✓ |  |
| `buyer_id` | 买家 |  |  | `map` |
| `quantity` | 数量 | ✓ |  |  |
| `amount` | 金额 | ✓ |  | `money` |
| `status` | 状态 |  |  | `map` |
| `payment_method` | 支付方式 |  |  | `map` |
| `created_at` | 下单时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `order_no` | 订单号 | `text` | ✓ | `required`, `max:32` |
| `product_id` | 商品 | `select` | ✓ | `required` |
| `buyer_id` | 买家 | `select` | ✓ | `required` |
| `quantity` | 数量 | `number` | ✓ | `required` |
| `amount` | 金额 | `money` | ✓ | `required` |
| `status` | 状态 | `select` |  |  |
| `payment_method` | 支付方式 | `select` |  |  |
| `paid_at` | 支付时间 | `datetime` |  |  |
| `shipped_at` | 发货时间 | `datetime` |  |  |
| `remark` | 备注 | `textarea` |  |  |

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

## 供应商

- URI：`suppliers`
- 模型：`App\Models\Supplier`
- 类：`App\Aimanong\SupplierResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 名称 |  | ✓ |  |
| `contact_email` | contact_email |  |  |  |
| `phone` | 电话 |  |  |  |
| `credit_limit` | credit_limit |  |  |  |
| `status` | 状态 | ✓ |  | `badge` |
| `enabled` | 是否启用 | ✓ |  | `bool` |
| `address` | 地址 |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 名称 | `text` | ✓ | `required`, `max:255` |
| `contact_email` | contact_email | `email` |  | `email` |
| `phone` | 电话 | `tel` |  |  |
| `credit_limit` | credit_limit | `text` | ✓ | `required`, `max:255` |
| `status` | 状态 | `select` | ✓ | `required` |
| `enabled` | 是否启用 | `switch` |  |  |
| `address` | 地址 | `textarea` |  |  |

## 栏目

- URI：`cms-categories`
- 模型：`App\Models\CmsCategory`
- 类：`App\Aimanong\CmsCategoryResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 栏目名称 |  | ✓ |  |
| `parent.name` | 上级栏目 |  |  |  |
| `sort` | 排序 | ✓ |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 栏目名称 | `text` | ✓ | `required`, `max:64` |
| `parent_id` | 上级栏目 | `select` |  |  |
| `sort` | 排序 | `number` |  |  |

## 作者

- URI：`cms-authors`
- 模型：`App\Models\CmsAuthor`
- 类：`App\Aimanong\CmsAuthorResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 姓名 |  | ✓ |  |
| `email` | 邮箱 |  | ✓ |  |
| `bio` | 简介 |  |  |  |
| `active` | 是否在职 |  |  | `bool` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 姓名 | `text` | ✓ | `required`, `max:64` |
| `email` | 邮箱 | `email` | ✓ | `required`, `email` |
| `bio` | 简介 | `textarea` |  |  |
| `active` | 是否在职 | `switch` |  |  |

## 标签

- URI：`cms-tags`
- 模型：`App\Models\CmsTag`
- 类：`App\Aimanong\CmsTagResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `name` | 标签名称 |  | ✓ |  |
| `color` | 颜色 |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 标签名称 | `text` | ✓ | `required`, `max:32` |
| `color` | 颜色 | `color` |  |  |

## 文章

- URI：`cms-articles`
- 模型：`App\Models\CmsArticle`
- 类：`App\Aimanong\CmsArticleResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `slug` | 别名 |  | ✓ |  |
| `title` | 标题 |  | ✓ |  |
| `category.name` | 栏目 |  |  |  |
| `author.name` | 作者 |  |  |  |
| `tags.name` | 标签 |  |  |  |
| `status` | 状态 |  |  | `map` |
| `is_featured` | 是否精选 |  |  | `bool` |
| `view_count` | 浏览量 | ✓ |  |  |
| `read_minutes` | 阅读时长 | ✓ |  |  |
| `published_at` | 发布时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `slug` | 别名 | `text` | ✓ | `required`, `max:128` |
| `title` | 标题 | `text` | ✓ | `required`, `max:200` |
| `category_id` | 所属栏目 | `select` | ✓ | `required` |
| `author_id` | 作者 | `select` | ✓ | `required` |
| `tags` | 标签 | `multiselect` |  |  |
| `status` | 状态 | `select` |  |  |
| `is_featured` | 是否精选 | `switch` |  |  |
| `body` | 正文 | `textarea` |  |  |
| `published_at` | 发布时间 | `datetime` |  |  |
| `view_count` | 浏览量 | `number` |  |  |
| `read_minutes` | 阅读时长(分钟) | `number` |  |  |
| `seo_title` | SEO 标题 | `text` |  | `max:200` |
| `seo_description` | SEO 描述 | `textarea` |  |  |

## 租户

- URI：`tenants`
- 模型：`App\Models\Tenant`
- 类：`App\Aimanong\TenantResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `name` | 名称 |  | ✓ |  |
| `code` | 编码 |  | ✓ |  |
| `plan` | 套餐 |  |  | `map` |
| `active` | 是否启用 |  |  | `bool` |
| `expired_at` | 到期时间 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 名称 | `text` | ✓ | `required`, `max:64` |
| `code` | 编码 | `text` | ✓ | `required`, `max:32`, `alpha_dash` |
| `plan` | 套餐 | `select` |  |  |
| `active` | 是否启用 | `switch` |  |  |
| `expired_at` | 到期时间 | `datetime` |  |  |

## 客户

- URI：`saas-customers`
- 模型：`App\Models\SaasCustomer`
- 类：`App\Aimanong\SaasCustomerResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `name` | 姓名 |  | ✓ |  |
| `email` | 邮箱 |  | ✓ |  |
| `phone` | 电话 |  |  |  |
| `level` | 等级 |  |  | `map` |
| `balance` | 余额 | ✓ |  | `money` |
| `active` | 是否启用 |  |  | `bool` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 姓名 | `text` | ✓ | `required`, `max:64` |
| `email` | 邮箱 | `email` | ✓ | `required`, `email`, `max:128` |
| `phone` | 电话 | `tel` |  | `max:20` |
| `level` | 等级 | `select` |  |  |
| `balance` | 余额 | `money` |  |  |
| `active` | 是否启用 | `switch` |  |  |

## 订单

- URI：`saas-orders`
- 模型：`App\Models\SaasOrder`
- 类：`App\Aimanong\SaasOrderResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `order_no` | 订单号 |  | ✓ |  |
| `customer.name` | 客户 |  |  |  |
| `amount` | 金额 | ✓ |  | `money` |
| `status` | 状态 |  |  | `map` |
| `paid_at` | 支付时间 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `order_no` | 订单号 | `text` | ✓ | `required`, `max:32` |
| `customer_id` | 客户 | `select` | ✓ | `required` |
| `amount` | 金额 | `money` | ✓ | `required` |
| `status` | 状态 | `select` |  |  |
| `paid_at` | 支付时间 | `datetime` |  |  |

## 角色

- URI：`admin-roles`
- 模型：`Aimanong\Models\Role`
- 类：`App\Aimanong\AdminRoleResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `slug` | slug |  |  |  |
| `name` | 名称 |  | ✓ |  |
| `description` | 描述 |  |  |  |
| `is_super` | is_super | ✓ |  | `bool` |
| `sort` | 排序 |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `slug` | slug | `text` | ✓ | `required`, `max:255` |
| `name` | 名称 | `text` | ✓ | `required`, `max:255` |
| `description` | 描述 | `textarea` |  |  |
| `is_super` | is_super | `switch` |  |  |
| `sort` | 排序 | `number` | ✓ | `required` |
| `permissions` | 权限 | `multiselect` |  |  |

## 权限节点

- URI：`admin-permissions`
- 模型：`Aimanong\Models\Permission`
- 类：`App\Aimanong\AdminPermissionResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `slug` | slug |  |  |  |
| `name` | 名称 |  | ✓ |  |
| `group` | group |  |  |  |
| `description` | 描述 |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |
| `updated_at` | 更新时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `slug` | slug | `text` | ✓ | `required`, `max:255` |
| `name` | 名称 | `text` | ✓ | `required`, `max:255` |
| `group` | group | `text` |  | `max:255` |
| `description` | 描述 | `textarea` |  |  |

## 上传演示

- URI：`demo-uploads`
- 模型：`App\Models\DemoUpload`
- 类：`App\Aimanong\DemoUploadResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `title` | 标题 |  | ✓ |  |
| `cover` | 封面 |  |  | `image` |
| `attachment` | 附件 |  |  | `file` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `title` | 标题 | `text` | ✓ | `required`, `max:190` |
| `cover` | 封面 | `image` |  |  |
| `gallery` | 图集 | `images` |  |  |
| `attachment` | 附件 | `file` |  |  |
| `attachments` | 多附件 | `files` |  |  |

## 设备报修

- URI：`equipment-repairs`
- 模型：`App\Models\EquipmentRepair`
- 类：`App\Aimanong\EquipmentRepairResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `repair_no` | 报修单号 |  | ✓ |  |
| `equipment_name` | 设备名称 |  | ✓ |  |
| `reporter` | 报修人 |  | ✓ |  |
| `status` | 状态 |  |  | `map` |
| `urgency` | 紧急程度 |  |  | `map` |
| `reported_at` | 报修时间 | ✓ |  | `datetime` |
| `handler` | 处理人 |  |  |  |
| `photos` | 现场照片 |  |  | `image` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `repair_no` | 报修单号 | `text` | ✓ | `required`, `max:60` |
| `equipment_name` | 设备名称 | `text` | ✓ | `required`, `max:190` |
| `reporter` | 报修人 | `text` | ✓ | `required`, `max:100` |
| `description` | 故障描述 | `textarea` |  |  |
| `photos` | 现场照片 | `images` |  |  |
| `attachments` | 相关附件 | `files` |  |  |
| `reported_at` | 报修时间 | `datetime` |  |  |
| `status` | 状态 | `select` | ✓ | `required` |
| `urgency` | 紧急程度 | `select` | ✓ | `required` |
| `处理信息` | 处理信息 | `divider` |  |  |
| `handler` | 处理人 | `text` |  | `max:100` |
| `handled_at` | 处理时间 | `datetime` |  |  |

## 客户

- URI：`crm-customers`
- 模型：`App\Models\CrmCustomer`
- 类：`App\Aimanong\CrmCustomerResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `logo` | LOGO |  |  | `image` |
| `name` | 客户名称 |  | ✓ |  |
| `level` | 等级 |  |  | `map` |
| `industry` | 行业 |  |  | `map` |
| `status` | 状态 |  |  | `map` |
| `source` | 来源 |  |  | `map` |
| `owner` | 负责人 |  | ✓ |  |
| `phone` | 电话 |  |  |  |
| `contacts.name` | 联系人 |  |  |  |
| `annual_revenue` | 年营收 |  |  | `money` |
| `next_follow_at` | 下次跟进 | ✓ |  | `datetime` |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `name` | 客户名称 | `text` | ✓ | `required`, `max:190` |
| `short_name` | 简称 | `text` |  | `max:100` |
| `level` | 客户等级 | `select` |  |  |
| `industry` | 所属行业 | `select` |  |  |
| `status` | 客户状态 | `select` |  |  |
| `source` | 客户来源 | `select` |  |  |
| `owner` | 负责人 | `text` |  | `max:100` |
| `logo` | 客户 LOGO | `image` |  |  |
| `phone` | 联系电话 | `tel` |  |  |
| `website` | 官网 | `url` |  |  |
| `area` | 所在地区 | `region` |  |  |
| `address` | 详细地址 | `text` |  | `max:500` |
| `next_follow_at` | 下次跟进日期 | `date` |  |  |
| `annual_revenue` | 年营收 | `money` |  |  |
| `employee_count` | 员工人数 | `number` |  |  |
| `remark` | 备注 | `textarea` |  |  |

## 联系人

- URI：`crm-contacts`
- 模型：`App\Models\CrmContact`
- 类：`App\Aimanong\CrmContactResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `avatar` | 头像 |  |  | `image` |
| `name` | 姓名 |  | ✓ |  |
| `customer.name` | 所属客户 |  | ✓ |  |
| `title` | 职位 |  |  |  |
| `department` | 部门 |  |  |  |
| `phone` | 电话 |  | ✓ |  |
| `email` | 邮箱 |  |  |  |
| `wechat` | 微信 |  |  |  |
| `is_primary` | 主要联系人 |  |  | `bool` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `customer_id` | 所属客户 | `select` | ✓ | `required` |
| `name` | 姓名 | `text` | ✓ | `required`, `max:100` |
| `title` | 职位 | `text` |  | `max:100` |
| `department` | 部门 | `text` |  | `max:100` |
| `phone` | 电话 | `tel` |  |  |
| `email` | 邮箱 | `email` |  |  |
| `wechat` | 微信 | `text` |  | `max:100` |
| `is_primary` | 主要联系人 | `switch` |  |  |
| `avatar` | 头像 | `image` |  |  |
| `remark` | 备注 | `textarea` |  |  |

## 商机

- URI：`crm-opportunities`
- 模型：`App\Models\CrmOpportunity`
- 类：`App\Aimanong\CrmOpportunityResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `title` | 商机名称 |  | ✓ |  |
| `customer.name` | 客户 |  | ✓ |  |
| `amount` | 金额 | ✓ |  | `money` |
| `stage` | 阶段 |  |  | `map` |
| `probability` | 赢单率 |  |  | `progress` |
| `expected_close_at` | 预计成交 | ✓ |  | `datetime` |
| `contacts.name` | 对接人 |  |  |  |
| `owner` | 负责人 |  |  |  |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `title` | 商机名称 | `text` | ✓ | `required`, `max:190` |
| `customer_id` | 所属客户 | `select` | ✓ | `required` |
| `amount` | 预计金额 | `money` |  |  |
| `stage` | 阶段 | `select` |  |  |
| `probability` | 赢单率(%) | `slider` |  |  |
| `expected_close_at` | 预计成交日期 | `date` |  |  |
| `owner` | 负责人 | `text` |  | `max:100` |
| `contacts` | 对接人 | `multiselect` |  |  |
| `remark` | 备注 | `textarea` |  |  |

## 跟进记录

- URI：`crm-followups`
- 模型：`App\Models\CrmFollowup`
- 类：`App\Aimanong\CrmFollowupResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `followed_at` | 跟进时间 | ✓ |  | `datetime` |
| `customer.name` | 客户 |  | ✓ |  |
| `type` | 方式 |  |  | `map` |
| `subject` | 主题 |  | ✓ |  |
| `owner` | 跟进人 |  |  |  |
| `created_at` | 创建时间 | ✓ |  | `datetime` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `customer_id` | 客户 | `select` | ✓ | `required` |
| `opportunity_id` | 关联商机 | `select` |  |  |
| `type` | 跟进方式 | `select` |  |  |
| `subject` | 主题 | `text` | ✓ | `required`, `max:190` |
| `content` | 跟进内容 | `textarea` |  |  |
| `attachments` | 附件 | `files` |  |  |
| `followed_at` | 跟进时间 | `datetime` |  |  |
| `owner` | 跟进人 | `text` |  | `max:100` |

## 合同

- URI：`crm-contracts`
- 模型：`App\Models\CrmContract`
- 类：`App\Aimanong\CrmContractResource`

### 列表页列

| 列 | 标题 | 可排序 | 可搜索 | 展示器 |
|---|---|---|---|---|
| `id` | ID | ✓ |  |  |
| `contract_no` | 合同号 |  | ✓ |  |
| `title` | 合同名称 |  | ✓ |  |
| `customer.name` | 客户 |  | ✓ |  |
| `amount` | 金额 | ✓ |  | `money` |
| `status` | 状态 |  |  | `map` |
| `signed_at` | 签署日期 | ✓ |  | `datetime` |
| `end_at` | 到期日期 | ✓ |  | `datetime` |
| `attachment` | 扫描件 |  |  | `file` |

### 表单字段

| 字段 | 标签 | 类型 | 必填 | 校验规则 |
|---|---|---|---|---|
| `contract_no` | 合同号 | `text` | ✓ | `required`, `max:60` |
| `title` | 合同名称 | `text` | ✓ | `required`, `max:190` |
| `customer_id` | 客户 | `select` | ✓ | `required` |
| `opportunity_id` | 关联商机 | `select` |  |  |
| `amount` | 合同金额 | `money` |  |  |
| `status` | 合同状态 | `select` |  |  |
| `signed_at` | 签署日期 | `date` |  |  |
| `period` | 有效期 | `daterange` |  |  |
| `attachment` | 合同扫描件 | `file` |  |  |
| `remark` | 备注 | `textarea` |  |  |

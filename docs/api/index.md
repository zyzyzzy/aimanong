<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# API 参考

> 本页由 `php artisan aimanong:docs` 自动生成。
> 修改 Resource 声明后重新执行即可，**无需手工同步**。

## 环境

| 项 | 值 |
|---|---|
| 框架版本 | 1.7.1 |
| Laravel | 12.69.3 |
| PHP | 8.2.29 |
| 字段类型数 | 31 |
| 已注册 Resource | 34 |

## 已注册 Resource

| 名称 | URI | 模型 | 列数 | 字段数 |
|---|---|---|---|---|
| 操作日志 | `admin-operation-logs` | `Aimanong\Foundation\Audit\OperationLog` | 10 | 0 |
| 登录日志 | `admin-login-logs` | `Aimanong\Foundation\Audit\LoginLog` | 7 | 0 |
| 数据字典 | `admin-dict-types` | `Aimanong\Foundation\Dict\DictType` | 7 | 4 |
| 字典条目 | `admin-dict-items` | `Aimanong\Foundation\Dict\DictItem` | 7 | 6 |
| 管理员 | `admin-users` | `Aimanong\Models\Administrator` | 9 | 8 |
| SEO 元数据 | `seo-meta` | `App\Models\Course` | 3 | 0 |
| 商品分类 | `shop-categories` | `App\Models\ShopCategory` | 6 | 4 |
| 商品 | `shop-products` | `App\Models\ShopProduct` | 10 | 12 |
| 收货地址（插件验证） | `shop-addresses` | `App\Models\ShopAddress` | 5 | 5 |
| 订单 | `shop-orders` | `App\Models\ShopOrder` | 8 | 10 |
| 用户 | `users` | `App\Models\User` | 4 | 4 |
| 订单 | `orders` | `App\Models\Order` | 8 | 5 |
| 工单 | `tickets` | `App\Models\Ticket` | 9 | 7 |
| 字段演示 | `demo-fields` | `App\Models\Ticket` | 5 | 9 |
| 课程 | `courses` | `App\Models\Course` | 13 | 10 |
| 分类 | `categories` | `App\Models\Category` | 5 | 4 |
| 部门 | `departments` | `App\Models\Department` | 12 | 9 |
| 供应商 | `suppliers` | `App\Models\Supplier` | 10 | 7 |
| 栏目 | `cms-categories` | `App\Models\CmsCategory` | 5 | 3 |
| 作者 | `cms-authors` | `App\Models\CmsAuthor` | 6 | 4 |
| 标签 | `cms-tags` | `App\Models\CmsTag` | 4 | 2 |
| 文章 | `cms-articles` | `App\Models\CmsArticle` | 11 | 13 |
| 租户 | `tenants` | `App\Models\Tenant` | 6 | 5 |
| 客户 | `saas-customers` | `App\Models\SaasCustomer` | 7 | 6 |
| 订单 | `saas-orders` | `App\Models\SaasOrder` | 6 | 5 |
| 角色 | `admin-roles` | `Aimanong\Models\Role` | 8 | 6 |
| 权限节点 | `admin-permissions` | `Aimanong\Models\Permission` | 7 | 4 |
| 上传演示 | `demo-uploads` | `App\Models\DemoUpload` | 5 | 5 |
| 设备报修 | `equipment-repairs` | `App\Models\EquipmentRepair` | 9 | 12 |
| 客户 | `crm-customers` | `App\Models\CrmCustomer` | 13 | 16 |
| 联系人 | `crm-contacts` | `App\Models\CrmContact` | 10 | 10 |
| 商机 | `crm-opportunities` | `App\Models\CrmOpportunity` | 9 | 9 |
| 跟进记录 | `crm-followups` | `App\Models\CrmFollowup` | 7 | 8 |
| 合同 | `crm-contracts` | `App\Models\CrmContract` | 9 | 10 |

## 其它页面

- [字段类型](./fields.md) —— 全部可用字段及其属性
- [列展示器](./columns.md) —— 列表页可用选项
- [Resource 详情](./resources.md) —— 每个 Resource 的完整定义
- [HTTP API](./api.md) —— 查询参数与响应格式

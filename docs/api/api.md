<!-- 自动生成，请勿手工编辑 -->
<!-- 来源: php artisan aimanong:docs -->
<!-- 修改 Resource 声明后请重新生成 -->


# HTTP API

> 由 `php artisan aimanong:docs` 自动生成。

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/admin/api/{uri}` | 列表（分页 + 搜索 + 排序） |
| POST | `/admin/api/{uri}` | 新增 |
| GET | `/admin/api/{uri}/{id}` | 详情 |
| PUT | `/admin/api/{uri}/{id}` | 更新 |
| DELETE | `/admin/api/{uri}/{id}` | 删除 |

## 查询参数

| 参数 | 类型 | 说明 |
|---|---|---|
| `page` | int | 页码，从 1 开始 |
| `per_page` | int | 每页条数，不传则用 Resource 声明的值 |
| `keyword` | string | 搜索关键词，在可搜索列上模糊匹配 |
| `sort` | string | 排序字段，必须是可排序列 |
| `direction` | `asc` \| `desc` | 排序方向。**注意参数名是 `direction`，不是 `order`** |

> 传入未知参数时，响应会带 `warnings.UNKNOWN_QUERY_PARAM` 提示，
> 而不会静默忽略 —— 避免"参数写错却以为生效"。

## 响应格式

```json
{
  "data": [ { "id": 1, "name": "示例" } ],
  "meta": {
    "total": 42,
    "perPage": 20,
    "currentPage": 1,
    "lastPage": 3
  }
}
```

## AI 自省接口

| 接口 | 说明 |
|---|---|
| `GET /__ai/capabilities.json` | 全部能力清单 |
| `GET /__ai/schema/{uri}` | 某 Resource 完整定义 |
| `GET /__ai/openapi.json` | OpenAPI 3.1 文档 |
| `GET /__ai/context` | Markdown 上下文 |
| `POST /__ai/verify` | 校验声明合法性 |

> 默认仅 `local` / `debug` 环境开启；生产需配置 `AIMANONG_AI_TOKEN`。

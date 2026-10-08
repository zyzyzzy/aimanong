# AGENTS.md

本文件是 **AI Agent 在本项目中工作的强制约定**。在动手前请先读 [llms.txt](./llms.txt)。

## 项目定位

AI 码农（Aimanong）—— AI-First 后台框架，基于 Laravel 12 + Vue 3。
**使用方主要是 AI Agent，人是审阅者。**

## 目录约定

| 目录 | 用途 | Agent 是否可写 |
|---|---|---|
| `packages/framework/src` | 框架核心 | ⚠️ 谨慎，需人类 review |
| `packages/ui/src` | Vue 组件库 | ⚠️ 谨慎 |
| `demo/app/Aimanong` | 示例 Resource | ✅ 可自由写 |
| `docs/` | 文档 | ✅ 可写 |
| `tests/` | 测试 | ✅ 可写 |

## 生成代码的强制流程

1. **先查能力**：`GET /__ai/capabilities.json` 或 `php artisan ai:capabilities`
2. **写 Resource**：放 `app/Aimanong/{Name}Resource.php`
3. **必须校验**：`php artisan ai:verify`
4. **跑测试**：`composer test`
5. **不得跳过第 3、4 步**

## 代码风格

- PHP：Laravel Pint 风格，PHPStan **Level 8**
- 链式调用**扁平化**，不嵌套闭包（AI 生成嵌套闭包错误率高）
- 方法不做重载，签名唯一
- 时间计算**禁止裸调 Carbon `diffIn*`**（Laravel 12 下返回负数 float），用框架时间工具封装
- TypeScript：`strict` 模式，无 `any`

## 提交规范

Conventional Commits：`feat:` `fix:` `docs:` `refactor:` `test:` `chore:`

中文或英文描述均可，但前缀必须是英文。

## 禁止事项

- ❌ 提交私钥、Token、`.env`
- ❌ `--force` 强推到 `main`/`develop`
- ❌ 删除他人分支或 Release
- ❌ 直接在 `main` 上开发（走 `feat/*` 分支 + PR）
- ❌ 手写前端页面（前端由 Schema 自动渲染）

## 出错时怎么办

框架异常实现 `AiReadableException`，含 `didYouMean` / `example` / `docs` 三个字段。

1. 读 `did_you_mean`，**改一次再试**
2. 仍失败，查 `GET /__ai/capabilities.json` 确认正确 API
3. 仍失败，把完整报错交给人类，**不要反复重写**

## 验证你的产出

```bash
php artisan ai:verify     # 声明合法性 + 是否已注册 + 幽灵列检测
composer test             # 单元测试
php artisan serve         # 访问 /admin 人工确认
```

### ⚠️ 语法校验通过 ≠ 需求达标

`validate_declaration` **默认只检查语法合法性**。若任务有具体要求
（某列可搜索、每页 N 条等），**必须传 `requirements` 参数**：

```json
{"resource": "App\\Aimanong\\OrderResource",
 "requirements": {"searchable": "order_no,customer_name",
                  "sortable": "amount,paid_at",
                  "required": "order_no",
                  "per_page": 30}}
```

不传 `requirements` 就交付，等于没有验证。

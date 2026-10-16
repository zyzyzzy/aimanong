<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 外键关联解析 —— 把「模型上的一个关联」变成「表单下拉的候选」。
 *
 * ## 为什么需要这个契约
 *
 * 在此之前，做一个「外键下拉」要在声明里**手写三件事**：
 *
 *     $form->select('author_id', '作者')
 *         ->options(Author::pluck('name', 'id')->all())   // ① 自己查库
 *         ->rules('exists:authors,id');                    // ② 自己拼表名
 *     // ③ 还得在模型上先定义 author() 关联
 *
 * 而这三件事框架**全都知道** —— 字段名 `author_id` 就说明了关联是 `author`，
 * 关联定义里就有目标模型、表名、主键。实测中一个 LLM 为搞清这些，
 * 翻了自省接口、MCP 文档，还反射了 142 个类。
 *
 * 这几件事又是**最容易写错**的：表名猜错（`categorys` vs `categories`）、
 * `pluck` 出来是 Collection 直接传给 `options()`（编译失败）、
 * 忘记加 `exists` 规则（前端能提交任意 id）。
 *
 * ## 为什么是契约
 *
 * core 不能依赖任何 ORM —— 关联怎么查、目标模型怎么读，只有驱动知道。
 * 本契约是 core 与驱动之间的接缝。
 */
interface RelationResolver
{
    /**
     * 解析一个关联，返回下拉所需的一切。
     *
     * @param  string  $model  持有该关联的模型类名（如 App\Models\Article）
     * @param  string  $relation  关联方法名（如 author）
     * @param  string|null  $labelColumn  候选显示用哪一列；null = 自动挑
     * @return array{options: array<int, array{value: mixed, label: string}>, exists: string, labelColumn: string}|null
     *                                                                                                                  解析不了时返回 null（不抛异常）——
     *                                                                                                                  编译期会遇到「模型还没建」「表还没迁」这类正常中间态，
     *                                                                                                                  让整个 Resource 编译失败比降级更难排查
     */
    public function resolve(string $model, string $relation, ?string $labelColumn = null): ?array;
}

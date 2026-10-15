<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Contracts\RelationResolver;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Drivers\Eloquent\EloquentRelationResolver;
use Aimanong\Form\Fields\BelongsTo;
use Aimanong\Form\Form;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Drivers;
use PHPUnit\Framework\TestCase;

/**
 * 外键下拉（`$form->belongsTo(...)`）。
 *
 * ## 它替声明方省掉的（都是实测中真的踩过的）
 *
 * | 原先要手写 | 后果 |
 * |---|---|
 * | `Author::pluck('name','id')->all()` | 忘了 `->all()` → 编译失败（传的是 Collection） |
 * | `->rules('exists:authors,id')` | 忘了 → 前端能提交任意 id |
 * | 表名 | 写错不报错，一路错到运行时（实测 `categorys` vs `categories`） |
 */
class BelongsToFieldTest extends TestCase
{
    protected function tearDown(): void
    {
        /*
         * ⚠️ 必须**完整恢复**驱动集，不能只 `Drivers::reset()`。
         *
         * 只 reset 会让后续测试（如 DictionaryTest）在「没有任何驱动」的状态下跑，
         * 报出与它自己毫无关系的错 —— 红的位置离原因很远，极难排查。
         * （同一个坑在 MultiApplicationMenuTest 上踩过一次。）
         */
        Drivers::reset();
        Drivers::registerIntrospector(new EloquentIntrospector);
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);
        Drivers::registerRelationResolver(new EloquentRelationResolver);

        parent::tearDown();
    }

    public function test_relation_name_is_inferred_from_field_name(): void
    {
        $field = new BelongsTo('author_id', '作者');

        $this->assertSame('author', $field->resolvedRelationName());
    }

    public function test_explicit_relation_overrides_inference(): void
    {
        $field = (new BelongsTo('writer_id', '作者'))->relation('author');

        $this->assertSame('author', $field->resolvedRelationName());
    }

    public function test_field_without_id_suffix_uses_its_own_name(): void
    {
        $field = new BelongsTo('author', '作者');

        $this->assertSame('author', $field->resolvedRelationName());
    }

    public function test_schema_type_is_select_not_belongs_to(): void
    {
        /*
         * ⚠️ 这是最关键的一条。
         *
         * `type` 表示**控件**，前端模板只认 `f.type === 'select'` 才画下拉。
         * 若这里写成 `belongsTo`，字段会掉进 `<input>` 分支 ——
         * 变成一个人手填 id 的文本框，与这个字段存在的意义正好相反。
         */
        $this->assertSame('select', (new BelongsTo('author_id', '作者'))->getType());
    }

    public function test_apply_resolved_fills_options_and_exists_rule(): void
    {
        $field = new BelongsTo('author_id', '作者');

        $field->applyResolved(
            [['value' => 1, 'label' => '鲁迅']],
            'exists:authors,id'
        );

        $this->assertSame([['value' => 1, 'label' => '鲁迅']], $field->getProps()['options']);
        $this->assertSame('author', $field->getProps()['relation']);
        $this->assertContains('exists:authors,id', $field->toNode()->rules);
    }

    public function test_apply_resolved_does_not_duplicate_exists_rule(): void
    {
        $field = new BelongsTo('author_id', '作者');
        $field->rules('exists:authors,id');

        $field->applyResolved([], 'exists:authors,id');

        $this->assertCount(1, array_filter(
            $field->toNode()->rules,
            static fn ($r) => $r === 'exists:authors,id'
        ));
    }

    public function test_resolver_absent_degrades_instead_of_throwing(): void
    {
        // 外键下拉是**增强**能力：没装驱动不该让整个编译崩掉
        Drivers::reset();

        $this->assertFalse(Drivers::hasRelationResolver());
        $this->assertNull(Drivers::relationResolver());
    }

    public function test_form_collects_belongs_to_fields(): void
    {
        $form = new Form;
        $form->text('title', '标题');
        $form->belongsTo('author_id', '作者');
        $form->belongsTo('category_id', '分类');

        $this->assertSame(['author_id', 'category_id'], array_keys($form->belongsToFields()));
    }

    public function test_resolver_contract_shape(): void
    {
        // 契约本身：驱动返回的数组必须带这三个键
        $resolver = new class implements RelationResolver
        {
            public function resolve(string $model, string $relation, ?string $labelColumn = null): ?array
            {
                // 契约的另一半：解析不了返回 null，**不抛异常** ——
                // 「模型还没建」「表还没迁」都是编译期的正常中间态
                if ($relation === 'missing') {
                    return null;
                }

                return ['options' => [], 'exists' => 'exists:t,id', 'labelColumn' => 'name'];
            }
        };

        $result = $resolver->resolve('M', 'r');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('options', $result);
        $this->assertArrayHasKey('exists', $result);
        $this->assertNull($resolver->resolve('M', 'missing'), '解析不了必须返回 null 而不是抛异常');
    }
}

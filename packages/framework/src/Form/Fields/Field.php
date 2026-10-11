<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Exceptions\UnknownFieldTypeException;
use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Support\FieldType;

/**
 * 字段基类。
 *
 * Aimanong 约定：
 *   - 链式调用扁平化，无重载
 *   - 所有配置可序列化（无闭包）
 *   - 类型必须在 FieldType 中登记，否则抛可自愈异常
 */
abstract class Field
{
    /**
     * 字段类型标识，子类覆盖。
     */
    protected string $type = 'text';

    protected string $label;

    protected mixed $default = null;

    protected bool $required = false;

    protected bool $readonly = false;

    protected bool $hidden = false;

    /**
     * @var array<int, string>
     */
    protected array $rules = [];

    /**
     * @var array<string, mixed>
     */
    protected array $props = [];

    public function __construct(
        protected string $name,
        ?string $label = null,
    ) {
        $this->label = $label ?? $name;

        // 类型合法性校验：AI 写错类型时立刻给出可自愈错误
        if (! FieldType::exists($this->type)) {
            throw UnknownFieldTypeException::make($this->type, static::class);
        }
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function required(bool $value = true): static
    {
        $this->required = $value;

        if ($value && ! in_array('required', $this->rules, true)) {
            $this->rules[] = 'required';
        }

        return $this;
    }

    public function readonly(bool $value = true): static
    {
        $this->readonly = $value;

        return $this;
    }

    public function hidden(bool $value = true): static
    {
        $this->hidden = $value;

        return $this;
    }

    /**
     * 追加 Laravel 验证规则。
     */
    public function rules(string|array $rules): static
    {
        foreach ((array) $rules as $rule) {
            if (! in_array($rule, $this->rules, true)) {
                $this->rules[] = $rule;
            }
        }

        return $this;
    }

    public function max(int $value): static
    {
        return $this->rules("max:{$value}");
    }

    public function min(int $value): static
    {
        return $this->rules("min:{$value}");
    }

    /**
     * 仅「新增」时必填。
     *
     * 典型场景：用户表单的密码框 —— 新增时必须填，
     * 编辑时留空表示不改（配合 omitWhenEmpty()）。
     *
     * 用 required() 会导致编辑时被自己的规则卡住，
     * 用 nullable() 又会让新增时静默存进空密码。
     */
    public function requiredOnCreate(bool $value = true): static
    {
        $this->props['requiredOnCreate'] = $value;

        return $this;
    }

    /**
     * 提交时若为空则**整个字段不提交**。
     *
     * 典型场景：编辑用户时的密码框。留空代表「不改密码」，
     * 若把空字符串提交上去，`'hashed'` cast 会把它哈希成一个
     * 新密码 —— 账号当场失效，而且不报任何错。
     *
     * 更通用地说：任何「留空表示不动」的可选字段都该加它。
     */
    public function omitWhenEmpty(bool $value = true): static
    {
        $this->props['omitWhenEmpty'] = $value;

        return $this;
    }

    public function help(string $text): static
    {
        $this->props['help'] = $text;

        return $this;
    }

    public function placeholder(string $text): static
    {
        $this->props['placeholder'] = $text;

        return $this;
    }

    /**
     * 格式化为日期时间。
     *
     * 放在基类而非仅 Show：llms.txt 的示例包含
     * `$show->field('created_at')->dateTime()`，而 Show::field()
     * 返回的就是 Field 子类 —— 基类缺失该方法会导致照文档写必崩。
     */
    public function dateTime(string $format = 'Y-m-d H:i:s'): static
    {
        $this->props['format'] = $format;
        $this->props['display'] = 'datetime';

        return $this;
    }

    /**
     * 格式化为日期。
     */
    public function dateFormat(string $format = 'Y-m-d'): static
    {
        $this->props['format'] = $format;
        $this->props['display'] = 'date';

        return $this;
    }

    /**
     * 值 → 标签映射（详情页与列表通用）。
     *
     * 存为 stdClass：PHP 会把数字字符串键转回整数，
     * 直接存数组会被 json_encode 成 ["否","是"] 而非 {"0":"否","1":"是"}。
     *
     * @param  array<int|string, mixed>  $map
     */
    public function map(array $map): static
    {
        $object = new \stdClass;

        foreach ($map as $key => $label) {
            $object->{(string) $key} = $label;
        }

        $this->props['map'] = $object;
        $this->props['display'] = 'map';

        return $this;
    }

    /**
     * 枚举值 → 标签映射。
     *
     * @param  class-string  $enumClass
     */
    public function using(string $enumClass): static
    {
        $this->props['enum'] = $enumClass;
        $this->props['display'] = 'enum';

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    public function toNode(): FieldNode
    {
        return new FieldNode(
            name: $this->name,
            type: $this->type,
            label: $this->label,
            props: $this->props,
            rules: $this->rules,
            default: $this->default,
            required: $this->required,
            readonly: $this->readonly,
            hidden: $this->hidden,
        );
    }
}

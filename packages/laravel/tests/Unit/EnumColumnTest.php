<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Grid\Column;
use PHPUnit\Framework\TestCase;

enum ProbeStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return $this === self::Active ? '启用' : '禁用';
    }
}

enum ProbeStatusNoLabel: string
{
    case Active = 'active';
}

/**
 * `->using(Enum::class)` 必须真的把标签送到前端。
 *
 * ## 事故
 *
 * 原先 `using()` 只往 schema 里写 `formatter: enum` + `enum: App\Enums\X`
 * （PHP 类名的**字符串**），而前端是 JS —— 它解析不了 PHP 类名，
 * 只会去读 `props.map` 取标签。于是：
 *
 *     声明合法 ✅  ai:verify 通过 ✅  schema 也生成了 ✅
 *     列表照旧显示 active / disabled 原始值 ❌
 *
 * 而 `llms.txt` 第 154 行明写着 `->using(EnumClass::class) // 值→标签映射`
 * —— **文档承诺了，实现没做**。实测中一个 LLM 为此做了对照实验
 * （给枚举逐个加 label()/getLabel()/title()/labels() 试），
 * 确认框架「一个都不调用」，最后只能用 `->map()` 绕开。
 */
class EnumColumnTest extends TestCase
{
    public function test_using_resolves_labels_into_props_map(): void
    {
        $node = (new Column('status', '状态'))->using(ProbeStatus::class)->toNode();

        $this->assertSame('enum', $node->formatter);
        $this->assertSame(ProbeStatus::class, $node->enumClass);

        // 关键：标签必须落进 props.map —— 前端只认这个
        $map = (array) $node->props['map'];
        $this->assertSame(['active' => '启用', 'disabled' => '禁用'], $map);
    }

    public function test_without_label_falls_back_to_case_name(): void
    {
        // `Active` 也比 `active` 可读 —— 总比显示原始值强
        $node = (new Column('status', '状态'))->using(ProbeStatusNoLabel::class)->toNode();

        $this->assertSame(['active' => 'Active'], (array) $node->props['map']);
    }

    public function test_explicit_map_wins(): void
    {
        /*
         * 手写的 map 必须优先。
         *
         * ⚠️ 守卫要同时认 stdClass —— `map()` 存的是 stdClass 而不是数组
         * （为了让 JSON 序列化成 `{}` 而不是 `[]`）。第一版只判 is_array，
         * 手写的 map 被枚举标签悄悄覆盖，且不报错。
         */
        $node = (new Column('status', '状态'))
            ->using(ProbeStatus::class)
            ->map(['active' => '我自己写的'])
            ->toNode();

        $this->assertSame(['active' => '我自己写的'], (array) $node->props['map']);
    }

    public function test_explicit_map_wins_regardless_of_order(): void
    {
        $node = (new Column('status', '状态'))
            ->map(['active' => '先写的'])
            ->using(ProbeStatus::class)
            ->toNode();

        $this->assertSame(['active' => '先写的'], (array) $node->props['map']);
    }

    public function test_plain_column_is_untouched(): void
    {
        $node = (new Column('name', '姓名'))->toNode();

        $this->assertArrayNotHasKey('map', $node->props);
    }

    public function test_nonexistent_enum_class_does_not_throw(): void
    {
        /*
         * 声明了不存在的枚举 —— 不该让整页崩掉（编译期另有校验兜底）。
         *
         * 这里**故意**传一个不存在的类名，所以签名上不满足 class-string ——
         * 这正是被测的行为（声明写错时不该在渲染期炸）。
         */
        /** @phpstan-ignore argument.type */
        $node = (new Column('status', '状态'))->using('App\\Enums\\Nope')->toNode();

        $this->assertArrayNotHasKey('map', $node->props);
    }
}

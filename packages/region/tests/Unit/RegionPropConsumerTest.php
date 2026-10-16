<?php

declare(strict_types=1);

namespace Aimanong\Region\Tests\Unit;

use Aimanong\Region\Fields\RegionField;
use Aimanong\Region\RegionExtension;
use PHPUnit\Framework\TestCase;

/**
 * 「声明了但没人消费」门禁（region 版）。
 *
 * ## 为什么需要它
 *
 * 2026-10-16 审计里有一类反复出现的问题：**API 存在、prop 存在、
 * 文档也写了，但没有任何代码读它**。运行期完全看不出来 ——
 * 不报错、不回滚、结果「看起来是对的」，只是不是你要的。
 *
 * region 上就有一个实例：`storeCode()` 全仓库**零消费方**。
 * 字段类注释写「或编码（通过 ->storeCode() 切换）」，
 * 使用者调了它，库里存的照旧是名称路径。
 *
 * ## 机制
 *
 * 拿 `RegionField` 所有**会写进 props 的键**，去插件自己的视图
 * （field.blade / script.blade）里找有没有同名字符串。
 * 一个都没有 → 这个 prop 就是死的，测试红。
 *
 * 这不能证明「消费得对」，但能证明「有人在读」——
 * 而「没人读」正是最难发现的那一半。
 */
class RegionPropConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new RegionExtension)->register();
    }

    /**
     * 视图源码，**已剥掉 blade 注释**。
     *
     * ⚠️ 必须剥注释：注释里解释「这里不该再用 `f.props.level || 3`」
     * 这种话是正常的，但会被「字符串出现过」式的检查误判成代码。
     * （第一次写这条测试就踩了：断言自己写的注释命中了。）
     *
     * @return array<string, string>
     */
    private function viewSources(): array
    {
        $dir = __DIR__.'/../../resources/views';

        $strip = static fn (string $f): string => (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents($dir.'/'.$f)
        );

        return [
            'field.blade.php' => $strip('field.blade.php'),
            'script.blade.php' => $strip('script.blade.php'),
        ];
    }

    /**
     * 字段写进 props 的键，必须至少被一个视图读到。
     */
    public function test_every_prop_has_a_consumer(): void
    {
        $field = (new RegionField('area'))->level(3)->storeCode()->data([]);
        $props = array_keys($field->getProps());
        $sources = $this->viewSources();

        $orphans = [];

        foreach ($props as $prop) {
            $found = false;

            foreach ($sources as $text) {
                if (str_contains($text, $prop)) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $orphans[] = $prop;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            '这些 prop 没有任何视图消费（设了也不生效）：'.implode('、', $orphans)
        );
    }

    /**
     * 反向：`storeCode` 必须真的进了视图的取值逻辑，
     * 而不只是「字符串出现过」（注释里提一嘴也算出现过）。
     */
    public function test_store_code_is_actually_read(): void
    {
        $script = $this->viewSources()['script.blade.php'];

        $this->assertMatchesRegularExpression(
            '/props\s*\.\s*storeCode|\[\s*[\'"]storeCode[\'"]\s*\]/',
            $script,
            'script 里必须真的读 props.storeCode，不能只在注释里提到'
        );
    }

    /**
     * `level` 必须由字段类下发，前端不该用 `|| 3` 猜默认值。
     *
     * 靠 `|| 3` 兜底的坏处：把默认改成 2 之后，前端仍按 3 渲染，
     * 后端以为在 2 —— 症状是「区级下拉莫名出现/消失」，无报错。
     */
    public function test_level_is_sent_and_not_guessed(): void
    {
        $this->assertArrayHasKey('level', (new RegionField('area'))->getProps());

        $blade = $this->viewSources()['field.blade.php'];

        $this->assertStringNotContainsString(
            'f.props.level || 3',
            $blade,
            '前端不该用 || 3 猜默认 level —— 字段类必须下发它'
        );
    }
}

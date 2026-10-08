<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\RequirementChecker;
use Aimanong\Ai\Verifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ValidateDeclaration extends Tool
{
    protected string $description = <<<'MARKDOWN'
        校验一个 Resource 声明是否合法。

        生成或修改 Resource 后必须调用：它会检查
        类是否存在、是否继承 Resource、uri 合法性、model 是否存在、
        以及能否成功编译。

        返回 valid=true 表示可直接使用；false 时会给出 did_you_mean 与修正示例。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $class = (string) $request->string('resource');

        $result = (new Verifier())->verify($class);

        // 需求核对（若提供）
        $requirements = $request->get('requirements');
        $requirementReport = null;

        if (is_array($requirements) && $requirements !== []) {
            $requirementReport = (new RequirementChecker())->check($class, $requirements);
        }

        if ($result['valid']) {
            $lines = [sprintf('✅ %s 语法校验通过。', $class), ''];

            if ($requirementReport !== null) {
                $lines[] = '## 需求核对';
                $lines[] = '';

                foreach ($requirementReport['results'] as $r) {
                    $icon = $r['satisfied'] ? '✅' : '❌';
                    $lines[] = sprintf('%s %s — %s', $icon, $r['requirement'], $r['detail']);

                    if (! $r['satisfied'] && ! empty($r['fix'])) {
                        $lines[] = sprintf('   修正: %s', $r['fix']);
                    }
                }

                $lines[] = '';
                $lines[] = $requirementReport['message'];
            } else {
                $lines[] = '无需修改，语法合法。';
                $lines[] = '';
                $lines[] = '⚠️ 注意：本校验**只检查语法合法性，不检查是否满足你的需求**。';
                $lines[] = '若任务有具体要求（如"某列可搜索"），请传入 requirements 参数做核对，';
                $lines[] = '否则你无法确认任务真的完成了。';
                $lines[] = '';
                $lines[] = 'requirements 示例：';
                $lines[] = '{"searchable": "title,status", "sortable": "price,stock", "required": "title"}';
            }

            return Response::text(implode("\n", $lines));
        }

        $lines = [sprintf('❌ %s 校验未通过（%d 个问题）', $class, $result['issue_count']), ''];

        foreach ($result['issues'] as $i => $issue) {
            $lines[] = sprintf('%d. [%s] %s', $i + 1, $issue['code'], $issue['message']);

            if (! empty($issue['did_you_mean'])) {
                $lines[] = sprintf('   是否想用: %s', $issue['did_you_mean']);
            }
            if (! empty($issue['hint'])) {
                $lines[] = sprintf('   提示: %s', $issue['hint']);
            }
            if (! empty($issue['example'])) {
                $lines[] = sprintf('   示例: %s', $issue['example']);
            }

            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('Resource 类全限定名，如 App\\Aimanong\\UserResource')
                ->required(),
            'requirements' => $schema->object([
                'searchable' => $schema->string()->description('要求可搜索的列，逗号分隔，如 "title,status"'),
                'sortable' => $schema->string()->description('要求可排序的列，逗号分隔'),
                'required' => $schema->string()->description('要求必填的表单字段，逗号分隔'),
                'columns' => $schema->string()->description('要求列表必须包含的列，逗号分隔'),
                'fields' => $schema->string()->description('要求表单必须包含的字段，逗号分隔'),
                'per_page' => $schema->integer()->description('要求的每页条数'),
            ])->description('任务需求清单。强烈建议传入 —— 否则本工具只查语法，不查需求是否达标'),
        ];
    }
}

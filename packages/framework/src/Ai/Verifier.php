<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;

/**
 * 声明校验器。
 *
 * AI 生成 Resource 后的自检入口：
 * 不依赖人肉 review，机器即可判断产出是否合法。
 */
class Verifier
{
    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    public function verify(string $class): array
    {
        $issues = [];

        if (! class_exists($class)) {
            return $this->result($class, false, [[
                'code' => 'CLASS_NOT_FOUND',
                'message' => "类不存在: {$class}",
                'did_you_mean' => $this->suggestClass($class),
                'hint' => 'Resource 应放在 app/Aimanong/ 下，命名 {Name}Resource',
            ]]);
        }

        if (! is_subclass_of($class, \Aimanong\Resource::class)) {
            return $this->result($class, false, [[
                'code' => 'NOT_A_RESOURCE',
                'message' => "{$class} 未继承 Aimanong\\Resource",
                'example' => 'class XxxResource extends Resource',
            ]]);
        }

        // model()
        try {
            $model = $class::model();

            if (! class_exists($model)) {
                $issues[] = [
                    'code' => 'MODEL_NOT_FOUND',
                    'message' => "model() 返回的类不存在: {$model}",
                    'hint' => '请确认 Eloquent 模型已创建',
                ];
            }
        } catch (\Throwable $e) {
            $issues[] = [
                'code' => 'MODEL_NOT_DEFINED',
                'message' => '未实现 model() 方法',
                'example' => 'public static function model(): string { return User::class; }',
            ];
        }

        // uri()
        $uri = $class::uri();
        if ($uri === '' || ! preg_match('/^[a-z0-9\-]+$/', $uri)) {
            $issues[] = [
                'code' => 'INVALID_URI',
                'message' => "uri() 不合法: '{$uri}'",
                'hint' => '只允许小写字母、数字与连字符',
                'example' => "public static function uri(): string { return 'users'; }",
            ];
        }

        // 编译是否成功
        try {
            $node = (new Compiler())->compile($class);

            if ($node->columns === [] && $node->fields === []) {
                $issues[] = [
                    'code' => 'EMPTY_DEFINITION',
                    'message' => 'grid() 与 form() 都未定义任何内容',
                    'hint' => '至少定义 grid() 的列或 form() 的字段',
                    'example' => "public static function grid(Grid \$grid): void { \$grid->column('id', 'ID'); }",
                ];
            }
        } catch (\Throwable $e) {
            $issues[] = [
                'code' => 'COMPILE_FAILED',
                'message' => '编译失败: '.$e->getMessage(),
                'hint' => '检查 grid()/form()/show() 中的语法与类型',
            ];
        }

        // 是否已注册到 Registry
        // 未注册的 Resource 后台根本看不到，但原先能通过全部校验
        $registered = in_array($class, array_values(Aimanong::registry()->all()), true);

        if (! $registered) {
            $issues[] = [
                'code' => 'NOT_REGISTERED',
                'message' => '该 Resource 尚未注册，后台无法访问',
                'hint' => '在 ServiceProvider 的 boot() 中调用 Aimanong::registry()->register('.$class.'::class)',
                'example' => "Aimanong::registry()->register(\\{$class}::class);",
            ];
        }

        return $this->result($class, $issues === [], $issues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    protected function result(string $class, bool $ok, array $issues): array
    {
        return [
            'resource' => $class,
            'valid' => $ok,
            'issues' => $issues,
            'issue_count' => count($issues),
        ];
    }

    /**
     * 类名建议：先精确匹配，再用编辑距离模糊匹配。
     *
     * AI 容易把类名拼错（少个字母、大小写错），
     * 这里给出最相近的已注册 Resource。
     */
    protected function suggestClass(string $class): ?string
    {
        $all = \Aimanong\Aimanong::registry()->all();

        if ($all === []) {
            return null;
        }

        $short = class_basename($class);

        // 精确匹配短名（大小写不敏感，AI 常写成全小写）
        foreach ($all as $registered) {
            if (strcasecmp(class_basename($registered), $short) === 0) {
                return $registered;
            }
        }

        // 模糊匹配：与每个已注册类的短名比编辑距离
        $best = null;
        $shortest = -1;

        foreach ($all as $registered) {
            $candidate = class_basename($registered);
            $lev = levenshtein(strtolower($short), strtolower($candidate));

            // 阈值：距离越小越像；按长度放宽，避免长类名误判
            $threshold = max(2, (int) (strlen($candidate) * 0.3));

            if ($lev <= $threshold && ($lev < $shortest || $shortest < 0)) {
                $best = $registered;
                $shortest = $lev;
            }
        }

        return $best;
    }
}

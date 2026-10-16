<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Exceptions\AiReadableException;
use Aimanong\Resource;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Support\Drivers;
use Aimanong\Support\Str;

/**
 * 声明校验器。
 *
 * AI 生成 Resource 后的自检入口：
 * 不依赖人肉 review，机器即可判断产出是否合法。
 */
class Verifier
{
    /**
     * @param  string  $class  Resource 类全限定名
     * @return array<string, mixed>
     */
    /**
     * 框架已知的 Laravel 规则名。
     *
     * 不是白名单（自定义 Rule 类名也合法）—— 只是用来给出
     * 「你是不是想写 X」的提示，所以是**提示级**而非拒绝级。
     *
     * @var array<int, string>
     */
    protected const KNOWN_RULES = [
        'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric',
        'boolean', 'email', 'url', 'date', 'array', 'json', 'uuid', 'ip',
        'confirmed', 'active_url', 'alpha', 'alpha_dash', 'alpha_num',
        'digits', 'digits_between', 'min', 'max', 'between', 'size',
        'in', 'not_in', 'unique', 'exists', 'regex', 'same', 'different',
        'after', 'before', 'date_format', 'image', 'mimes', 'file',
    ];

    /**
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
            $node = (new Compiler)->compile($class);

            if ($node->columns === [] && $node->fields === []) {
                $issues[] = [
                    'code' => 'EMPTY_DEFINITION',
                    'message' => 'grid() 与 form() 都未定义任何内容',
                    'hint' => '至少定义 grid() 的列或 form() 的字段',
                    'example' => "public static function grid(Grid \$grid): void { \$grid->column('id', 'ID'); }",
                ];
            }

            $issues = array_merge($issues, $this->checkRules($node));
        } catch (\Throwable $e) {
            $issue = [
                'code' => 'COMPILE_FAILED',
                'message' => '编译失败: '.$e->getMessage(),
                'hint' => '检查 grid()/form()/show() 中的语法与类型',
            ];

            /*
             * 若异常本身携带可自愈上下文（如幽灵列的 did_you_mean），
             * 必须原样透出 —— 否则 AI 只看到"编译失败"这种笼统提示，
             * 拿不到"是否想用 teacher_email"这样的关键线索。
             */
            if ($e instanceof AiReadableException) {
                $ctx = $e->context();

                $issue['code'] = $e::errorCode();

                foreach (['did_you_mean', 'available_columns', 'example', 'docs'] as $key) {
                    if (! empty($ctx[$key])) {
                        $issue[$key] = $ctx[$key];
                    }
                }

                if (! empty($ctx['hint'])) {
                    $issue['hint'] = $ctx['hint'];
                }
            }

            $issues[] = $issue;
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

        // 幽灵字段警告（不阻断）：表单字段若既非真实列、也非虚拟属性，
        // 会静默写进四份产物。可能是合法虚拟字段，也可能是漏改的字段。
        foreach ($this->suspectFields($class) as $name) {
            $issues[] = [
                'code' => 'SUSPECT_FIELD',
                'message' => "表单字段 '{$name}' 既不是数据库列，也未定义为模型属性/关系",
                'hint' => '若这是有意的虚拟字段，可忽略；否则请检查是否拼写错误或漏建字段',
                'example' => '// 确认无误则忽略；否则改为真实列名',
            ];
        }

        return $this->result($class, $issues === [], $issues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    /**
     * 校验声明的验证规则能不能被 Laravel 认出来。
     *
     * ## 为什么必须在这里拦
     *
     * 实测（L1/L2 复测）：一个 agent 写
     *
     *     ->rules('nullable|in:active,disabled')
     *
     * 提交时 **500**：`Method Illuminate\Validation\Validator::validateNullable|in does not exist.`
     *
     * Laravel 对**数组**里的规则不做管道拆分 —— 整串会被当成一个规则**名**。
     * 而 `ai:verify` 当时照样报 `valid: true`：**声明层面完全看不出问题**，
     * 只有真发请求才炸。
     *
     * 这和「枚举标签到不了前端」是同一族：`ai:verify` 只能证明
     * 「声明合法」，证明不了「声明真的能跑」。
     * 能提前发现的那部分，就该提前发现。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function checkRules(ResourceNode $node): array
    {
        $issues = [];

        foreach ($node->fields as $field) {
            foreach ($field->rules as $rule) {
                $rule = (string) $rule;

                // 情形一：数组元素里还带 `|` —— 必然 500
                if (str_contains($rule, '|')) {
                    $issues[] = [
                        'code' => 'RULE_NOT_SPLIT',
                        'message' => "字段 [{$field->name}] 的规则 '{$rule}' 没有被拆开，提交时会 500"
                            .'（Laravel 对数组里的规则不做管道拆分）',
                        'hint' => '用 ->rules([...]) 逐个写，或直接写字符串 ->rules(\'a|b\')（框架会拆）',
                        'example' => "\$form->text('x')->rules(['nullable', 'in:a,b']);",
                    ];

                    continue;
                }

                // 情形二：规则名不认识 —— 十有八九是拼错或写法不对
                $base = explode(':', $rule, 2)[0];

                if ($base === '' || in_array($base, self::KNOWN_RULES, true)) {
                    continue;
                }

                $issues[] = [
                    'code' => 'UNKNOWN_RULE',
                    'message' => "字段 [{$field->name}] 用了框架不认识的规则 '{$rule}'",
                    'did_you_mean' => $this->suggestRule($base),
                    'known_rules' => self::KNOWN_RULES,
                    'hint' => '若这是自定义规则类名（如 App\\Rules\\Xxx），忽略本条即可',
                ];
            }
        }

        return $issues;
    }

    /** 拼错的规则名给个最近匹配 */
    protected function suggestRule(string $base): ?string
    {
        $best = null;
        $score = 0.0;

        foreach (self::KNOWN_RULES as $candidate) {
            $percent = 0.0;
            similar_text($base, $candidate, $percent);

            if ($percent > $score) {
                $score = $percent;
                $best = $candidate;
            }
        }

        return $score >= 65.0 ? $best : null;
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
        $all = Aimanong::registry()->all();

        if ($all === []) {
            return null;
        }

        $short = Str::classBasename($class);

        // 精确匹配短名（大小写不敏感，AI 常写成全小写）
        foreach ($all as $registered) {
            if (strcasecmp(Str::classBasename($registered), $short) === 0) {
                return $registered;
            }
        }

        // 模糊匹配：与每个已注册类的短名比编辑距离
        $best = null;
        $shortest = -1;

        foreach ($all as $registered) {
            $candidate = Str::classBasename($registered);
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

    /**
     * 找出"既非真实列、也非虚拟属性"的表单字段。
     *
     * 这类字段会静默写进 schema.json / types.ts / ai-context.md，
     * 可能是合法的虚拟字段，也可能是漏改的字段 —— 因此仅警告不阻断。
     *
     * @return array<int, string>
     */
    protected function suspectFields(string $class): array
    {
        try {
            $model = $class::model();

            if (! class_exists($model)) {
                return [];
            }

            /*
             * 走 `SchemaIntrospector` 契约，而不是 Eloquent / Schema 门面 ——
             * 这样同一份校验逻辑在 Laravel 与 ThinkPHP 上都成立。
             *
             * 缺驱动时返回空（与内核里「内省缺驱动不抛、跳过」的语义一致）：
             * 拿不到真实列就无从判断幽灵列，宁可跳过也不误报。
             */
            $introspector = Drivers::introspector();

            if ($introspector === null) {
                return [];
            }

            $instance = new $model;
            $table = $introspector->tableOf($instance);

            if ($table === null || ! $introspector->hasTable($table)) {
                return [];
            }

            $real = $introspector->columnsOf($table);
            /** @var class-string<\Aimanong\Contracts\Resource> $class */
            $node = (new Compiler)->compile($class);
        } catch (\Throwable) {
            return [];
        }

        $suspects = [];

        foreach ($node->fields as $f) {
            // 纯展示字段不参与数据校验：
            // divider（分组标题）/ display 没有对应数据列，hidden 亦同
            if (in_array($f->type, ['divider', 'display'], true) || $f->hidden) {
                continue;
            }

            /*
             * 一个字段对应**多个**真实列的，逐个检查它们，
             * 而不是检查字段名本身。
             *
             * 典型是 daterange：它对应 start_at / end_at 两列，
             * 「period」这个名字在表里当然不存在 —— 不特判就会
             * 报一个看似莫名其妙的 SUSPECT_FIELD('period')，
             * 掩盖真正的问题（比如 start_at 拼错了）。
             */
            if ($f->type === 'daterange') {
                foreach (['startColumn', 'endColumn'] as $key) {
                    $column = $f->props[$key] ?? null;

                    if (! is_string($column) || $column === '') {
                        continue;
                    }

                    if (! in_array($column, $real, true)) {
                        $suspects[] = $column.'（来自 '.$f->name.' 的 daterange）';
                    }
                }

                continue;
            }

            if (in_array($f->name, $real, true)) {
                continue;
            }

            // 排除虚拟属性（访问器 / casts / 关系）—— 同样走契约。
            // 两端写法不同：Laravel 是 hasGetMutator/hasCast，
            // ThinkPHP 是 getXxxAttr() 方法与 $type 声明，
            // 由各自的 SchemaIntrospector 判断。
            if ($introspector->isVirtualAttribute($instance, $f->name)) {
                continue;
            }

            $suspects[] = $f->name;
        }

        return $suspects;
    }
}

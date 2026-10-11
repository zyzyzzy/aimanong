<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\Capabilities;
use Aimanong\Support\FieldType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 文档检索（本地知识，无需联网）。
 */
class SearchDocs extends Tool
{
    protected string $description = <<<'MARKDOWN'
        在框架内置文档中检索用法（字段类型、列选项、表单选项、校验规则、铁律）。

        本地检索，无需联网。不确定某个 API 怎么用就调它。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $query = strtolower((string) $request->string('query'));

        $sections = [
            '铁律' => [
                '方法签名唯一，不存在重载，不要猜第二种写法',
                '不嵌套闭包，所有配置走链式调用',
                '字段必须是数据库真实字段或已定义访问器',
                '状态类字段用 PHP Enum + ->options(Enum::cases())',
                '生成后必须调用 validate_declaration',
                '不要写前端代码 —— 前端由 Schema 自动渲染',
            ],
            '字段类型' => array_map(
                fn (string $t): string => sprintf('%s → JSON 类型 %s', $t, FieldType::toJsonType($t)),
                FieldType::all()
            ),
            /*
             * 列选项与表单选项一律从 Capabilities 取数，不在此硬编码。
             * 教训：M4 新增 bool()/money() 等展示器时只改了 Capabilities，
             * 此处仍是旧的 8 项 —— 导致 search-docs 与 capabilities.json
             * 数据分叉，AI 若只信前者就找不到新能力。
             */
            '列选项' => $this->columnOptions(),
            '表单选项' => $this->formOptions(),
            '树形结构' => $this->treeOptions(),
            '分步表单' => [
                '声明方式：$form->step(\'步骤标题\'); 后续字段自动归属该步骤',
                '例：$form->step(\'基本信息\'); $form->text(\'name\'); $form->step(\'联系方式\'); $form->email(\'email\');',
                '字段归属由声明顺序决定 —— 扁平、无嵌套闭包（框架铁律）',
            ],
            '只读资源' => $this->foundationOptions('readonly'),
            '基座功能' => $this->foundationOptions('foundation'),
            '扩展与多应用' => $this->extensionAndApplicationOptions(),
            '需求核对' => $this->requirementKeys(),
            '工作流' => [
                '1. list_resources 了解项目',
                '2. describe_resource 看字段',
                '3. scaffold_resource 从表生成（推荐）',
                '4. validate_declaration 校验',
                '5. run_verify 整体自检',
            ],
        ];

        if ($query === '') {
            $out = ['# Aimanong 文档', ''];
            foreach ($sections as $title => $items) {
                $out[] = "## {$title}";
                foreach ($items as $item) {
                    $out[] = "- {$item}";
                }
                $out[] = '';
            }

            return Response::text(implode("\n", $out));
        }

        $out = ["# 检索: {$query}", ''];
        $hit = 0;

        /*
         * 分词后再匹配。
         *
         * ## 为什么不能整串匹配
         *
         * 第六轮实测发现：AI 拿到的需求是**中文业务语言**，
         * 它会直接拿需求里的词去查，而整串 `str_contains` 只认
         * 「文档里恰好出现过这一整串」——
         *
         *   查「多图上传」→ 未找到（文档里写的是 `images()`）
         *   查「图片」    → 命中
         *
         * 需求里几乎不会刚好写成一个 API 名，所以整串匹配对
         * 「用业务语言发现能力」这个核心场景基本无效。
         *
         * 改为按词匹配：中文按 2-gram 切、英文/符号按空格与标点切，
         * 任一词命中即算命中，并按命中词数排序（最相关的排前面）。
         *
         * 保留「整串优先」：整串命中说明是精确查询，置顶。
         */
        $terms = $this->tokenize($query);
        $scored = [];

        foreach ($sections as $title => $items) {
            foreach ($items as $item) {
                $haystack = strtolower($item.' '.$title);
                $score = 0;

                if (str_contains($haystack, $query)) {
                    $score += 100;   // 整串命中，权重最高
                }

                foreach ($terms as $term) {
                    if (str_contains($haystack, $term)) {
                        $score++;
                    }
                }

                if ($score > 0) {
                    $scored[] = ['title' => $title, 'item' => $item, 'score' => $score];
                }
            }
        }

        if ($scored === []) {
            return Response::text(sprintf(
                "未找到与 '%s' 相关的内容。\n\n可检索的主题: %s",
                $query,
                implode(' / ', array_keys($sections))
            ));
        }

        // 相关度降序，同分保持原始顺序（稳定排序）
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $bySection = [];
        foreach ($scored as $row) {
            $bySection[$row['title']][] = $row['item'];
        }

        foreach ($sections as $title => $items) {
            if (! isset($bySection[$title])) {
                continue;
            }

            $out[] = "## {$title}";
            foreach ($bySection[$title] as $m) {
                $out[] = "- {$m}";
                $hit++;
            }
            $out[] = '';
        }

        return Response::text(implode("\n", $out));
    }

    /**
     * 检索停用词。
     *
     * 只放「词面高频但语义泛化」的词 —— 它们出现在几乎任何句子和
     * 任何文档里，保留只会制造假阳性。刻意保持很短：
     * 过度停用会让正常查询查不到，那是另一种失败。
     *
     * @var array<int, string>
     */
    protected const STOP_WORDS = [
        '不存在', '不能', '不会', '不要', '不是',
        '可以', '需要', '进行', '如果', '因为', '所以', '但是',
        '什么', '怎么', '这个', '那个', '一个', '东西',
    ];

    /**
     * 把查询串切成检索词。
     *
     * - 英文/数字/符号：按空白与常见标点切
     * - 中文：按 2-gram 切（「多图上传」→ 多图 / 图上 / 上传）
     *
     * 2-gram 是个刻意粗糙的选择：中文没有空格，做真正分词要引依赖；
     * 而 2-gram 对「上传」「字典」「日志」这类双字术语命中率已经足够，
     * 且不会把「多图上传」这种四字组合漏掉。
     *
     * @return array<int, string>
     */
    protected function tokenize(string $query): array
    {
        $terms = [];

        // 英文与数字：按非字母数字切
        foreach (preg_split('/[^a-z0-9_]+/u', $query) ?: [] as $part) {
            if ($part !== '' && mb_strlen($part) >= 2) {
                $terms[] = $part;
            }
        }

        /*
         * 中文：整块 + 2-gram（滤掉停用词）。
         *
         * ⚠️ 只切 2-gram 是不够的：查「报修留痕」会切出
         * 报修 / 修留 / 留痕 —— 其中「修留」是**跨词边界的假词**，
         * 而文档里真正存在的词是「留痕」。若只靠 2-gram，
         * 这个查询会**因为假词没命中而整条失败**（实测踩到）。
         *
         * 所以额外保留：
         *   - 整个中文块（若文档里恰好有这个词组）
         *   - 每个 2-gram（覆盖「多图上传」→ 上传 这类）
         */
        $han = preg_replace('/[^\p{Han}]+/u', ' ', $query) ?? '';
        foreach (preg_split('/\s+/u', trim($han)) ?: [] as $block) {
            if ($block === '') {
                continue;
            }

            $terms[] = $block;

            $len = mb_strlen($block);

            /*
             * 用滑动 2-gram，但**跳过落在停用词内部的 gram**。
             *
             * 停用词表按「完整停用词」列（如「不存在」），
             * 匹配时看整块里是否出现它 —— 命中则整段跳过，
             * 而不是逐个 gram 比对。
             *
             * 原因：「不存在」会切出 不存 / 存在 两个 gram，
             * 逐个比对要同时列两条，且「存在」单独看又是正常词
             * （「字典存在」「文件存在」）。按整词跳过才不会误伤。
             */
            $skip = [];
            foreach (self::STOP_WORDS as $word) {
                $offset = 0;
                while (($pos = mb_strpos($block, $word, $offset)) !== false) {
                    for ($k = $pos; $k < $pos + mb_strlen($word); $k++) {
                        $skip[$k] = true;
                    }
                    $offset = $pos + 1;
                }
            }

            for ($i = 0; $i < $len - 1; $i++) {
                // 起始位落在停用词内 → 这个 gram 不可靠，跳过
                if (isset($skip[$i]) || isset($skip[$i + 1])) {
                    continue;
                }

                $terms[] = mb_substr($block, $i, 2);
            }
        }

        return array_values(array_unique($terms));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('检索关键词，留空则返回全部文档'),
        ];
    }

    /**
     * 树形结构选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function treeOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->treeOptions() as $name => $desc) {
            $out[] = "tree()->{$name}() {$desc}";
        }

        return $out;
    }

    /**
     * validate_declaration 支持的 requirements 键 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function requirementKeys(): array
    {
        $out = ['传给 validate_declaration 的 requirements 参数，支持以下键：'];

        foreach ((new Capabilities)->requirementKeys() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        $out[] = '注意：不认识的键会被报错，不会静默忽略';

        return $out;
    }

    /**
     * 扩展与多应用选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function extensionAndApplicationOptions(): array
    {
        $caps = new Capabilities;

        $out = ['【扩展（插件）】'];
        foreach ($caps->extensionCapabilities() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        $out[] = '【多应用】';
        foreach ($caps->applicationCapabilities() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        return $out;
    }

    /**
     * 列选项 —— 从 Capabilities 取数，保证与 /__ai/capabilities.json 同源。
     *
     * @return array<int, string>
     */
    protected function columnOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->columnOptions() as $name => $desc) {
            $out[] = "{$name}() {$desc}";
        }

        return $out;
    }

    /**
     * 基座能力（P0）—— 从 Capabilities 取数。
     *
     * `readonly` 单列一节，因为它是**声明写法**，
     * AI 需要能一眼找到「怎么写」；其余是「已经存在、不要重复造」的告知。
     *
     * @return array<int, string>
     */
    protected function foundationOptions(string $mode): array
    {
        $foundation = (new Capabilities)->foundationCapabilities();
        $out = [];

        foreach ($foundation as $key => $info) {
            if ($mode === 'readonly' && $key !== 'readonly') {
                continue;
            }

            if ($mode === 'foundation' && $key === 'readonly') {
                continue;
            }

            $line = "{$key}() {$info['summary']}";

            if (isset($info['enabled'])) {
                $line .= $info['enabled'] ? '（已启用）' : '（已关闭）';
            }

            $out[] = $line;

            foreach ([
                'example', 'detail', 'auto', 'excludes', 'when', 'use_when',
                'config', 'uri', 'uris', 'declare', 'two_sources', 'colors', 'cache',
                // 业务同义词：让「用业务语言提问的人」也能检索到
                'also_known_as',
            ] as $field) {
                if (! isset($info[$field])) {
                    continue;
                }

                $value = $info[$field];

                // uris 这类是数组，要展开成可读文本，否则 AI 拿不到具体 URI
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }

                if (is_string($value)) {
                    $out[] = "  - {$field}: {$value}";
                }
            }
        }

        return $out;
    }

    /**
     * 表单选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function formOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->formOptions() as $name => $desc) {
            $out[] = "{$name}() {$desc}";
        }

        return $out;
    }
}

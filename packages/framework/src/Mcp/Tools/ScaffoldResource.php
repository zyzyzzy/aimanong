<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 从数据库表一键生成 Resource 代码。
 *
 * 这是 AI 最省事的路径：不用猜字段，直接读表结构生成。
 * 用 Laravel 12 的原生 Schema API（doctrine/dbal 已被移除）。
 */
class ScaffoldResource extends Tool
{
    protected string $description = <<<'MARKDOWN'
        从数据库表结构生成 Aimanong Resource 的 PHP 代码。
        推荐优先使用此工具：它直接读取真实表结构，不会猜错字段名。

        生成后仍需用 validate_declaration 校验，并把文件保存到 app/Aimanong/。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $table = trim((string) $request->string('table'));

        // string() 返回 Stringable（缺失时是空对象，永不为 null），
        // 必须转字符串再判空 —— 与 null 比较恒为 true。
        $modelInput = trim((string) $request->string('model'));
        $model = $modelInput !== ''
            ? $modelInput
            : 'App\\Models\\'.Str::studly(Str::singular($table));

        try {
            $columns = Schema::getColumns($table);
        } catch (\Throwable $e) {
            return Response::text(sprintf(
                "读取表 [%s] 失败: %s\n\n提示：确认表存在且数据库连接正常。",
                $table,
                $e->getMessage()
            ));
        }

        if ($columns === []) {
            return Response::text("表 [{$table}] 没有字段，或表不存在。");
        }

        $className = Str::studly(Str::singular($table)).'Resource';
        $uri = Str::kebab(Str::plural($table));
        // 注意：string() 返回 Stringable 对象，?: 判断恒为 true，
        // 必须先转字符串再判空。
        $label = trim((string) $request->string('label'));
        $label = $label === '' ? $table : $label;

        $gridLines = [];
        $formLines = [];

        foreach ($columns as $col) {
            $name = $col['name'];

            if (in_array($name, ['id', 'created_at', 'updated_at'], true)) {
                $gridLines[] = $name === 'id'
                    ? "        \$grid->column('id', 'ID')->sortable();"
                    : "        \$grid->column('{$name}', '{$this->zh($name)}')->dateTime()->sortable();";

                continue;
            }

            if (str_ends_with($name, '_id')) {
                $gridLines[] = "        \$grid->column('{$name}', '{$this->zh($name)}')->sortable();";
                $formLines[] = "        \$form->number('{$name}')->label('{$this->zh($name)}');";

                continue;
            }

            // 列生成：数据库 boolean 类型优先，其次按语义选择展示器
            $colType = strtolower((string) ($col['type'] ?? 'string'));
            $semantic = $this->semanticKind($name);
            $isBool = str_contains($colType, 'bool') || $semantic === 'bool';

            $gridLines[] = match (true) {
                $isBool => "        \$grid->column('{$name}', '{$this->zh($name)}')->bool()->sortable();",
                $semantic === 'status' => "        \$grid->column('{$name}', '{$this->zh($name)}')->badge()->sortable();",
                $semantic === 'money' => "        \$grid->column('{$name}', '{$this->zh($name)}')->money()->sortable();",
                in_array($name, ['name', 'title'], true) => "        \$grid->column('{$name}', '{$this->zh($name)}')->searchable();",
                str_ends_with($name, '_at') => "        \$grid->column('{$name}', '{$this->zh($name)}')->dateTime()->sortable();",
                default => "        \$grid->column('{$name}', '{$this->zh($name)}');",
            };

            $formLines[] = $this->formLine($col, $name);
        }

        $showNames = array_slice(array_column($columns, 'name'), 0, 5);
        $showList = implode("', '", $showNames);

        $code = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Aimanong;

        use Aimanong\\Form\\Form;
        use Aimanong\\Grid\\Grid;
        use Aimanong\\Resource;
        use Aimanong\\Show\\Show;
        use {$model};

        class {$className} extends Resource
        {
            public static function model(): string
            {
                return {$this->classBasename($model)}::class;
            }

            public static function label(): string
            {
                return '{$label}';
            }

            public static function uri(): string
            {
                return '{$uri}';
            }

            public static function grid(Grid \$grid): void
            {
        {$this->join($gridLines)}
            }

            public static function form(Form \$form): void
            {
        {$this->join($formLines)}
            }

            public static function show(Show \$show): void
            {
                \$show->fields(['{$showList}']);
            }
        }

        PHP;

        $code = str_replace('        <?php', '<?php', $code);

        return Response::text(sprintf(
            "已根据表 [%s] 生成 Resource 代码（共 %d 个字段）。\n\n"
            ."保存为: app/Aimanong/%s.php\n"
            ."并在 ServiceProvider 中注册: Aimanong::registry()->register(App\\Aimanong\\%s::class);\n\n"
            ."然后运行 validate_declaration 校验。\n\n"
            ."```php\n%s```",
            $table,
            count($columns),
            $className,
            $className,
            $code
        ));
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function formLine(array $col, string $name): string
    {
        $type = strtolower((string) ($col['type'] ?? 'string'));
        $label = $this->zh($name);
        $nullable = (bool) ($col['nullable'] ?? false);
        $required = $nullable ? '' : '->required()';

        /*
         * 数据库类型优先：boolean 是列语义最硬的依据，
         * 比列名推断更可靠（如 resolved 从名字看不出是布尔）。
         */
        if (str_contains($type, 'bool')) {
            return "        \$form->switch('{$name}')->label('{$label}');";
        }

        // 其次按「语义列名」判断 —— 比单纯看类型更贴合业务用途。
        // AI 实测反馈：status 类字段被生成为 text，列表显示原始值不直观。
        $semantic = $this->semanticKind($name);

        if ($semantic === 'status') {
            // 状态类：生成 select + 占位选项，并提示 AI 按业务补全
            return "        \$form->select('{$name}')->label('{$label}')->options([\n"
                ."            // TODO: 按业务补全选项，例如：\n"
                ."            // 'active' => '启用',\n"
                ."            // 'disabled' => '禁用',\n"
                ."        ]){$required};";
        }

        if ($semantic === 'bool') {
            return "        \$form->switch('{$name}')->label('{$label}');";
        }

        if ($semantic === 'money') {
            return "        \$form->money('{$name}')->label('{$label}'){$required};";
        }

        if ($semantic === 'email') {
            return "        \$form->email('{$name}')->label('{$label}'){$required}->rules('email');";
        }

        if ($semantic === 'url') {
            return "        \$form->url('{$name}')->label('{$label}'){$required};";
        }

        if ($semantic === 'phone') {
            return "        \$form->tel('{$name}')->label('{$label}'){$required};";
        }

        // 回退到按数据库类型推断（boolean 已在上方处理，此处不再判断）
        $method = match (true) {
            str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 'decimal',
            str_contains($type, 'int') => 'number',
            str_contains($type, 'text') => 'textarea',
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => 'datetime',
            str_contains($type, 'date') => 'date',
            str_contains($type, 'time') => 'time',
            default => 'text',
        };

        if ($method === 'text') {
            return "        \$form->text('{$name}')->label('{$label}'){$required}->max(255);";
        }

        if ($method === 'textarea') {
            return "        \$form->textarea('{$name}')->label('{$label}')->rows(4);";
        }

        return "        \$form->{$method}('{$name}')->label('{$label}'){$required};";
    }

    /**
     * 按列名推断语义类型 —— 比数据库类型更能反映真实用途。
     *
     * 注意：调用方应先检查数据库类型（boolean 是最硬的依据），
     * 本方法只处理"类型看不出用途"的情况。
     *
     * @return string|null status | bool | money | email | url | phone
     */
    protected function semanticKind(string $name): ?string
    {
        $n = strtolower($name);

        // 布尔类：优先识别 is_/has_ 前缀与常见布尔列名
        if (str_starts_with($n, 'is_') || str_starts_with($n, 'has_')) {
            return 'bool';
        }

        foreach (['enabled', 'disabled', 'active', 'published', 'visible', 'verified',
            'locked', 'resolved', 'closed', 'finished', 'completed', 'deleted'] as $kw) {
            if ($n === $kw) {
                return 'bool';
            }
        }

        // 状态类：枚举值的字符串列
        foreach (['status', 'state', 'stage', 'type', 'kind', 'category', 'level', 'priority'] as $kw) {
            if ($n === $kw || str_ends_with($n, '_'.$kw)) {
                return 'status';
            }
        }

        // 金额类
        if (str_contains($n, 'price') || str_contains($n, 'amount') || str_contains($n, 'money')
            || str_contains($n, 'fee') || str_contains($n, 'cost')) {
            return 'money';
        }

        if ($n === 'email' || str_ends_with($n, '_email')) {
            return 'email';
        }

        if (str_ends_with($n, '_url') || str_ends_with($n, '_link') || $n === 'url') {
            return 'url';
        }

        if (str_contains($n, 'phone') || str_contains($n, 'mobile') || str_contains($n, 'tel')) {
            return 'phone';
        }

        return null;
    }

    /**
     * 列名 → 中文标签。
     *
     * 先用精确匹配，再用后缀模式匹配（如 user_name → 用户名）。
     */
    protected function zh(string $name): string
    {
        $exact = [
            // 主键与时间
            'id' => 'ID',
            'uid' => '用户ID',
            'user_id' => '用户',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
            'deleted_at' => '删除时间',
            'published_at' => '发布时间',
            'paid_at' => '支付时间',
            'closed_at' => '关闭时间',
            'started_at' => '开始时间',
            'finished_at' => '完成时间',

            // 常见业务字段
            'name' => '名称',
            'title' => '标题',
            'subject' => '主题',
            'body' => '内容',
            'content' => '内容',
            'description' => '描述',
            'summary' => '摘要',
            'remark' => '备注',
            'note' => '备注',
            'keyword' => '关键词',
            'tags' => '标签',

            // 编号类
            'no' => '编号',
            'code' => '编码',
            'sn' => '序列号',
            'order_no' => '订单号',
            'ticket_no' => '工单号',
            'product_no' => '商品编号',

            // 状态类
            'status' => '状态',
            'state' => '状态',
            'stage' => '阶段',
            'type' => '类型',
            'kind' => '种类',
            'category' => '分类',
            'level' => '级别',
            'priority' => '优先级',
            'sort' => '排序',
            'weight' => '权重',

            // 布尔类
            'enabled' => '是否启用',
            'disabled' => '是否禁用',
            'active' => '是否激活',
            'published' => '是否发布',
            'visible' => '是否可见',
            'verified' => '是否已验证',
            'locked' => '是否锁定',
            'resolved' => '是否解决',
            'closed' => '是否关闭',
            'finished' => '是否完成',

            // 金额与数量
            'price' => '价格',
            'amount' => '金额',
            'total' => '合计',
            'money' => '金额',
            'fee' => '费用',
            'cost' => '成本',
            'quantity' => '数量',
            'count' => '数量',
            'stock' => '库存',
            'num' => '数量',

            // 联系方式
            'email' => '邮箱',
            'phone' => '电话',
            'mobile' => '手机',
            'tel' => '电话',
            'address' => '地址',
            'avatar' => '头像',
            'url' => '链接',
            'link' => '链接',
            'image' => '图片',
            'photo' => '图片',
            'icon' => '图标',

            // 人员
            'author' => '作者',
            'owner' => '负责人',
            'assignee' => '处理人',
            'creator' => '创建人',
            'operator' => '操作人',
        ];

        if (isset($exact[$name])) {
            return $exact[$name];
        }

        // 后缀模式：xxx_name → xxx名称
        $suffixMap = [
            '_name' => '名称',
            '_title' => '标题',
            '_no' => '编号',
            '_code' => '编码',
            '_status' => '状态',
            '_type' => '类型',
            '_id' => 'ID',
            '_at' => '时间',
            '_url' => '链接',
            '_image' => '图片',
            '_count' => '数量',
        ];

        foreach ($suffixMap as $suffix => $label) {
            if (str_ends_with($name, $suffix)) {
                $prefix = substr($name, 0, -strlen($suffix));

                // 前缀也尝试翻译，拼成「用户名」这类标签
                $prefixZh = $this->zhPrefix($prefix);

                return $prefixZh.$label;
            }
        }

        // 无法翻译时回退原名 —— 比给错误的中文更好
        return $name;
    }

    /**
     * 翻译列名前缀（用于 user_name → 用户名 这类组合）。
     */
    protected function zhPrefix(string $prefix): string
    {
        return [
            'user' => '用户',
            'order' => '订单',
            'product' => '商品',
            'ticket' => '工单',
            'customer' => '客户',
            'member' => '会员',
            'account' => '账户',
            'company' => '公司',
            'department' => '部门',
            'project' => '项目',
            'task' => '任务',
            'article' => '文章',
            'post' => '帖子',
            'comment' => '评论',
            'payment' => '支付',
            'invoice' => '发票',
            'shipment' => '发货',
            'refund' => '退款',
        ][$prefix] ?? '';
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function join(array $lines): string
    {
        return $lines === [] ? '        // 无字段' : implode("\n", $lines);
    }

    protected function classBasename(string $fqcn): string
    {
        return class_basename($fqcn);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('数据库表名，如 users')
                ->required(),
            'model' => $schema->string()
                ->description('Eloquent 模型全限定类名，默认按表名推导'),
            'label' => $schema->string()
                ->description('中文名称，默认用表名'),
        ];
    }
}

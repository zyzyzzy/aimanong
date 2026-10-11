<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Form\Fields\Field;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Schema\Compiler;
use Aimanong\Support\FieldType;
use Aimanong\Ui\ThemeConfig;

/**
 * 能力清单。
 *
 * 这是 AI 的"记忆体"：框架能完整回答"我有什么能力"，
 * 而不只是"我有哪些类"。AI 不需要猜 API，直接读这个。
 */
class Capabilities
{
    /**
     * 读布尔配置，**无容器时退化为默认值**。
     *
     * 能力清单要能在单元测试、纯静态自省（不启动 Laravel）下被读取 ——
     * 直接调 config() 在没有 Application 实例时会抛
     * `ReflectionException: Class "config" does not exist`。
     *
     * 注意：只对「有没有开关」这类展示性信息容错，
     * 业务逻辑里的 config() 不许这么写（那会掩盖真实配置错误）。
     */
    protected function configFlag(string $key, bool $default = true): bool
    {
        try {
            return (bool) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 完整能力清单。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => Aimanong::version(),
            'framework' => [
                'name' => 'Aimanong',
                'label' => 'AI 码农',
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'resources' => $this->resources(),
            'field_types' => $this->fieldTypes(),
            'tree_options' => $this->treeOptions(),
            'extension' => $this->extensionCapabilities(),
            'requirement_keys' => $this->requirementKeys(),
            'cannot_verify' => $this->cannotVerify(),
            'scope_hooks' => ScopeHooks::introspect(),
            'rbac' => $this->rbacCapabilities(),
            'ui' => ThemeConfig::introspect(),
            'menu' => [
                'how' => 'Resource 覆盖 menu() 方法声明菜单（可选，不实现也有默认菜单）',
                'keys' => 'group（分组）| icon（图标）| sort（排序，小的在前）| label（显示名）| visible（是否显示）',
                'example' => "public static function menu(): array { return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10]; }",
                'permission_filter' => 'RBAC 开启时自动按「index」权限过滤，未授权的菜单不显示',
                'introspect' => 'GET /__ai/menu 返回当前用户可见的菜单树',
            ],
            'query_columns' => $this->queryColumnRules(),
            'applications' => $this->applicationCapabilities(),
            'column_options' => $this->columnOptions(),
            'form_options' => $this->formOptions(),
            'rules' => $this->availableRules(),
            'foundation' => $this->foundationCapabilities(),
            /*
             * 项目里**实际存在**的字典与可选值。
             *
             * 这是数据字典对 AI 最大的价值：AI 不再需要翻代码猜
             * 「状态有哪些值」，自省一次就拿到完整枚举。
             */
            'dictionaries' => (new Dictionary)->all(),
        ];
    }

    /**
     * 基座能力（P0）—— 框架内置、用户一行代码都不用写的能力。
     *
     * 这是「带地基的平台」在 AI 侧的表达：AI 必须知道
     * 「操作日志已经存在，不要自己再建一张表」。
     *
     * 单一来源：search-docs 与 aimanong:docs 都从这里取数。
     *
     * @return array<string, array<string, mixed>>
     */
    public function foundationCapabilities(): array
    {
        $operationOn = $this->configFlag('aimanong.foundation.operation_log.enable');
        $loginOn = $this->configFlag('aimanong.foundation.login_log.enable');

        return [
            'readonly' => [
                'summary' => 'Resource::readonly() 声明只读资源',
                'example' => 'public static function readonly(): bool { return true; }',
                'detail' => '返回 true 时：前端隐藏「新增/编辑/删除」入口，'
                    .'store/update/destroy 一律 403，权限节点只生成 index/show/export。',
                'when' => '审计日志、监控、报表快照这类「只应由系统写入」的数据',
            ],
            'operation_log' => [
                'summary' => '操作日志：谁在什么时候改了哪条数据',
                'enabled' => $operationOn,
                'uri' => 'admin-operation-logs',
                'auto' => '框架自动记录写操作（POST/PUT/PATCH/DELETE），无需声明',
                'excludes' => 'GET/HEAD/OPTIONS 不记；密码类字段自动掩码为 ******',
                'config' => "config('aimanong.foundation.operation_log')",
                'use_when' => '数据对不上时，**先查这张表** —— 它是唯一的客观依据',
            ],
            'upload' => [
                'summary' => '文件上传：单图/多图/单文件/多文件四种字段，落盘与 URL 解析统一走 Uploader',
                'enabled' => $this->configFlag('aimanong.foundation.upload.enable'),
                'declare' => "\$form->image('cover'); \$form->images('gallery'); "
                    ."\$form->file('contract')->accept('pdf'); \$form->files('docs');",
                'field_types' => ['image', 'images'],
                'file_field_types' => ['file', 'files'],
                'store_form' => '数据库里存**相对路径**（uploads/2026/10/合同-ab12cd34.pdf），'
                    .'不是完整 URL —— 换域名/换 CDN 不用刷数据；多图/多文件存 JSON 数组',
                'naming' => '落盘名 = 可读原名 + 8 位随机后缀。纯随机名用户认不出文件；'
                    .'只用原名会静默覆盖同名文件。文件名全部服务端生成，客户端名不参与路径',
                'serve' => "config('aimanong.foundation.upload.serve')："
                    .'auto（本地磁盘走框架路由，免 storage:link）/ route（需登录）/ url（CDN）',
                'security' => '扩展名 + 真实 MIME 双白名单；默认不含 svg'
                    .'（SVG 可内嵌 script，同源读取等于 XSS）',
                'use_when' => '需要图片/附件字段时 —— 不要自己写上传控制器',
            ],
            'dict' => [
                'summary' => '数据字典：把枚举变成一等公民，AI 能自省有哪些可选值',
                'enabled' => $this->configFlag('aimanong.foundation.dict.enable'),
                'uris' => ['admin-dict-types', 'admin-dict-items'],
                'declare' => "\$form->select('status')->dict('order_status');  "
                    ."\$grid->column('status', '状态')->dict('order_status');",
                'two_sources' => '代码声明（config foundation.dict.declarations，优先级高、后台只读）'
                    .' 与数据库字典（后台可维护）；读取只走 Dictionary 一个入口，因此不算分叉',
                'colors' => '字典条目可带 color（success/danger/warning/info/muted），'
                    .'列表徽章直接用字典颜色，不再靠关键词猜',
                'cache' => "config('aimanong.foundation.dict.cache_ttl')，写库自动失效",
                'use_when' => '同一个枚举要在列表、表单、导出多处出现时 —— '
                    .'不要在各处重复写 options()/map()',
            ],
            'login_log' => [
                'summary' => '登录日志：成功与失败都记',
                'enabled' => $loginOn,
                'uri' => 'admin-login-logs',
                'auto' => '框架在登录控制器内自动记录，无需声明',
                'config' => "config('aimanong.foundation.login_log')",
                'use_when' => '排查「登不上」或「有人在爆破」',
            ],
        ];
    }

    /**
     * 所有已注册 Resource 及其字段。
     *
     * @return array<int, array<string, mixed>>
     */
    public function resources(): array
    {
        $compiler = new Compiler;
        $out = [];

        foreach (Aimanong::registry()->all() as $uri => $class) {
            $node = $compiler->compile($class);

            $out[] = [
                'uri' => $node->uri,
                'label' => $node->label,
                'model' => $node->model,
                'resource_class' => $class,
                'grid' => [
                    'searchable' => array_values(array_map(
                        fn ($c): string => $c->name,
                        array_filter($node->columns, fn ($c): bool => $c->searchable)
                    )),
                    'sortable' => array_values(array_map(
                        fn ($c): string => $c->name,
                        array_filter($node->columns, fn ($c): bool => $c->sortable)
                    )),
                    'columns' => array_map(fn ($c): array => $c->toArray(), $node->columns),
                ],
                'form' => [
                    'fields' => array_map(fn ($f): array => $f->toArray(), $node->fields),
                    'rules' => $node->meta['rules'] ?? [],
                ],
            ];
        }

        return $out;
    }

    /**
     * 全部字段类型及其 PHP 类与可用属性。
     *
     * @return array<string, mixed>
     */
    public function fieldTypes(): array
    {
        $out = [];

        foreach (FieldType::all() as $type) {
            $class = 'Aimanong\\Form\\Fields\\'.$this->classOf($type);

            $out[$type] = [
                'php' => $class,
                'json_type' => FieldType::toJsonType($type),
                'props' => $this->propsOf($class),
            ];
        }

        return $out;
    }

    /**
     * 列可用选项。
     *
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        return [
            // 行为类
            'sortable' => '允许排序',
            'searchable' => '加入快捷搜索',
            'filter' => '加入筛选器',

            // 展示器：决定单元格如何渲染
            'dateTime' => '日期时间格式化，参数: 格式字符串（默认 Y-m-d H:i:s）',
            'bool' => '布尔→是否标签，参数: (trueLabel, falseLabel)，默认「是/否」',
            'badge' => '徽章样式（适合状态类字段）',
            'money' => '金额千分位，参数: 货币符号（默认 ¥）',
            'image' => '图片缩略图，参数: 高度像素（默认 32）',
            'link' => '超链接，参数: 显示文本',
            'progress' => '进度条（适合百分比/完成度）',
            'using' => '枚举值→标签映射，参数: 枚举类名',
            'map' => '值映射，参数: 关联数组',

            // 关联与条件样式（真实业务场景常用）
            'relation' => '显式声明关联列，参数: 关联路径（如 category.name）。'
                .'支持 belongsTo / hasOne（单值）与 belongsToMany（多值，显示为「A / B / C」，导出同样处理）',
            'dangerWhen' => '条件高亮，参数: (运算符, 阈值, 级别)。运算符支持 < <= > >= == !=',
            'dangerBelow' => '小于阈值时高亮（如库存不足），参数: (阈值, 级别)',
            'warningAbove' => '大于阈值时高亮，参数: (阈值, 级别)',

            // 外观类
            'width' => '列宽，参数: 像素',
            'label' => '列标题，参数: 字符串',

            // 列表整体配置（写在 grid() 中，不属于任何单列）
            'perPage' => '每页条数，参数: int。写法: $grid->perPage(15); 不传则用框架默认 20',
            'actions' => '是否显示行操作按钮，参数: bool',
            'batchActions' => '批量操作按钮，参数: 数组',

            // 导出（写在 grid() 中）
            'export' => '开启 CSV 导出，写法: $grid->export(); 未开启时导出接口返回 403',
            'exportExcept' => '导出时排除的列，参数: 数组。如 exportExcept([\'cover_url\'])',
            'exportChunkSize' => '导出分批查询条数，参数: int（默认 1000，大表用）',
        ];
    }

    /**
     * 树形结构选项（在 Resource 的 tree() 方法中使用）。
     *
     * @return array<string, string>
     */
    public function treeOptions(): array
    {
        return [
            'parentColumn' => '父级字段名，参数: 字符串（默认 parent_id）',
            'titleColumn' => '节点显示字段，参数: 字符串（默认 name）',
            'orderColumn' => '排序字段，参数: 字符串（默认 sort）',
            'draggable' => '（尚未实现）声明允许拖拽。当前前端无拖拽 UI，调整层级请用编辑表单或 PUT /{uri}/{id}/move',
            'maxDepth' => '最大层级，参数: int（0 = 不限制）',
        ];
    }

    /**
     * 查询列规则（搜索/排序/筛选支持的列名形态）。
     *
     * @return array<string, string>
     */
    public function queryColumnRules(): array
    {
        return [
            '本表列' => '直接写列名，如 order_no',
            '关联列' => '写 关联名.字段名，如 product.name；框架自动走 whereHas / 子查询',
            '关联要求' => '模型上必须定义该关联（返回 Eloquent Relation），且目标表有该字段',
            '非法列' => '不存在的列、非法字符会在**编译期**报错，不会等到运行时',
            '排序限制' => '关联排序仅支持 belongsTo / hasOne（有唯一目标行）',
            '多对多' => '列表显示与导出均支持（自动拼成「A / B / C」）；'
                .'表单用 multiselect + ->relation() 声明即可自动写入',
            '错误码' => 'GHOST_COLUMN（列不存在）/ INVALID_QUERY_COLUMN（不可用于查询）',
        ];
    }

    /**
     * RBAC 能力（v1.3.0 起内置）。
     *
     * @return array<string, mixed>
     */
    public function rbacCapabilities(): array
    {
        return [
            'enabled' => PermissionGate::enabled(),
            'actions' => PermissionGate::ACTIONS,
            'slug_format' => '{uri}.{action}，如 cms-authors.update',
            'auto_generated' => '权限节点由 Resource 注册时自动生成，无需手写',
            'super_role' => 'is_super 角色绕过所有判定',
            'toggle' => "config('aimanong.auth.rbac') 或 AIMANONG_RBAC 环境变量；关闭时全部放行",
            'command' => 'php artisan aimanong:permission sync|list|super {user}',
        ];
    }

    /**
     * **框架无法自动验证的需求** —— 必须人工确认。
     *
     * 多租户场景验证发现的最危险问题：
     * `validate_declaration`（含 requirements）全绿的同时，
     * 租户名单正在被泄漏。因为「每租户只能看自己数据」这类
     * **安全需求**映射不到任何一个 requirements 键，
     * 校验器对它完全无感，AI 会误以为已验证。
     *
     * 因此这里显式列出「校验器管不了、必须人工确认」的领域，
     * 让 AI 至少知道「这条我没验证过」，而不是默认通过。
     *
     * @return array<string, string>
     */
    public function cannotVerify(): array
    {
        return [
            '多租户/数据隔离' => '框架**不提供**行级数据隔离（无 tenant 概念、无全局作用域钩子）。'
                .'必须自己在模型层实现（全局作用域 + creating 盖章），并人工验证越权。',
            '权限/RBAC' => 'v1.3.0 起**已内置** RBAC（角色/权限，操作级粒度）。'
                .'但**按钮级/字段级权限、菜单权限**仍需自行实现。',
            '行级权限' => '「A 只能看自己创建的数据」这类需求框架无法验证，'
                .'也不提供官方拦截点，必须自己写并测试。',
            '审计日志' => '框架不记录谁改了什么，需自行实现。',
            '接口限流' => '框架不限流，需在 Laravel 层自行配置。',
            '提示' => '以上需求若出现在客户要求里，**不要指望 validate_declaration 会验证** —— '
                .'它会返回「全部满足」但实际上这类需求不在它的能力范围内。'
                .'请改用实际的越权测试来验证。',
        ];
    }

    /**
     * validate_declaration 支持的 requirements 键。
     *
     * 明确列出，避免 AI 猜测或使用不会被核对的键。
     *
     * @return array<string, string>
     */
    public function requirementKeys(): array
    {
        return [
            'searchable' => '要求可搜索的列，逗号分隔',
            'sortable' => '要求可排序的列，逗号分隔',
            'required' => '要求必填的表单字段，逗号分隔',
            'columns' => '要求列表包含的列，逗号分隔',
            'fields' => '要求表单包含的字段，逗号分隔',
            'per_page' => '要求的每页条数（会运行时实测）',
            'tree' => '是否要求树形结构，传 true',
            'export' => '是否要求开启导出，传 true',
            'step' => '是否要求分步表单（至少 2 步），传 true',
        ];
    }

    /**
     * 扩展（插件）能力说明。
     *
     * @return array<string, string>
     */
    public function extensionCapabilities(): array
    {
        return [
            '继承' => 'App 的扩展类需继承 Aimanong\\Extend\\Extension',
            'name()' => '扩展唯一标识（必需）',
            'dependencies()' => '依赖的其他扩展名，缺失会被跳过而非崩溃',
            'register()' => '注册阶段：绑定容器、登记 Resource（此时勿访问数据库）',
            'boot()' => '启动阶段：注册路由、视图、菜单',
            'this->resources()' => '登记本扩展提供的 Resource',
            'this->routes()' => '注册路由（自动带后台前缀与中间件）',
            '启用方式' => "config/aimanong.php 的 'extensions' 数组",
            '生成骨架' => 'php artisan aimanong:make-extension {Name}',
            '查看状态' => 'php artisan aimanong:extensions',
        ];
    }

    /**
     * 多应用能力说明。
     *
     * @return array<string, string>
     */
    public function applicationCapabilities(): array
    {
        return [
            '配置' => "config/aimanong.php 的 'applications' 数组",
            '隔离维度' => '每个应用有独立的路由前缀、auth guard、用户模型',
            '当前应用' => 'Aimanong::application()->current()',
            '切换' => "Aimanong::application()->switch('merchant')",
            '实现' => '用 Laravel 12 的 Context 做请求级隔离（并发安全）',
        ];
    }

    /**
     * 表单可用选项。
     *
     * @return array<string, string>
     */
    public function formOptions(): array
    {
        return [
            'label' => '字段标签，参数: 字符串',
            'required' => '必填（自动生成 required 规则）',
            'default' => '默认值，参数: mixed',
            'readonly' => '只读',
            'hidden' => '隐藏',
            'rules' => '追加 Laravel 验证规则，参数: string|array',
            'max' => '最大长度，参数: int（生成 max:N）',
            'min' => '最小长度，参数: int（生成 min:N）',
            'placeholder' => '占位文本，参数: string',
            'help' => '帮助文本，参数: string',
            'options' => '下拉选项，参数: array 或 Enum::cases()',
            'rows' => '文本域行数，参数: int',
            'step' => '声明分步表单的步骤，参数: 字符串（步骤标题）。后续字段归属该步骤',
            'relation(多对多)' => 'multiselect 上声明多对多关联：'
                ."\$form->multiSelect('tags')->relation('tags')->options([...]); "
                .'框架自动 sync 中间表 + 编辑时自动回填，无需手写模型事件',
            'dict' => '用数据字典填选项：->dict(\'order_status\')，'
                .'字典不存在会编译期报错（DICT_NOT_FOUND），不会给你空下拉框',
            'accept' => '上传字段的扩展名收窄，参数: 字符串（如 pdf,docx）',
            'maxSize' => '上传字段的单文件上限（KB），参数: int',
            'directory' => '上传字段的存储子目录，参数: 字符串（默认 uploads）',
            'disk' => '上传字段的存储磁盘，参数: 字符串（默认取框架配置）',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableRules(): array
    {
        return [
            'required', 'nullable', 'string', 'integer', 'numeric',
            'boolean', 'email', 'url', 'date', 'array',
            'min:N', 'max:N', 'between:min,max', 'in:a,b,c',
            'unique:table,column', 'exists:table,column',
        ];
    }

    protected function classOf(string $type): string
    {
        return match ($type) {
            'switch' => 'SwitchField',
            // 类型名是 file，类名是 FileField（避免与 Illuminate 的 File 打架）
            'file' => 'FileField',
            default => ucfirst($type),
        };
    }

    /**
     * 反射取字段类的链式方法（排除基类通用方法后的特有方法）。
     *
     * @return array<int, string>
     */
    protected function propsOf(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $base = new \ReflectionClass(Field::class);
        $baseMethods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            $base->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $own = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($m->getName(), $baseMethods, true) || str_starts_with($m->getName(), '__')) {
                continue;
            }

            $own[] = $m->getName();
        }

        return $own;
    }
}

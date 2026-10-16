<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Form\Fields\Field;
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
        return CapabilitiesRuntime::flag($key, $default);
    }

    /**
     * 完整能力清单。
     *
     * @return array<string, mixed>
     */
    /**
     * 「声明了但前端不消费」的 prop 清单 —— **按位置区分**。
     *
     * ## 为什么必须带位置（2026-10-17 批 1 修正）
     *
     * 第一版清单只按 **prop 名** 登记，于是 `relation` 被笼统地标成「死」。
     * 实测立刻暴露误报：同一时刻
     *
     *   - `$grid->column('customer.name')->relation('customer.name')` → 前端确实不读
     *   - `$form->multiSelect('tags')->relation('tags')` → **真的接通了**
     *     （Compiler 收进 `meta.relationFields`，ResourceController 拿它 sync 中间表）
     *
     * 也就是说：**同一个 prop 名，在列上是死的，在字段上是活的**。
     * 不分位置就会对完全正常的写法报错 —— 而误报会让 AI 很快学会忽略这条校验，
     * 比没有校验更糟。
     *
     * ## 返回形状
     *
     * ```
     * [
     *     'column' => ['width' => '它对用户是什么体验', ...],
     *     'field'  => [...],
     * ]
     * ```
     *
     * 键 `column` / `field` 与 Verifier 扫的两侧一一对应。
     *
     * @return array<string, array<string, string>>
     */
    public static function unwiredProps(): array
    {
        return [
            'column' => [
                'relation' => '->relation(关联路径)：列上只认列名里的点号，显式声明是空操作',
            ],
            'field' => [
                'allowHalf' => '->rate()->allowHalf()：界面只画整星，半星选择无效',
                'disk' => '->disk(盘名)：上传盘只取 config，不读字段声明',
                'display' => '->display()：只读展示不按此渲染（前端走的是字段 type 分支）',
                'separator' => '->tags()->separator()：分隔符未接线（且 tags 本身无渲染分支）',
            ],
        ];
    }

    /**
     * 死 prop 的**替代写法** —— 让 AI 拿到明确的下一步，而不是只有一句「不生效」。
     *
     * 形状与 `unwiredProps()` 完全一致（同为 column/field 两段），
     * 且**键必须一一对应**（有测试钉住）。
     *
     * @return array<string, array<string, string>>
     */
    public static function unwiredPropReplacements(): array
    {
        return [
            'column' => [
                'relation' => '用列名里的点号：$grid->column(\'category.name\', \'分类\')。'
                    .'点号写法是已接通的（注意：去掉 ->relation() 即可，点号本身已经在生效）',
            ],
            'field' => [
                'allowHalf' => '暂无替代：现在只支持整星。需要半星评分请在扩展里用 fieldView() 自定义 rate 渲染',
                'disk' => '把盘写在配置里：config/aimanong.php 的 foundation.upload.disk（字段级 ->disk() 不生效）',
                'display' => '列表展示用列的 ->dateTime() / ->dateFormat()（那两个已接通）；'
                    .'表单字段的 display 不影响渲染，可以删掉',
                'separator' => '暂无替代 —— 而且要注意：**tags 字段本身前端就没有渲染分支**，'
                    .'它现在退化成普通文本输入（不是标签 UI）。'
                    .'需要标签交互请用扩展注册字段渲染，或改用 multiselect',
            ],
        ];
    }

    /**
     * **词表表达不了什么** —— 前端表达力的边界 + 逃生口。
     *
     * ## 与 `cannot_verify` 的分工
     *
     * - `cannot_verify`：讲**后端安全**（多租户 / 行级权限 / 限流）—— 校验器管不了
     * - `cannot_express`：讲**前端表达力** —— 声明词表管不了，你必须写前端代码
     *
     * 两件事都让 AI「知道自己的边界」，但边界的位置完全不同。
     *
     * ## 为什么这一节是必需的
     *
     * 在这之前，AI 遇到「要一个富文本编辑器」时只有两条路：
     *   ① 翻遍自省资料，确认到底有没有（**查无此能力**也是一种答案，但它查不到）
     *   ② 反射框架类去猜（实测有一次反射了 142 个类，最后自己写了个扩展）
     *
     * 把边界**明说出来**，AI 就能一次判断「这个需求要写扩展」，
     * 而不是在「框架是不是有我没找到的写法」上反复试错。
     *
     * @return array<string, string>
     */
    public function cannotExpress(): array
    {
        return [
            /*
             * 逃生口放在最前面 —— AI 读到「做不到」之后，
             * 下一个问题必然是「那我该怎么做」。
             */
            '逃生口（怎么做到）' => '词表做不到的，用**扩展**：'
                .'① php artisan aimanong:make-extension {Name} 生成骨架；'
                .'② 在 register() 里 $this->field(YourField::class, \'type\', \'jsonType\', \'label\') 注册字段类型；'
                .'③ $this->fieldView(\'type\', \'vendor::field\') 指定它长什么样、'
                .'$this->fieldScript(\'vendor::script\') 指定逻辑怎么算。'
                .'代价：这个字段的渲染不进 schema 自省，属于「你必须自己保证对」的部分。',

            '富文本 / HTML 内容' => '**没有**富文本字段类型。'
                .'textarea 是纯文本。CMS 正文这类需求要么用 textarea（放弃富文本），'
                .'要么按上面的逃生口接一个编辑器扩展。',

            '图表 / 数据可视化' => '**没有**图表字段类型，也没有声明式仪表盘。'
                .'列表里的 ->progress() 是唯一的数据可视化。'
                .'要做图表请在扩展里注册一个字段类型，或在自定义页面里处理。',

            '主子表（一单多行明细）' => '**没有**嵌套表单 / 可编辑子表格。'
                .'典型场景「订单 + N 行商品明细」目前只能用两次独立操作（先建订单再建明细），'
                .'或写扩展。',

            '地图选点 / 代码编辑器 / 拖拽排序' => '**都没有**声明方式。'
                .'（树形页的 draggable 是空的 —— 见 tree_options.draggable）',

            '自定义仪表盘 / 自定义页面' => '**没有**声明式的页面机制。'
                .'Resource 的心智模型是「一张表 = 一组页面（列表 + 弹窗表单）」。'
                .'首页的统计卡片是框架自己的 Blade 模板，不是声明出来的 —— '
                .'要改它只能改视图，或用扩展注册路由自己渲染。',

            '详情页' => '⚠️ `Show` 类**存在但没有任何渲染方** —— '
                .'`Show::fields([...])` 会进 schema，但页面上不会出现只读详情页。'
                .'这是已知缺口（与 unwired_props 同类）。'
                .'当下要看明细请用列表页的列，或扩展里自己渲染。',

            '条件表达式' => '`visibleWhen(字段, 值|数组)` **只支持等值 / 包含**，'
                .'不是任意表达式（不支持 > < && 之类的运算）。'
                .'更复杂的联动要么拆成多个字段组合判断，要么写扩展。',

            '列表展示形态' => '列表**只有表格一种形态**（没有卡片 / 列表模式的声明）。'
                .'窄屏时框架会按 --stack 规则把表格转成卡片，那是响应式行为，不是可选形态。',

            '权限的字段级粒度' => '同一行记录里「某字段对某人只读」**做不到声明**，见 cannot_verify。',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => Aimanong::version(),
            'framework' => [
                'name' => 'Aimanong',
                'label' => 'AI 码农',
                ...CapabilitiesRuntime::frameworkVersions(),
                'php' => PHP_VERSION,
            ],
            'resources' => $this->resources(),
            'field_types' => $this->fieldTypes(),
            'tree_options' => $this->treeOptions(),
            'extension' => $this->extensionCapabilities(),
            'requirement_keys' => $this->requirementKeys(),
            /*
             * ⚠️ 这两条是实测中 agent 花时间最多的摩擦点，写进自省资料。
             */
            /*
             * ⚠️ 字段类型与校验规则是**两件独立的事** —— 实测坑。
             *
             * 本轮一个 agent 实测：`$form->email('email')` 看起来就"是邮箱"，
             * 但把 `->rules('email')` 去掉后，schema 里规则只剩 `["required"]`
             * —— 字段类型**不会**自动带上同名校验规则。
             */
            'field_type_vs_rule' => '字段类型（$form->email()）只决定**渲染**用哪个控件；'
                .'校验规则要另外写（->rules(\'email\') / ->required() / ->max(10)）。'
                .'两者同名但互不蕴含 —— 实测：只写 $form->email() 时提交非法邮箱**能过**。',
            'http_conventions' => [
                '数据接口' => '数据接口统一在 {prefix}/api/{uri}（如 /admin/api/users）。'
                    .'不带 /api 的 /admin/{uri} 是 SPA 的 HTML 页面，curl 它会拿回 HTML 而不是 JSON。'
                    .'**以 /__ai/openapi.json 里的 paths 为准** —— 它与真实路由同源。',
                '新建/编辑页' => '**没有**独立的 /admin/{uri}/create 或 /admin/{uri}/{id}/edit 路由 —— '
                    .'表单是列表页里的弹窗。提交用 POST /admin/api/{uri}、更新用 PUT /admin/api/{uri}/{id}。',
                '非法排序列' => '?sort= 指向不存在的列会返回 422 SORT_COLUMN_NOT_DECLARED'
                    .'（附 did_you_mean 与可排序列清单），**不会**静默返回未排序数据。'
                    .'参数名是 sort + direction（不是 order / sort_order）。',
            ],
            'cannot_verify' => $this->cannotVerify(),
            /*
             * ⚠️ **下发了但前端不消费的 prop**（按 column / field 分段）。
             *
             * 2026-10-16 审计发现 13 个这样的 API：声明能写、schema 里也有、
             * `ai:verify` 报 valid:true，但前端一次都没读过 —— 既不生效也不报错。
             * 2026-10-17 批 1 接通了 8 个，剩 5 个（且按位置分开）。
             *
             * AI 读到 column_options / form_options 会以为这些都可用，
             * 于是写出「看起来配了、实际没效果」的代码。这一节就是把这个坑明说出来。
             *
             * 两道防线（**都已就位**）：
             *   ① `ai:verify` **事前拦截** —— 用到就报 UNWIRED_PROP 并给出替代写法
             *   ② 单测 `PropConsumerTest` —— 新增未接线的 prop 会让它红
             *
             * 之所以两道都要：① 让 AI 当场知道，② 防止有人**新加**死 prop 而没人发现。
             */
            'unwired_props' => self::unwiredProps(),
            /*
             * ⚠️ **词表做不到什么**（前端表达力的边界）。
             *
             * 与 cannot_verify 分工：那条讲后端安全，这条讲前端表达力。
             * 有了它，AI 遇到「要富文本」这类需求可以一次判断「要写扩展」，
             * 而不必反射框架类去猜有没有隐藏写法（实测有过反射 142 个类的事故）。
             */
            'cannot_express' => $this->cannotExpress(),
            'scope_hooks' => ScopeHooks::introspect(),
            'rbac' => $this->rbacCapabilities(),
            'ui' => ThemeConfig::introspect(),
            'menu' => [
                'how' => 'Resource 覆盖 menu() 方法声明菜单（可选，不实现也有默认菜单）',
                'keys' => 'group（分组）| icon（图标）| sort（排序，小的在前）| label（显示名）| visible（是否显示）',
                'example' => "public static function menu(): array { return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10]; }",
                'permission_filter' => 'RBAC 开启时自动按「index」权限过滤，未授权的菜单不显示',
                'introspect' => 'GET /__ai/menu 返回**全部已声明**菜单（该接口无登录上下文，不做权限过滤；某用户实际可见的菜单在渲染时按权限过滤）',
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
            'dictionaries' => CapabilitiesRuntime::dictionaryList(),
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
        $throttleOn = $this->configFlag('aimanong.auth.throttle.enable');

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
                'also_known_as' => '处理留痕 / 操作留痕 / 谁改的 / 变更记录 / 审计追踪 / '
                    .'事后能查到谁在什么时候改了什么',
            ],
            'profile' => [
                'summary' => '个人中心：改资料 / 改密码 / 看我的角色权限与操作记录',
                'enabled' => $this->configFlag('aimanong.foundation.profile.enable'),
                'route' => 'GET {prefix}/profile（顶栏用户名即入口）',
                'api' => 'PUT api/profile、PUT api/profile/password',
                'security' => '只允许改 name/email/phone/avatar 四个字段（白名单）；'
                    .'改密码必须验当前密码，防止会话被劫持后直接改密',
                'note' => '它不是 Resource —— Resource 的心智模型是「一张表 = 一组页面」，'
                    .'而个人中心操作的是当前登录者自己，没有 id 也没有列表',
            ],
            'admin_user' => [
                'summary' => '管理员账号管理界面（内置 Resource：admin-users）',
                'enabled' => $this->configFlag('aimanong.foundation.admin_user.enable'),
                'uri' => 'admin-users',
                'auto' => '框架内置，装完就有；关掉只影响界面，不影响认证',
                'password' => '密码框三件套：requiredOnCreate() + omitWhenEmpty() + min(6)。'
                    .'编辑留空 = 不改密码；少了 omitWhenEmpty 会被空串哈希成新密码，账号当场失效',
                'fields' => '账号/姓名/邮箱/手机/密码/头像/角色/启用；'
                    .'停用的账号在密码校验之后被拒绝登录',
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
                'also_known_as' => '现场照片 / 传多张图 / 附件上传 / 报价单扫描件 / '
                    .'图片附件 / 文件上传',
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
                /*
                 * 业务同义词。
                 *
                 * 第六轮实测发现：需求方说的是「运营希望以后自己改文案，
                 * 不希望每次改文案都找开发」，而能力面里写的是
                 * 「把枚举变成一等公民」—— 开发视角，业务词一个都没有
                 * （「文案」「运营」出现 0 次）。AI 拿业务词去检索就是 MISS。
                 *
                 * 这不是分词问题，是**词表问题**：能力描述要能被
                 * 「用业务语言提问的人」检索到。
                 */
                'also_known_as' => '运营改文案 / 改显示名 / 下拉选项可维护 / '
                    .'状态文案不用发版 / 枚举集中管理',
            ],
            'login_log' => [
                'summary' => '登录日志：成功与失败都记',
                'enabled' => $loginOn,
                'uri' => 'admin-login-logs',
                'auto' => '框架在登录控制器内自动记录，无需声明',
                'config' => "config('aimanong.foundation.login_log')",
                'use_when' => '排查「登不上」或「有人在爆破」',
            ],
            'login_throttle' => [
                'summary' => '登录限流：每 IP 与「每 IP×每账号」双维度计数',
                'enabled' => $throttleOn,
                'auto' => '框架在登录控制器内自动生效，无需声明',
                'config' => "config('aimanong.auth.throttle')",
                'use_when' => '担心后台被爆破密码；或用户反馈「登录总提示尝试过于频繁」',
                'also_known_as' => '防爆破 / 登录失败次数限制 / 密码爆破保护 / 尝试过于频繁',
                'caveat' => '超限后抛的是校验异常（HTTP 422），'
                    .'不是 429；关闭用 auth.throttle.enable=false，'
                    .'单维度关闭把对应上限设为 0',
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

        foreach (Aimanong::registry()->all() as $class) {
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
            'batchActions' => '批量操作，参数: 动作名数组。'
                .'支持 delete（批量删除，复用 destroy 权限）与 '
                .'export（导出选中项，需先 export()）。'
                .'写法: $grid->batchActions([\'delete\', \'export\']); '
                .'未声明的动作名会在编译期报错',

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
            /*
             * ⚠️ 这一段原先**只有参数名**，没有钩子签名、没有类命名空间。
             *
             * 实测代价（L3 复测）：一个 LLM 知道「有 tree 这个东西」，
             * 但在 llms.txt、本文件、`/__ai/examples/tree`（当时 404）
             * 三处都拿不到该 `use` 哪个类，最后靠 `class_exists()` 反射
             * 才命中 `Aimanong\Tree\Tree`。
             *
             * 「有这条能力」与「能照着写出来」是两件事 —— 少了签名与
             * namespace，这段自省文档就只完成了前一半。
             */
            'hook' => "public static function tree(\Aimanong\Tree\Tree \$tree): void",
            'use' => 'use Aimanong\\Tree\\Tree;',
            'example' => 'public static function tree(Tree $tree): void { '
                ."\$tree->parentColumn('parent_id')->titleColumn('name')->orderColumn('sort'); }",
            'example_endpoint' => 'GET /__ai/examples/tree 给出可直接照抄的完整 Resource',
            'parentColumn' => '父级字段名，参数: 字符串（默认 parent_id）',
            'titleColumn' => '节点显示字段，参数: 字符串（默认 name）',
            'orderColumn' => '排序字段，参数: 字符串（默认 sort）',
            'draggable' => '（尚未实现）声明允许拖拽。当前前端无拖拽 UI，调整层级请用编辑表单或 PUT /{uri}/{id}/move',
            'maxDepth' => '最大层级，参数: int（0 = 不限制）',
            'caveat' => '树形页的列表**只请求 /tree 接口**（返回嵌套 children）。'
                .'因此 $grid->column("parent.name") 这类**关联列在树形页取不到值**（显示为空）——'
                .'需要父级名称时在模型上定义访问器（如 parent_name + $appends）',
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
            'enabled' => CapabilitiesRuntime::rbacEnabled(),
            'actions' => CapabilitiesRuntime::rbacActions(),
            'slug_format' => '{uri}.{action}，如 cms-authors.update',
            /*
             * ⚠️ 措辞必须准确：节点是「命名自动推导」，不是「注册即写库」。
             *
             * 原文写的是「权限节点由 Resource 注册时自动生成，无需手写」——
             * 第六轮实测里 AI 据此在注释中断言「权限节点自动生成」，
             * 但**它没跑 sync 命令，数据库里实际是 0 个节点**。
             * 新建 Resource 后不做这一步，权限列表里根本看不到它。
             */
            'naming' => '权限节点名由 Resource 自动推导为 {uri}.{action}，无需手写',
            'requires_sync' => '新增/改动 Resource 后**必须执行** php artisan aimanong:permission sync '
                .'才会写入数据库；不执行则权限列表里看不到该资源',
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
            '按钮级权限' => '**自动**：权限节点是 {uri}.{action}，页面按它决定渲染哪些按钮。'
                .'没有 {uri}.destroy 权限的人，列表里**根本没有**删除按钮（服务端不输出），'
                .'直接调接口也是 403。无需任何声明。',
            '多租户/数据隔离' => '框架**不提供开箱即用的租户列**（没有内置的 tenant_id 约定），'
                .'但**提供官方拦截点**：`ScopeHooks::query()` 注册查询作用域'
                .'（对所有 Resource 查询生效，包含列表/详情/更新/删除四条路径），'
                .'`ScopeHooks::writing()` 在写入前盖章。'
                .'本文件顶部的 `scope_hooks` 段会告诉你注册了没有 —— '
                .'**未注册时列表/详情/更新/删除都会返回全表数据**，越权就是这么来的。'
                .'⚠️ 作用域是**全局**的：回调里必须自己判断模型'
                .'（`$query->getModel() instanceof Foo`），否则会把所有表都加上不该有的条件。',
            '权限/RBAC' => '已内置 RBAC（角色/权限，操作级粒度）+ **按钮级权限**'
                .'（无权限的动作按钮服务端就不渲染，不是 CSS/JS 藏起来）'
                .'+ 菜单权限（按权限过滤）。'
                .'**字段级权限**（同一条记录里某字段对某人只读）仍需自行实现。',
            '行级权限' => '「A 只能看自己创建的数据」这类需求框架无法验证，'
                .'也不提供官方拦截点，必须自己写并测试。',
            '审计日志' => '**框架已内置**：操作日志（谁在什么时候改了哪条数据）与登录日志'
                .'（成功与失败都记）都是开箱可用的 Resource —— 见本文件的 `foundation` 段。'
                .'「无法验证」的部分是**业务语义**：某个字段算不算敏感、'
                .'某次改动要不要留痕，框架判断不了。',
            '接口限流' => '**登录接口有内置限流**（`LoginThrottle`，见 config/aimanong.php → '
                .'auth.throttle：enable/window/max）。'
                .'业务接口**没有**默认限流 —— 需要的话在 Laravel 层自行配置。',
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
            'hidden' => '隐藏（静态，永远不显示）',
            'batchAction(自定义批量动作)' => '声明一个自定义批量动作。'
                .'参数: (动作名, 标签)，然后 ->handler(处理器类)。'
                .'处理器实现 Aimanong\\Contracts\\BatchActionHandler（handle(string $model, array $ids): int）。'
                ."例: \$grid->batchAction('ship', '标记为已发货')->handler(ShipOrders::class);  "
                .'权限节点 {uri}.{动作名} 自动生成，id 校验与操作日志继承框架的',
            'belongsTo(外键下拉)' => '外键下拉，候选与 exists 规则**自动**从模型关联解析。'
                .'参数: (字段名, 标签)。关联名默认从字段名推断（author_id → author），'
                .'可用 ->relation() 显式指定、->labelColumn() 指定显示列。'
                .'例: $form->belongsTo(\'author_id\', \'作者\');  '
                .'**不要再手写 options(pluck) 与 exists 规则** —— 表名/主键框架自己知道',
            'visibleWhen' => '条件显示：另一个字段等于某值时才出现。'
                ."参数: (字段名, 值|数组)。例: \$form->select('priority')->visibleWhen('type', 'bug'); "
                ."或 ->visibleWhen('type', ['bug','feature'])。被条件藏起来的字段提交时会被清空",
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
            'requiredOnCreate' => '仅新增时必填（编辑留空表示不改），典型是用户表单的密码框',
            'omitWhenEmpty' => '提交时若为空则整个字段不提交 —— 「留空表示不修改」的可选字段必须加它',
            'relation' => 'multiSelect 上声明多对多关联并自动 sync 中间表：->relation(\'roles\')',
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

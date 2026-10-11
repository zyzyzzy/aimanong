<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Ai\Capabilities;
use Aimanong\Aimanong;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Schema\Compiler;
use Aimanong\Support\FieldType;
use Illuminate\Console\Command;

/**
 * 从 Schema 生成文档站的 Markdown。
 *
 * 核心价值：文档由声明自动生成，**永不漂移**。
 * 手写只写教程类内容，API 参考一律自动产出。
 */
class DocsCommand extends Command
{
    protected $signature = 'aimanong:docs
                            {--out=docs/api : 输出目录}';

    protected $description = '从 Resource 声明生成文档（API 参考自动生成，永不漂移）';

    public function handle(): int
    {
        $outDir = $this->option('out');
        $outDir = is_string($outDir) && $outDir !== '' ? rtrim($outDir, '/') : 'docs/api';

        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $capabilities = new Capabilities;

        $this->write("{$outDir}/index.md", $this->indexPage($capabilities));
        $this->write("{$outDir}/fields.md", $this->fieldsPage());
        $this->write("{$outDir}/columns.md", $this->columnsPage($capabilities));
        $this->write("{$outDir}/resources.md", $this->resourcesPage());
        $this->write("{$outDir}/api.md", $this->apiPage());

        $this->newLine();
        $this->info('✅ 文档已生成到 '.$outDir);
        $this->line('   提示：这些文件由声明自动生成，请勿手工编辑。');

        return self::SUCCESS;
    }

    protected function write(string $path, string $content): void
    {
        file_put_contents($path, $content);
        $this->line("  ✓ {$path}");
    }

    /**
     * 自动生成文件的头部注释。
     */
    protected function header(): string
    {
        return "<!-- 自动生成，请勿手工编辑 -->\n"
            ."<!-- 来源: php artisan aimanong:docs -->\n"
            ."<!-- 修改 Resource 声明后请重新生成 -->\n\n";
    }

    protected function indexPage(Capabilities $caps): string
    {
        $data = $caps->toArray();
        $resources = $data['resources'];

        $lines = [$this->header()];
        $lines[] = '# API 参考';
        $lines[] = '';
        $lines[] = '> 本页由 `php artisan aimanong:docs` 自动生成。';
        $lines[] = '> 修改 Resource 声明后重新执行即可，**无需手工同步**。';
        $lines[] = '';
        $lines[] = '## 环境';
        $lines[] = '';
        $lines[] = '| 项 | 值 |';
        $lines[] = '|---|---|';
        $lines[] = '| 框架版本 | '.$data['version'].' |';
        $lines[] = '| Laravel | '.$data['framework']['laravel'].' |';
        $lines[] = '| PHP | '.$data['framework']['php'].' |';
        $lines[] = '| 字段类型数 | '.count($data['field_types']).' |';
        $lines[] = '| 已注册 Resource | '.count($resources).' |';
        $lines[] = '';

        if ($resources !== []) {
            $lines[] = '## 已注册 Resource';
            $lines[] = '';
            $lines[] = '| 名称 | URI | 模型 | 列数 | 字段数 |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($resources as $r) {
                $lines[] = sprintf(
                    '| %s | `%s` | `%s` | %d | %d |',
                    $r['label'],
                    $r['uri'],
                    $r['model'],
                    count($r['grid']['columns']),
                    count($r['form']['fields'])
                );
            }

            $lines[] = '';
        }

        $lines[] = '## 其它页面';
        $lines[] = '';
        $lines[] = '- [字段类型](./fields.md) —— 全部可用字段及其属性';
        $lines[] = '- [列展示器](./columns.md) —— 列表页可用选项';
        $lines[] = '- [Resource 详情](./resources.md) —— 每个 Resource 的完整定义';
        $lines[] = '- [HTTP API](./api.md) —— 查询参数与响应格式';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 字段类型手册。
     */
    protected function fieldsPage(): string
    {
        $lines = [$this->header()];
        $lines[] = '# 字段类型';
        $lines[] = '';
        $lines[] = '> 由 `php artisan aimanong:docs` 自动生成。';
        $lines[] = '';

        $examples = [
            'text' => "\$form->text('name')->label('名称')->required()->max(255);",
            'textarea' => "\$form->textarea('body')->label('内容')->rows(4);",
            'number' => "\$form->number('sort')->label('排序')->default(0);",
            'decimal' => "\$form->decimal('price')->label('价格')->decimals(2);",
            'money' => "\$form->money('amount')->label('金额')->symbol('¥');",
            'select' => "\$form->select('status')->label('状态')->options(['a' => '甲']);",
            'multiselect' => "\$form->multiSelect('tags')->label('标签')->options(['a' => '甲']);",
            'radio' => "\$form->radio('type')->label('类型')->options(['a' => '甲']);",
            'checkbox' => "\$form->checkbox('favs')->label('偏好')->options(['a' => '甲']);",
            'switch' => "\$form->switch('enabled')->label('是否启用');",
            'date' => "\$form->date('published_at')->label('发布日期');",
            'datetime' => "\$form->datetime('started_at')->label('开始时间');",
            'time' => "\$form->time('open_at')->label('营业时间');",
            'daterange' => "\$form->dateRange('period')->label('周期');",
            'email' => "\$form->email('email')->label('邮箱')->rules('email');",
            'url' => "\$form->url('homepage')->label('主页');",
            'password' => "\$form->password('pwd')->label('密码');",
            'tel' => "\$form->tel('phone')->label('电话');",
            'rate' => "\$form->rate('score')->label('评分')->max(5);",
            'slider' => "\$form->slider('progress')->label('进度')->range(0, 100);",
            'color' => "\$form->color('theme')->label('主题色');",
            'icon' => "\$form->icon('ico')->label('图标');",
            'tags' => "\$form->tags('labels')->label('标记');",
            'hidden' => "\$form->hidden('token');",
            'display' => "\$form->display('info')->label('说明');",
            'divider' => "\$form->divider('分组标题');",
        ];

        $lines[] = '## 通用链式方法';
        $lines[] = '';
        $lines[] = '所有字段类型都支持：';
        $lines[] = '';
        $lines[] = '| 方法 | 说明 |';
        $lines[] = '|---|---|';
        $lines[] = '| `label(string)` | 字段标签（中文名） |';
        $lines[] = '| `required(bool = true)` | 必填，自动生成 `required` 校验规则 |';
        $lines[] = '| `default(mixed)` | 默认值 |';
        $lines[] = '| `readonly(bool = true)` | 只读 |';
        $lines[] = '| `hidden(bool = true)` | 隐藏 |';
        $lines[] = '| `rules(string\|array)` | 追加 Laravel 验证规则 |';
        $lines[] = '| `max(int)` / `min(int)` | 长度或数值限制 |';
        $lines[] = '| `placeholder(string)` | 占位文本 |';
        $lines[] = '| `help(string)` | 字段下方帮助文本 |';
        $lines[] = '| `dateTime(string)` | 展示为日期时间 |';
        $lines[] = '| `map(array)` | 值 → 标签映射 |';
        $lines[] = '| `using(class-string)` | 枚举值 → 标签映射 |';
        $lines[] = '';

        $lines[] = '## 全部类型';
        $lines[] = '';

        foreach (FieldType::all() as $type) {
            $jsonType = FieldType::toJsonType($type);
            $example = $examples[$type] ?? "\$form->{$type}('field')->label('标签');";

            $lines[] = "### `{$type}`";
            $lines[] = '';
            $lines[] = "- JSON 类型：`{$jsonType}`";

            $extra = $this->extraProps($type);
            if ($extra !== '') {
                $lines[] = '- 专属方法：'.$extra;
            }

            $lines[] = '';
            $lines[] = '```php';
            $lines[] = $example;
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 各字段类型的专属方法说明。
     */
    protected function extraProps(string $type): string
    {
        return match ($type) {
            'textarea' => '`rows(int)` 行数',
            'number' => '`step(int|float)` 步进、`range(min, max)` 范围',
            'decimal', 'money' => '`decimals(int)` 小数位'.($type === 'money' ? '、`symbol(string)` 货币符号' : ''),
            'select', 'multiselect', 'radio', 'checkbox' => '`options(array)` 选项',
            'select' => '`multiple(bool)` 多选',
            'date', 'datetime', 'time' => '`format(string)` 格式',
            'rate' => '`max(int)` 星数、`allowHalf(bool)` 半星',
            'slider' => '`range(min, max)`、`step(int)`',
            'tags' => '`separator(string)` 分隔符',
            default => '',
        };
    }

    /**
     * 列展示器手册。
     */
    /**
     * 数据字典章节。
     *
     * 字典列表直接从 Dictionary 取（单一来源）——
     * 后台新增一个字典，文档里立刻就有，不需要人工同步。
     *
     * @param  array<int, string>  $lines
     */
    protected function dictionarySection(array &$lines): void
    {
        $dictionary = new Dictionary;
        $all = $dictionary->all();

        $lines[] = '## 数据字典';
        $lines[] = '';
        $lines[] = '把枚举变成一等公民：**声明一次，列表 / 表单 / 徽章配色共用**。';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "\$form->select('status')->label('状态')->dict('order_status');";
        $lines[] = "\$grid->column('status', '状态')->dict('order_status');";
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '字典不存在或没有任何启用条目时**编译期直接报错**（DICT_NOT_FOUND + 模糊匹配建议），';
        $lines[] = '而不是给你一个永远选不了值的空下拉框。';
        $lines[] = '';
        $lines[] = '两种来源，读取只走 `Dictionary` 一个入口：';
        $lines[] = '';
        $lines[] = '| 来源 | 位置 | 特点 |';
        $lines[] = '|---|---|---|';
        $lines[] = '| 代码声明 | `config/aimanong.php` 的 `foundation.dict.declarations` | 随代码走、可 diff、后台只读 |';
        $lines[] = '| 数据库 | 后台「数据字典 / 字典条目」 | 运营可随时改文案 |';
        $lines[] = '';

        if ($all === []) {
            $lines[] = '> 当前项目还没有任何数据字典。';
            $lines[] = '';

            return;
        }

        $lines[] = '### 当前项目的字典';
        $lines[] = '';
        $lines[] = '| code | 名称 | 来源 | 可选值 |';
        $lines[] = '|---|---|---|---|';

        foreach ($all as $code => $info) {
            $values = implode(' / ', array_map(
                fn (string $v, string $l): string => "`{$v}`={$l}",
                array_keys($info['items']),
                array_values($info['items'])
            ));

            $source = $info['source'] === 'code' ? '代码声明' : '数据库';
            $lines[] = "| `{$code}` | {$info['name']} | {$source} | {$values} |";
        }

        $lines[] = '';
    }

    /**
     * 基座功能章节。
     *
     * 从 Capabilities 取数（单一来源）——
     * 关闭某项基座功能时，文档里也会同步显示「已关闭」，
     * 不会出现「文档说有、实际没有」的漂移。
     *
     * @param  array<int, string>  $lines
     */
    protected function foundationSection(array &$lines, Capabilities $caps): void
    {
        $foundation = $caps->foundationCapabilities();

        $lines[] = '## 基座功能（开箱即用）';
        $lines[] = '';
        $lines[] = '以下能力由框架内置，**用户一行代码都不用写**，且可用配置整体关掉。';
        $lines[] = '';
        $lines[] = '| 能力 | 状态 | 说明 |';
        $lines[] = '|---|---|---|';

        foreach ($foundation as $key => $info) {
            if ($key === 'readonly') {
                continue;
            }

            $enabled = ($info['enabled'] ?? true) ? '已启用' : '已关闭';
            $summary = is_string($info['summary'] ?? null) ? $info['summary'] : '';

            $lines[] = "| `{$key}` | {$enabled} | {$summary} |";
        }

        $lines[] = '';

        foreach ($foundation as $key => $info) {
            if ($key === 'readonly') {
                continue;
            }

            $lines[] = '### '.$key;
            $lines[] = '';

            foreach (['uri', 'uris', 'auto', 'excludes', 'config', 'use_when'] as $field) {
                if (isset($info[$field]) && is_string($info[$field])) {
                    $lines[] = "- **{$field}**：{$info[$field]}";
                }
            }

            $lines[] = '';
        }
    }

    protected function columnsPage(Capabilities $caps): string
    {
        $data = $caps->toArray();

        $lines = [$this->header()];
        $lines[] = '# 列展示器与选项';
        $lines[] = '';
        $lines[] = '> 由 `php artisan aimanong:docs` 自动生成。';
        $lines[] = '';
        $lines[] = '## 全部选项';
        $lines[] = '';
        $lines[] = '| 方法 | 说明 |';
        $lines[] = '|---|---|';

        foreach ($data['column_options'] as $name => $desc) {
            $lines[] = "| `{$name}()` | {$desc} |";
        }

        $lines[] = '';
        $lines[] = '## 导出';
        $lines[] = '';
        $lines[] = '在 `grid()` 中调用 `$grid->export();` 开启 CSV 导出。';
        $lines[] = '导出复用列表的搜索/排序/筛选参数 —— 导出的内容与界面看到的一致。';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = '$grid->export();                        // 开启导出';
        $lines[] = "\$grid->exportExcept(['cover_url']);     // 排除某些列";
        $lines[] = '$grid->exportChunkSize(2000);          // 分批查询（大表）';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '> 未开启导出的 Resource 请求导出接口会返回 403，并提示如何开启。';
        $lines[] = '';
        $lines[] = '## 树形结构';
        $lines[] = '';
        $lines[] = '在 Resource 中实现 `tree()` 方法即可获得树形页面：';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = 'public static function tree(Tree \$tree): void';
        $lines[] = '{';
        $lines[] = "    \$tree->parentColumn('parent_id')";
        $lines[] = "        ->titleColumn('name')";
        $lines[] = "        ->orderColumn('sort')";
        $lines[] = '        ->draggable();';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '> **循环引用会被自动检测** —— 数据存在 A→B→A 时返回 422 而非无限递归。';
        $lines[] = '> 移动节点时会校验目标位置，不允许把节点移到自己的子孙下。';
        $lines[] = '';
        $lines[] = '## 分步表单';
        $lines[] = '';
        $lines[] = '用 `$form->step(\'步骤标题\')` 声明步骤，**后续字段自动归属该步骤**：';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = 'public static function form(Form \$form): void';
        $lines[] = '{';
        $lines[] = "    \$form->step('基本信息');";
        $lines[] = "    \$form->text('name')->required();";
        $lines[] = "    \$form->text('email')->required();";
        $lines[] = '';
        $lines[] = "    \$form->step('联系方式');";
        $lines[] = "    \$form->text('phone');";
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '> 字段归属由**声明顺序**决定 —— 扁平、无嵌套闭包（框架铁律）。';
        $lines[] = '> 提交时所有步骤一起校验，规则与单页表单一致。';
        $lines[] = '';
        $lines[] = '## 只读资源';
        $lines[] = '';
        $lines[] = '审计日志、监控、报表快照这类数据「只应该由系统写入」，';
        $lines[] = '在后台能随手改就失去了意义。覆盖 `readonly()` 即可：';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = 'public static function readonly(): bool';
        $lines[] = '{';
        $lines[] = '    return true;';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '| 层 | 效果 |';
        $lines[] = '|---|---|';
        $lines[] = '| 前端 | 隐藏「新增 / 编辑 / 删除」按钮与「操作」列 |';
        $lines[] = '| 接口 | `store` / `update` / `destroy` 一律 403（**与 RBAC 开关无关**） |';
        $lines[] = '| 权限 | 只生成 `index` / `show` / `export`，不产生点了必然 403 的假权限 |';
        $lines[] = '';
        $this->dictionarySection($lines);
        $this->foundationSection($lines, $caps);
        $lines[] = '## 扩展（插件）';
        $lines[] = '';
        $lines[] = '一个扩展 = 一个继承 `Aimanong\\Extend\\Extension` 的类：';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = 'php artisan aimanong:make-extension Seo   # 生成骨架';
        $lines[] = 'php artisan aimanong:extensions           # 查看状态';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '| 方法 | 用途 |';
        $lines[] = '|---|---|';
        $lines[] = '| `name()` | 唯一标识（必需） |';
        $lines[] = '| `dependencies()` | 依赖的其他扩展，缺失会被跳过而非崩溃 |';
        $lines[] = '| `register()` | 注册阶段：登记 Resource（勿访问数据库） |';
        $lines[] = '| `boot()` | 启动阶段：注册路由、视图 |';
        $lines[] = '';
        $lines[] = '> **失败隔离**：单个扩展出错会被记录并跳过，不影响其它扩展与框架本身。';
        $lines[] = '';
        $lines[] = '## 多应用（多后台）';
        $lines[] = '';
        $lines[] = '在 `config/aimanong.php` 声明多个后台，各自有独立的前缀、guard 与用户模型：';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "'applications' => [";
        $lines[] = "    'admin' => [";
        $lines[] = "        'title' => '运营后台',";
        $lines[] = "        'route' => ['prefix' => 'admin'],";
        $lines[] = "        'auth' => ['guard' => 'admin', 'model' => Administrator::class],";
        $lines[] = '    ],';
        $lines[] = "    'merchant' => [";
        $lines[] = "        'title' => '商家后台',";
        $lines[] = "        'route' => ['prefix' => 'merchant'],";
        $lines[] = "        'auth' => ['guard' => 'merchant', 'model' => Merchant::class],";
        $lines[] = '    ],';
        $lines[] = '],';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '> 当前应用：`Aimanong::application()->current()`（用 Laravel 12 Context 做请求级隔离，并发安全）';
        $lines[] = '';
        $lines[] = '## 示例';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = 'public static function grid(Grid $grid): void';
        $lines[] = '{';
        $lines[] = "    \$grid->column('id', 'ID')->sortable();";
        $lines[] = "    \$grid->column('name', '名称')->searchable();";
        $lines[] = "    \$grid->column('status', '状态')->badge();";
        $lines[] = "    \$grid->column('enabled', '是否启用')->bool('启用', '停用');";
        $lines[] = "    \$grid->column('amount', '金额')->money();";
        $lines[] = "    \$grid->column('progress', '进度')->progress();";
        $lines[] = "    \$grid->column('avatar', '头像')->image();";
        $lines[] = "    \$grid->column('homepage', '主页')->link('访问');";
        $lines[] = "    \$grid->column('created_at', '创建时间')->dateTime()->sortable();";
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 每个 Resource 的完整定义。
     */
    protected function resourcesPage(): string
    {
        $lines = [$this->header()];
        $lines[] = '# Resource 详情';
        $lines[] = '';
        $lines[] = '> 由 `php artisan aimanong:docs` 自动生成。';
        $lines[] = '';

        $compiler = new Compiler;
        $all = Aimanong::registry()->all();

        if ($all === []) {
            $lines[] = '（尚未注册任何 Resource）';

            return implode("\n", $lines);
        }

        foreach ($all as $uri => $class) {
            $node = $compiler->compile($class);

            $lines[] = "## {$node->label}";
            $lines[] = '';
            $lines[] = "- URI：`{$node->uri}`";
            $lines[] = "- 模型：`{$node->model}`";
            $lines[] = "- 类：`{$class}`";
            $lines[] = '';

            $lines[] = '### 列表页列';
            $lines[] = '';
            $lines[] = '| 列 | 标题 | 可排序 | 可搜索 | 展示器 |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($node->columns as $c) {
                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $c->name,
                    $c->label,
                    $c->sortable ? '✓' : '',
                    $c->searchable ? '✓' : '',
                    $c->formatter !== null ? "`{$c->formatter}`" : ''
                );
            }

            $lines[] = '';
            $lines[] = '### 表单字段';
            $lines[] = '';
            $lines[] = '| 字段 | 标签 | 类型 | 必填 | 校验规则 |';
            $lines[] = '|---|---|---|---|---|';

            foreach ($node->fields as $f) {
                $lines[] = sprintf(
                    '| `%s` | %s | `%s` | %s | %s |',
                    $f->name,
                    $f->label,
                    $f->type,
                    $f->required ? '✓' : '',
                    $f->rules !== [] ? '`'.implode('`, `', $f->rules).'`' : ''
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * HTTP API 文档。
     */
    protected function apiPage(): string
    {
        $lines = [$this->header()];
        $lines[] = '# HTTP API';
        $lines[] = '';
        $lines[] = '> 由 `php artisan aimanong:docs` 自动生成。';
        $lines[] = '';
        $lines[] = '## 端点';
        $lines[] = '';
        $lines[] = '| 方法 | 路径 | 说明 |';
        $lines[] = '|---|---|---|';
        $lines[] = '| GET | `/admin/api/{uri}` | 列表（分页 + 搜索 + 排序） |';
        $lines[] = '| POST | `/admin/api/{uri}` | 新增 |';
        $lines[] = '| GET | `/admin/api/{uri}/{id}` | 详情 |';
        $lines[] = '| PUT | `/admin/api/{uri}/{id}` | 更新 |';
        $lines[] = '| DELETE | `/admin/api/{uri}/{id}` | 删除 |';
        $lines[] = '';
        $lines[] = '## 查询参数';
        $lines[] = '';
        $lines[] = '| 参数 | 类型 | 说明 |';
        $lines[] = '|---|---|---|';
        $lines[] = '| `page` | int | 页码，从 1 开始 |';
        $lines[] = '| `per_page` | int | 每页条数，不传则用 Resource 声明的值 |';
        $lines[] = '| `keyword` | string | 搜索关键词，在可搜索列上模糊匹配 |';
        $lines[] = '| `sort` | string | 排序字段，必须是可排序列 |';
        $lines[] = '| `direction` | `asc` \| `desc` | 排序方向。**注意参数名是 `direction`，不是 `order`** |';
        $lines[] = '';
        $lines[] = '> 传入未知参数时，响应会带 `warnings.UNKNOWN_QUERY_PARAM` 提示，';
        $lines[] = '> 而不会静默忽略 —— 避免"参数写错却以为生效"。';
        $lines[] = '';
        $lines[] = '## 响应格式';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = '{';
        $lines[] = '  "data": [ { "id": 1, "name": "示例" } ],';
        $lines[] = '  "meta": {';
        $lines[] = '    "total": 42,';
        $lines[] = '    "perPage": 20,';
        $lines[] = '    "currentPage": 1,';
        $lines[] = '    "lastPage": 3';
        $lines[] = '  }';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## AI 自省接口';
        $lines[] = '';
        $lines[] = '| 接口 | 说明 |';
        $lines[] = '|---|---|';
        $lines[] = '| `GET /__ai/capabilities.json` | 全部能力清单 |';
        $lines[] = '| `GET /__ai/schema/{uri}` | 某 Resource 完整定义 |';
        $lines[] = '| `GET /__ai/openapi.json` | OpenAPI 3.1 文档 |';
        $lines[] = '| `GET /__ai/context` | Markdown 上下文 |';
        $lines[] = '| `POST /__ai/verify` | 校验声明合法性 |';
        $lines[] = '';
        $lines[] = '> 默认仅 `local` / `debug` 环境开启；生产需配置 `AIMANONG_AI_TOKEN`。';
        $lines[] = '';

        return implode("\n", $lines);
    }
}

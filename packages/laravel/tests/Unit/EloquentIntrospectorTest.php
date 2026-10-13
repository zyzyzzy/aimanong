<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Tests\Unit\Fixtures\IntrospectArticle;
use Aimanong\Tests\Unit\Fixtures\IntrospectComment;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Eloquent 内省实现的真实行为测试。
 *
 * ## 为什么必须连真数据库
 *
 * 幽灵列检测原先散在 `Compiler` 里，**没有任何测试覆盖到它的数据库路径** ——
 * 所有单测都因为「无 DB 连接 → 跳过检测」而提前 return，184 个测试全绿
 * 也证明不了这段逻辑是对的。这个文件补上那块空白。
 *
 * 用 sqlite 内存库，不依赖外部服务。
 */
class EloquentIntrospectorTest extends TestCase
{
    private static bool $booted = false;

    private EloquentIntrospector $introspector;

    protected function setUp(): void
    {
        $this->bootDatabase();

        $this->introspector = new EloquentIntrospector;
    }

    private function bootDatabase(): void
    {
        if (self::$booted) {
            return;
        }

        $container = new Container;

        /*
         * Facade 运行时只需要 ArrayAccess + make()，Container 完全够用；
         * 它的 docblock 却声明要 Foundation\Application。
         * 为了这个类型去起一个完整 Application 不划算 ——
         * 本测试要的是「内省行为」，不是「应用引导」。
         */
        /** @phpstan-ignore argument.type */
        Facade::setFacadeApplication($container);

        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->bootEloquent();

        // Schema 门面的 accessor 是 'db.schema'，由 Laravel 的
        // DatabaseServiceProvider 注册。这里用 Capsule 手动补上。
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', static fn (Container $c) => $c->make('db')->connection()->getSchemaBuilder());

        $schema = $capsule->getConnection()->getSchemaBuilder();

        $schema->create('introspect_articles', function ($table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('slug');
            $table->boolean('published')->default(false);
        });

        $schema->create('introspect_comments', function ($table): void {
            $table->increments('id');
            $table->integer('article_id');
            $table->string('body');
        });

        self::$booted = true;
    }

    public function test_table_of_returns_real_table_name(): void
    {
        $this->assertSame('introspect_articles', $this->introspector->tableOf(new IntrospectArticle));
    }

    public function test_table_of_returns_null_for_non_eloquent(): void
    {
        $this->assertNull($this->introspector->tableOf(new \stdClass));
    }

    public function test_has_table_reflects_reality(): void
    {
        $this->assertTrue($this->introspector->hasTable('introspect_articles'));
        $this->assertFalse($this->introspector->hasTable('no_such_table'));
    }

    public function test_columns_of_returns_real_columns(): void
    {
        $columns = $this->introspector->columnsOf('introspect_articles');

        $this->assertContains('id', $columns);
        $this->assertContains('title', $columns);
        $this->assertContains('slug', $columns);
        $this->assertContains('published', $columns);
        $this->assertNotContains('titel', $columns);
    }

    public function test_is_relation_distinguishes_relation_from_plain_value(): void
    {
        $article = new IntrospectArticle;

        $this->assertTrue($this->introspector->isRelation($article->comments()));
        $this->assertFalse($this->introspector->isRelation('not-a-relation'));
        $this->assertFalse($this->introspector->isRelation(new \stdClass));
    }

    /**
     * 四类合法但非真实列的名字，检测都必须放行 ——
     * 否则会把正常声明误报成「幽灵列」（CMS 场景验证发现过这类误报）。
     */
    public function test_is_virtual_attribute_accepts_all_four_legal_shapes(): void
    {
        $article = new IntrospectArticle;

        // ① 传统访问器 getTitleUpperAttribute()
        $this->assertTrue(
            $this->introspector->isVirtualAttribute($article, 'title_upper'),
            '传统访问器应被识别'
        );

        // ② casts 声明的列
        $this->assertTrue(
            $this->introspector->isVirtualAttribute($article, 'published'),
            'casts 声明的列应被识别'
        );

        // ③ 同名无参方法（关系 / 计算属性）
        $this->assertTrue(
            $this->introspector->isVirtualAttribute($article, 'comments'),
            '同名关系方法应被识别'
        );

        // ④ 真的不存在的名字 → 不放行（这是检测存在的意义）
        $this->assertFalse(
            $this->introspector->isVirtualAttribute($article, 'titel'),
            '拼错的列名不应被当成虚拟属性'
        );
    }

    public function test_is_virtual_attribute_returns_false_for_non_eloquent(): void
    {
        $this->assertFalse($this->introspector->isVirtualAttribute(new \stdClass, 'anything'));
    }

    public function test_comments_model_relation_is_recognised(): void
    {
        $this->assertTrue($this->introspector->isRelation((new IntrospectComment)->article()));
    }
}

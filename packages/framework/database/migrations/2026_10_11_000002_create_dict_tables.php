<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 数据字典。
 *
 * ## 为什么要数据字典
 *
 * 没有字典时，同一套枚举会在每个 Resource 里各写一遍：
 * 列表页一套 `map()`、表单页一套 `options()`、导出又一套。
 * 改一个文案要改 N 处，AI 也无从知道「项目里到底有哪些可选值」。
 *
 * 有了字典，枚举变成一等公民：AI 自省一次就能拿到全部可选项。
 *
 * ## 两张表
 *
 * - `admin_dict_types`  字典分类（order_status / 订单状态）
 * - `admin_dict_items`  字典条目（pending / 待付款）
 *
 * items 用 `type_code` 字符串关联而不是 `type_id` 外键：
 * 读取时一次查全比 join 简单，且字典分类被误删时条目不会变成孤儿
 * （读取方按 code 查，查不到自然是空，不会崩）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_dict_types')) {
            Schema::create('admin_dict_types', function (Blueprint $table): void {
                $table->id();

                // 代码里引用的就是它，如 ->dict('order_status')
                $table->string('code', 100)->unique();
                $table->string('name');
                $table->string('description', 500)->nullable();

                /*
                 * 代码声明的字典在后台**只读**。
                 *
                 * 否则运维把 order_status 一删，线上所有引用它的
                 * 页面立刻变成空下拉框 —— 一个纯后台操作搞挂业务。
                 */
                $table->boolean('is_locked')->default(false);

                $table->integer('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('admin_dict_items')) {
            Schema::create('admin_dict_items', function (Blueprint $table): void {
                $table->id();

                $table->string('type_code', 100)->index();

                $table->string('value', 190);
                $table->string('label');
                $table->integer('sort')->default(0);
                $table->boolean('enabled')->default(true);

                // 徽章语义色：success | danger | warning | info | muted
                $table->string('color', 30)->nullable();

                $table->json('extra')->nullable();
                $table->timestamps();

                // 同一个字典下 value 不允许重复
                $table->unique(['type_code', 'value']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_dict_items');
        Schema::dropIfExists('admin_dict_types');
    }
};

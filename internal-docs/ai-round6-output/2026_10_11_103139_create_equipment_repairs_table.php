<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 设备报修表 —— 第六轮 AI 实测的任务载体。
 *
 * 字段刻意覆盖 v1.6.0 的新能力：
 *   - status / urgency   → 枚举，适合数据字典
 *   - photos             → 多图上传（images）
 *   - attachments        → 多文件上传（files）
 *   - reported_at        → 排序
 *   - handled_at/handler → 处理留痕（可被操作日志回答）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('equipment_repairs')) {
            return;
        }

        Schema::create('equipment_repairs', function (Blueprint $table): void {
            $table->id();
            $table->string('repair_no', 60)->unique();
            $table->string('equipment_name', 190);
            $table->string('reporter', 100);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('urgency', 20)->default('normal');
            $table->json('photos')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('handler', 100)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_repairs');
    }
};

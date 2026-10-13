<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 审计日志基座：操作日志 + 登录日志。
 *
 * ## 设计取舍
 *
 * **1. 用户信息冗余存储（username）**
 * 只存 user_id 的话，账号被删除后日志就变成孤儿，
 * 审计价值归零。所以 username 冗余存一份，且**不设外键**。
 *
 * **2. 索引按「查最近」设计**
 * 实际用法 99% 是「倒序看最近 N 条」或「筛某个人的最近记录」，
 * 因此 created_at 和 user_id 建索引，不做全字段索引。
 *
 * **3. payload 存 JSON 且脱敏**
 * 密码类字段在写入前就被掩码（见 AuditRecorder::sanitize），
 * 不允许出现「日志浏览器里有明文密码」这种事。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_operation_logs')) {
            Schema::create('admin_operation_logs', function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('user_id')->nullable()->index();
                // 冗余存账号名：账号删除后日志仍需可读
                $table->string('username', 190)->nullable();

                $table->string('method', 10);
                $table->string('path', 500);
                $table->string('resource_uri', 190)->nullable()->index();
                $table->string('action', 50)->nullable()->index();
                $table->string('target_id', 190)->nullable();

                $table->unsignedSmallInteger('status')->default(200);
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->json('payload')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();

                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('admin_login_logs')) {
            Schema::create('admin_login_logs', function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('username', 190)->nullable()->index();

                // success | failed
                $table->string('status', 20)->index();
                $table->string('reason', 190)->nullable();

                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();

                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operation_logs');
        Schema::dropIfExists('admin_login_logs');
    }
};

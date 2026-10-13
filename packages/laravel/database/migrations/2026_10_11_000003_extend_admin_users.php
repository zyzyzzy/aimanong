<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户管理增强：补上管理员真正需要的字段。
 *
 * 此前 admin_users 只有 username/name/password/avatar/enabled ——
 * 连邮箱都没有，做不了「找回密码」「按邮箱通知」这类基本事情。
 *
 * 用逐列判断而不是整表判断：用户的表可能已经手工加过其中几列，
 * 整表判断会跳过全部，导致缺列却以为迁移成功。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->add('email', fn (Blueprint $t) => $t->string('email', 190)->nullable()->index());
        $this->add('phone', fn (Blueprint $t) => $t->string('phone', 32)->nullable());
        $this->add('last_login_at', fn (Blueprint $t) => $t->timestamp('last_login_at')->nullable());
        $this->add('last_login_ip', fn (Blueprint $t) => $t->string('last_login_ip', 45)->nullable());
    }

    public function down(): void
    {
        foreach (['email', 'phone', 'last_login_at', 'last_login_ip'] as $column) {
            if (Schema::hasColumn('admin_users', $column)) {
                Schema::table('admin_users', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * @param  callable(Blueprint): mixed  $definition
     */
    protected function add(string $column, callable $definition): void
    {
        if (Schema::hasColumn('admin_users', $column)) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};

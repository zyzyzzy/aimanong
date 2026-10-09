<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RBAC 数据模型。
     *
     * 设计取舍：
     *   - 角色 → 权限：多对多（一个角色可含多个权限）
     *   - 用户 → 角色：多对多（一个用户可有多个角色；多数场景只用 1 个）
     *   - 权限用**字符串 slug**（如 `shop-products.update`）而非自增 id：
     *     这样 AI 与人类都能读懂，且不需要查表就知道权限名。
     *   - 不做「权限组」表：group 只是权限的展示分类字段，够用且更简单。
     */
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();      // 如 'admin' / 'operator'
            $table->string('name');                     // 显示名，如「超级管理员」
            $table->text('description')->nullable();
            $table->boolean('is_super')->default(false); // 超级管理员：绕过所有判定
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 190)->unique();      // 如 'shop-products.update'
            $table->string('name');                     // 如「编辑商品」
            $table->string('group')->nullable();        // 展示分组，如「商品管理」
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('group');
        });

        // 角色 ↔ 权限
        Schema::create('admin_permission_role', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->index();
            $table->unsignedBigInteger('permission_id')->index();
            $table->primary(['role_id', 'permission_id']);
        });

        // 用户 ↔ 角色
        Schema::create('admin_role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_role_user');
        Schema::dropIfExists('admin_permission_role');
        Schema::dropIfExists('admin_permissions');
        Schema::dropIfExists('admin_roles');
    }
};

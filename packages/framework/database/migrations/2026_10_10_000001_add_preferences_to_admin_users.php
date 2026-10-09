<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 用户界面偏好（主题/密度/圆角等）。
     *
     * 存 JSON 而非多列：配置项会随版本增加，
     * 加一列就要改表结构，JSON 更合适。
     *
     * 注意：**只存用户选了什么值**，不存"有哪些选项" ——
     * 后者在 ThemeConfig::options() 里（单一数据源）。
     */
    public function up(): void
    {
        if (Schema::hasColumn('admin_users', 'preferences')) {
            return;
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('admin_users', 'preferences')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('preferences');
            });
        }
    }
};

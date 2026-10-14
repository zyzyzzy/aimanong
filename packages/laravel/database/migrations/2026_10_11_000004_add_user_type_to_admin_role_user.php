<?php

use Aimanong\Models\Administrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_role_user', 'user_type')) {
            Schema::table('admin_role_user', function (Blueprint $table): void {
                // 存用户模型类名 —— 多应用下区分「这个 id 属于哪张用户表」
                $table->string('user_type', 190)->nullable()->after('user_id');
                $table->index(['user_type', 'user_id'], 'idx_role_user_type');
            });
        }

        /*
         * 回填既有记录。
         *
         * 加列之前所有关联都来自单应用（只有 admin_users），
         * 所以一律标注为 Administrator —— 这样升级后既有授权不丢。
         */
        DB::table('admin_role_user')
            ->whereNull('user_type')
            ->update(['user_type' => Administrator::class]);
    }

    public function down(): void
    {
        Schema::table('admin_role_user', function (Blueprint $table): void {
            $table->dropIndex('idx_role_user_type');
            $table->dropColumn('user_type');
        });
    }
};

<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Resources;

use Aimanong\Auth\PermissionGate;
use Aimanong\Contracts\ApplicationResolver;
use Aimanong\Form\Form;
use Aimanong\Foundation\Audit\OperationLog;
use Aimanong\Grid\Grid;
use Aimanong\Models\Administrator;
use Aimanong\Models\Role;
use Aimanong\Resource;
use Aimanong\Show\Show;
use Illuminate\Support\Collection;

/**
 * 管理员账号（内置 Resource）。
 *
 * ## 为什么内置
 *
 * v1.3.0 起框架就带了 admin guard + 用户表，但**没有管理界面** ——
 * 用户只能手工改数据库。这是「带地基的平台」最尴尬的缺口：
 * 地基埋好了，没有门。
 *
 * ## 密码字段的三个声明
 *
 * ```php
 * $form->password('password')
 *     ->requiredOnCreate()   // 新增必填
 *     ->omitWhenEmpty()      // 编辑留空 = 不改密码（不提交这个键）
 *     ->min(6);
 * ```
 *
 * 少任何一个都会出事：
 *   - 只有 required()：编辑时被自己的规则卡住，改不了名字
 *   - 只有 nullable()：新增时静默存进空密码，账号进不去
 *   - 少了 omitWhenEmpty()：`'hashed'` cast 把空串哈希成新密码，
 *     账号当场失效且不报错
 */
class AdminUserResource extends Resource
{
    public static function model(): string
    {
        return Administrator::class;
    }

    public static function label(): string
    {
        return '管理员';
    }

    public static function uri(): string
    {
        return 'admin-users';
    }

    public static function menu(): array
    {
        return ['group' => '系统', 'icon' => '👤', 'sort' => 89];
    }

    /**
     * 只属于总管理后台。
     *
     * 内置的系统管理类（管理员/角色/权限/日志/字典）属于框架运维面，
     * 不该出现在用户后台或代理商后台 —— 菜单串台等于把后台结构
     * 暴露给不该看的人。
     */
    public static function applications(): array
    {
        return [ApplicationResolver::PRIMARY];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);

        $grid->column('id', 'ID')->sortable();
        $grid->column('username', '账号')->searchable();
        $grid->column('name', '姓名')->searchable();
        $grid->column('email', '邮箱')->searchable();
        // 多对多关联列：显示角色名而不是 id
        $grid->column('roles.name', '角色');
        $grid->column('enabled', '状态')->bool('启用', '停用');
        $grid->column('last_login_at', '最后登录')->dateTime()->sortable();
        $grid->column('last_login_ip', '登录 IP');
        $grid->column('created_at', '创建时间')->dateTime()->sortable();
    }

    public static function form(Form $form): void
    {
        $form->text('username')->label('账号')->required()->max(190)
            ->help('登录名，唯一。重复会返回 422 并指出是哪个字段。');

        $form->text('name')->label('姓名')->required()->max(190);
        $form->email('email')->label('邮箱')->max(190);
        $form->tel('phone')->label('手机号');

        $form->password('password')->label('密码')
            ->requiredOnCreate()
            ->omitWhenEmpty()
            ->min(6)
            ->help('新增时必填；编辑时留空表示不修改。');

        $form->image('avatar')->label('头像')->maxSize(2048);

        $form->multiSelect('roles')->label('角色')
            ->relation('roles')
            ->options(self::roleOptions())
            ->help('没勾任何角色 = 没有任何权限（超级管理员角色除外）。');

        $form->switch('enabled')->label('是否启用')->default(true)
            ->help('停用后该账号无法登录，已登录的会话在下次请求时失效。');
    }

    public static function show(Show $show): void
    {
        $show->fields([
            'id', 'username', 'name', 'email', 'phone', 'avatar',
            'enabled', 'last_login_at', 'last_login_ip', 'created_at',
        ]);
    }

    /**
     * 角色下拉。
     *
     * @return array<int, string>
     */
    public static function roleOptions(): array
    {
        try {
            $query = Role::query()->orderBy('sort')->orderBy('id');

            /*
             * 🔴 非超管**看不到**超管角色。
             *
             * 审计发现（2026-10-16）：下拉原先列出全部角色，
             * 包括 is_super=1 的两个 —— 一个只有 admin-users.update
             * 的账号可以直接在下拉里选中「超级管理员」并保存，完成自我提权。
             *
             * 下拉过滤只是**第一层**（不提供选项）；真正的门在
             * authorizeRelation()（服务端拒绝）。
             * 两层都要有：只过滤下拉等于「藏起来」，curl 一样能提交。
             */
            if (! self::currentUserIsSuper()) {
                $query->where('is_super', false);
            }

            /** @var array<int, string> $options */
            $options = $query->pluck('name', 'id')->all();

            return $options;
        } catch (\Throwable) {
            // 还没跑迁移 / 表不存在：给空数组而不是让整个后台 500
            return [];
        }
    }

    /**
     * 当前登录用户是不是超级管理员。
     *
     * 没有登录上下文（CLI / 单测）时返回 false —— 保守默认，
     * 与「越权风险宁可多拦」一致。
     */
    protected static function currentUserIsSuper(): bool
    {
        $user = PermissionGate::user();

        if (! is_object($user) || ! method_exists($user, 'isSuper')) {
            return false;
        }

        try {
            return (bool) $user->isSuper();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 角色关联的授权门。
     *
     * 审计实测（2026-10-16）：多对多原先直接 sync()，零校验 ——
     * 一个只有 admin-users.update 权限的账号把 roles 提交成
     * [超级管理员角色 id] 就完成了自我提权（改前处处 403，改后处处 200）。
     *
     * 规则：**非超管不得授予或撤销任何超管角色**。
     * 撤销也要拦 —— 否则可以把别的管理员踢下超管。
     */
    public static function authorizeRelation(string $relation, array $ids): void
    {
        if ($relation !== 'roles' || self::currentUserIsSuper()) {
            return;
        }

        $superIds = Role::query()
            ->where('is_super', true)
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        if ($superIds === []) {
            return;
        }

        foreach ($ids as $id) {
            if (in_array((int) $id, $superIds, true)) {
                throw new \RuntimeException(
                    '不能授予或撤销「超级管理员」角色 —— '
                    .'只有超级管理员本人可以调整超管角色。'
                );
            }
        }

        /*
         * 还要拦「把别人的超管角色**撤掉**」这一侧：
         * 提交里不含超管 id，但目标用户当前有。
         */
        $target = self::pendingTarget();

        if ($target === null) {
            return;
        }

        $had = $target->roles()
            ->where('is_super', true)
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        foreach ($had as $id) {
            if (! in_array($id, array_map('intval', $ids), true)) {
                throw new \RuntimeException(
                    '不能撤销他人的「超级管理员」角色 —— '
                    .'只有超级管理员本人可以调整超管角色。'
                );
            }
        }
    }

    /**
     * 本次请求正在改的那个账号（用于判断「原本有哪些超管角色」）。
     *
     * 新建时为 null。
     */
    protected static function pendingTarget(): ?Administrator
    {
        try {
            /** @var mixed $id */
            $id = request()->route('id');

            if (! is_int($id) && ! is_string($id)) {
                return null;
            }

            /** @var Administrator|null $found */
            $found = Administrator::query()->find($id);

            return $found;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 账号的最近操作记录（给「用户详情」用）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentOperations(int $userId, int $limit = 10): array
    {
        try {
            /** @var Collection<int, OperationLog> $rows */
            $rows = OperationLog::query()
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            $out = [];

            foreach ($rows as $log) {
                $out[] = [
                    'at' => $log->created_at?->format('Y-m-d H:i:s'),
                    'action' => $log->actionLabel(),
                    'resource' => $log->resource_uri,
                    'target' => $log->target_id,
                    'ip' => $log->ip,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}

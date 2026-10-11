<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Profile;

use Aimanong\Aimanong;
use Aimanong\Foundation\Resources\AdminUserResource;
use Aimanong\Foundation\Upload\Uploader;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 个人中心。
 *
 * ## 为什么不是 Resource
 *
 * Resource 的心智模型是「一张表 = 一组页面」，而个人中心操作的是
 * **当前登录者自己**（不存在 id 参数，也不该有列表/删除）。
 * 硬套 Resource 会得到一个「理论上能删自己」的页面。
 *
 * ## 与操作日志、上传的关系
 *
 * - 「我的操作记录」直接读审计日志（段 1 的产物）
 * - 「头像」用 Uploader（段 3 的产物）
 * 这正是 P0 六项必须连着做的原因：它们是互相咬合的。
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $this->assertEnabled();

        $user = Aimanong::user();

        return view('aimanong::profile', [
            'title' => '个人中心',
            'user' => $user,
            // 只把允许改的字段交给前端；password 等绝不外发
            'userData' => $this->userData(),
            'roles' => $this->roleNames(),
            'permissions' => $this->permissionSlugs(),
            'operations' => $this->operations(),
            'upload' => (new Uploader)->toArray(),
        ]);
    }

    /**
     * 更新基本资料。
     */
    public function update(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $user = Aimanong::user();

        if ($user === null) {
            abort(401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'avatar' => ['nullable', 'string', 'max:500'],
        ], [], [
            'name' => '姓名',
            'email' => '邮箱',
            'phone' => '手机号',
        ]);

        /*
         * 只允许改这四个字段。
         *
         * 绝不接受 enabled / roles / password —— 个人中心是「改自己」，
         * 不是「给自己提权」。用白名单而不是黑名单，
         * 将来模型新增敏感字段时不会自动变成可改。
         */
        $this->fill($user, $data);

        try {
            $user->save();
        } catch (QueryException $e) {
            return response()->json([
                'message' => '保存失败：'.($e->getMessage()),
            ], 422);
        }

        return response()->json(['message' => '资料已更新']);
    }

    /**
     * 修改密码。
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $this->assertEnabled();

        $user = Aimanong::user();

        if ($user === null) {
            abort(401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [], [
            'current_password' => '当前密码',
            'password' => '新密码',
        ]);

        // 必须验旧密码：否则一个被劫持的会话可以直接改掉密码
        if (! Hash::check($data['current_password'], (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['当前密码不正确'],
            ]);
        }

        $this->fill($user, ['password' => $data['password']]);
        $user->save();

        return response()->json(['message' => '密码已修改']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fill(object $user, array $data): void
    {
        foreach ($data as $key => $value) {
            if (method_exists($user, 'setAttribute')) {
                $user->setAttribute($key, $value);
            }
        }
    }

    /**
     * 前端可见的用户资料（白名单，不含密码等敏感字段）。
     *
     * @return array<string, string>
     */
    protected function userData(): array
    {
        $user = Aimanong::user();
        $out = [];

        foreach (['username', 'name', 'email', 'phone', 'avatar'] as $key) {
            $value = $user?->getAttribute($key);
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    protected function assertEnabled(): void
    {
        if (! config('aimanong.foundation.profile.enable', true)) {
            abort(404, '个人中心已被配置关闭（aimanong.foundation.profile.enable）。');
        }
    }

    /**
     * @return array<int, string>
     */
    protected function roleNames(): array
    {
        $user = Aimanong::user();

        if ($user === null || ! method_exists($user, 'roles')) {
            return [];
        }

        try {
            $out = [];

            /** @var iterable<Model> $roles */
            $roles = $user->roles()->get();

            foreach ($roles as $role) {
                $name = $role->getAttribute('name');
                $out[] = is_string($name) ? $name : '';
            }

            return array_values(array_filter($out));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 当前用户**实际持有**的权限节点。
     *
     * @return array<int, string>
     */
    protected function permissionSlugs(): array
    {
        $user = Aimanong::user();

        if ($user === null) {
            return [];
        }

        try {
            if (method_exists($user, 'isSuper') && $user->isSuper()) {
                return ['__super__'];
            }

            if (! method_exists($user, 'roles')) {
                return [];
            }

            $out = [];

            /** @var iterable<Model> $roles */
            $roles = $user->roles()->with('permissions')->get();

            foreach ($roles as $role) {
                /** @var mixed $permissions */
                $permissions = $role->getRelationValue('permissions');

                if (! is_iterable($permissions)) {
                    continue;
                }

                foreach ($permissions as $permission) {
                    if (! $permission instanceof Model) {
                        continue;
                    }

                    $slug = $permission->getAttribute('slug');

                    if (is_string($slug)) {
                        $out[$slug] = true;
                    }
                }
            }

            $keys = array_keys($out);
            sort($keys);

            return $keys;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 我的最近操作记录（读审计日志）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function operations(): array
    {
        if (! config('aimanong.foundation.operation_log.enable', true)) {
            return [];
        }

        $id = Aimanong::user()?->getAuthIdentifier();

        if (! is_numeric($id)) {
            return [];
        }

        return AdminUserResource::recentOperations((int) $id, 15);
    }
}

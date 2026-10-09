<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Models\Administrator;
use Aimanong\Ui\ThemeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 界面偏好接口。
 *
 * 双轨持久化：
 *   - localStorage（前端即时生效，防闪屏）
 *   - 服务端 admin_users.preferences（跨设备同步）
 */
class UiController extends BaseController
{
    /**
     * 读取当前用户偏好。
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->preferences(),
            'options' => ThemeConfig::introspect(),
        ]);
    }

    /**
     * 保存偏好。
     */
    public function store(Request $request): JsonResponse
    {
        $user = Aimanong::user();

        if (! $user instanceof Administrator) {
            return response()->json(['message' => '未登录'], 401);
        }

        /** @var array<string, mixed> $input */
        $input = $request->all();
        $normalized = ThemeConfig::normalize($input);

        $user->preferences = $normalized;
        $user->save();

        return response()->json([
            'message' => '已保存',
            'data' => $normalized,
        ]);
    }

    /**
     * 当前用户偏好（未登录时返回默认值）。
     *
     * @return array<string, mixed>
     */
    protected function preferences(): array
    {
        $user = Aimanong::user();

        if (! $user instanceof Administrator || ! is_array($user->preferences)) {
            return ThemeConfig::defaults();
        }

        return ThemeConfig::normalize($user->preferences);
    }
}

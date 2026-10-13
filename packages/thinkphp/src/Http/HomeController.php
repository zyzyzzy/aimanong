<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\Menu\MenuRegistry;
use Aimanong\ThinkPHP\View\NullErrors;
use Aimanong\ThinkPHP\View\ThinkView;
use think\Response;

/**
 * 控制台首页（工作台）。
 *
 * 与 Laravel 侧的 `HomeController::index()` 提供**同一组数据** ——
 * 模板是同一份真源转出来的，数据形状不一致就会渲染出错。
 *
 * ## 与 Laravel 侧的差异（如实说明）
 *
 * | 数据 | Laravel | ThinkPHP（当前） |
 * |---|---|---|
 * | user / roles / permCount | Administrator + 角色关系 | **未实现认证** → null / 空 |
 * | ui（主题偏好） | 存在用户偏好里 | 用默认主题 |
 * | resourceCount | 注册表 | 相同（core 的 Registry） |
 * | quickLinks | 按权限过滤的菜单 | 相同（core 的 MenuRegistry + 权限驱动） |
 *
 * 缺失的部分**给的是空值而不是假数据** —— 界面会显示「0 个角色」，
 * 而不是编一个数字出来。
 */
final class HomeController
{
    public function index(): Response
    {
        $menuRegistry = new MenuRegistry;

        $quickLinks = [];

        foreach ($menuRegistry->visible() as $item) {
            $quickLinks[] = [
                'uri' => $item->uri,
                'label' => $item->label,
                'icon' => $item->icon,
            ];
        }

        $html = (new ThinkView)->render('index', [
            'user' => Aimanong::user(),
            'resourceCount' => Aimanong::registry()->count(),
            'menu' => $menuRegistry->tree(),
            'quickLinks' => $quickLinks,
            'roles' => [],
            'permCount' => 0,
            'isSuper' => false,
            'userCount' => 0,
            'rbacEnabled' => false,
            'ui' => $this->defaultUi(),
            'errors' => new NullErrors,
            'menuRegistry' => $menuRegistry,
        ]);

        return Response::create($html, 'html', 200);
    }

    /**
     * 默认主题偏好。
     *
     * Laravel 侧从用户的 preferences 取；TP 侧认证还没做，
     * 所以先用默认值 —— 界面能正常渲染，只是没有个性化。
     *
     * @return array<string, mixed>
     */
    protected function defaultUi(): array
    {
        return [
            'primary' => '#2563eb',
            'radius' => 'md',
            'density' => 'normal',
            'dark' => false,
            'sidebar' => 'expanded',
        ];
    }
}

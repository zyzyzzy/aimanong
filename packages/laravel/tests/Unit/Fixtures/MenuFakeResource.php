<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Resource;
use Illuminate\Foundation\Auth\User;

/**
 * 菜单测试用的假 Resource。
 */
class MenuFakeResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function uri(): string
    {
        return 'menu-fakes';
    }

    public static function label(): string
    {
        return '假资源';
    }
}

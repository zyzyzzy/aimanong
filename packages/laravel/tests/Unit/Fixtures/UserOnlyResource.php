<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Resource;
use Illuminate\Foundation\Auth\User;

/**
 * 只属于用户后台的 Resource。
 */
class UserOnlyResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function uri(): string
    {
        return 'user-only';
    }

    public static function label(): string
    {
        return '仅用户后台';
    }

    public static function applications(): array
    {
        return ['user'];
    }

    public static function menu(): array
    {
        return ['group' => '我的', 'sort' => 20];
    }
}

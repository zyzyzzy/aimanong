<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Resource;
use Illuminate\Foundation\Auth\User;

/**
 * 只属于总后台与代理商后台的 Resource。
 */
class AppScopedResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function uri(): string
    {
        return 'app-scoped';
    }

    public static function label(): string
    {
        return '限定后台';
    }

    public static function applications(): array
    {
        return ['admin', 'agent'];
    }

    public static function menu(): array
    {
        return ['group' => '交易', 'sort' => 10];
    }
}

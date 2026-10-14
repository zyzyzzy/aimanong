<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Resource;
use Illuminate\Foundation\Auth\User;

/**
 * 不声明归属 —— 属于所有后台。
 */
class EverywhereResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function uri(): string
    {
        return 'everywhere';
    }

    public static function label(): string
    {
        return '所有后台';
    }

    public static function menu(): array
    {
        return ['group' => '通用', 'sort' => 30];
    }
}

<?php

declare(strict_types=1);

/*
 * 单元测试引导。
 *
 * ## 为什么需要它
 *
 * 单测是纯 PHPUnit（不起 Laravel 应用），因此 `AimanongServiceProvider::register()`
 * 不会跑。驱动注册表（`Aimanong\Support\Drivers`）为空时，
 * 用到数据字典 / 权限 / 上传的代码会抛 `DriverNotRegisteredException` ——
 * 而这是**正确的**行为（声明了能力却没有驱动）。
 *
 * 所以这里显式注册与真实应用**同一套**驱动：
 * 如果哪天驱动的构造方式变了而这里没跟着改，测试会立刻红，
 * 而不是等到生产环境。
 *
 * 顺序要求：先加载 autoload，再注册（注册要用到类）。
 */

require __DIR__.'/../vendor/autoload.php';

use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Drivers;

Drivers::registerIntrospector(new EloquentIntrospector);
Drivers::registerDictionary(new Dictionary);
Drivers::registerPermissionChecker(new PermissionGateChecker);
Drivers::registerUploadSpec(new Uploader);

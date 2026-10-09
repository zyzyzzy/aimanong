<?php

declare(strict_types=1);

use Aimanong\Region\Region;

if (! function_exists('region_name')) {
    /**
     * 通过地区编码取完整名称。
     *
     * 例：region_name('110101') → 「北京市/市辖区/东城区」
     */
    function region_name(?string $code): ?string
    {
        return $code === null ? null : Region::fullName($code);
    }
}

if (! function_exists('region_provinces')) {
    /**
     * 全部省份。
     *
     * @return array<int, array{code: string, name: string}>
     */
    function region_provinces(): array
    {
        return Region::provinces();
    }
}

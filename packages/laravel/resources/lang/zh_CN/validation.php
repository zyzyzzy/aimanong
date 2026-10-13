<?php

declare(strict_types=1);

/*
 * 中文校验消息。
 *
 * 为什么框架自带：这是**中文后台框架**，默认给用户看
 * 「The slug field is required.」既违和又降低可用性 ——
 * 尤其这些消息会直接出现在 AI 生成的表单上。
 *
 * 只覆盖后台最常用的规则（required / max / min / email / unique /
 * numeric / between / in / confirmed / integer / date 等），
 * 不做完整翻译 —— 保持体积极小（约 4KB），需要更全的可以装
 * laravel-lang/lang。
 *
 * 用法：config/app.php 里设置 'locale' => 'zh_CN'
 */
return [
    'accepted' => ':attribute 必须接受',
    'active_url' => ':attribute 不是有效的网址',
    'after' => ':attribute 必须晚于 :date',
    'after_or_equal' => ':attribute 必须等于或晚于 :date',
    'alpha' => ':attribute 只能由字母组成',
    'alpha_dash' => ':attribute 只能由字母、数字、下划线和短横线组成',
    'alpha_num' => ':attribute 只能由字母和数字组成',
    'array' => ':attribute 必须是数组',
    'before' => ':attribute 必须早于 :date',
    'before_or_equal' => ':attribute 必须等于或早于 :date',
    'between' => [
        'array' => ':attribute 只能有 :min - :max 个',
        'file' => ':attribute 必须介于 :min - :max KB 之间',
        'numeric' => ':attribute 必须介于 :min - :max 之间',
        'string' => ':attribute 必须介于 :min - :max 个字符之间',
    ],
    'boolean' => ':attribute 必须为布尔值',
    'confirmed' => ':attribute 两次输入不一致',
    'date' => ':attribute 不是有效的日期',
    'date_equals' => ':attribute 必须等于 :date',
    'date_format' => ':attribute 的格式必须为 :format',
    'different' => ':attribute 和 :other 不能相同',
    'digits' => ':attribute 必须是 :digits 位数字',
    'digits_between' => ':attribute 必须是 :min - :max 位数字',
    'email' => ':attribute 不是有效的邮箱地址',
    'ends_with' => ':attribute 必须以 :values 为结尾',
    'exists' => '选定的 :attribute 无效',
    'file' => ':attribute 必须是文件',
    'filled' => ':attribute 不能为空',
    'gt' => [
        'array' => ':attribute 必须多于 :value 个',
        'file' => ':attribute 必须大于 :value KB',
        'numeric' => ':attribute 必须大于 :value',
        'string' => ':attribute 必须多于 :value 个字符',
    ],
    'gte' => [
        'array' => ':attribute 必须不少于 :value 个',
        'file' => ':attribute 必须大于或等于 :value KB',
        'numeric' => ':attribute 必须大于或等于 :value',
        'string' => ':attribute 必须不少于 :value 个字符',
    ],
    'image' => ':attribute 必须是图片',
    'in' => '选定的 :attribute 无效',
    'in_array' => ':attribute 未在 :other 中出现',
    'integer' => ':attribute 必须是整数',
    'ip' => ':attribute 必须是有效的 IP 地址',
    'ipv4' => ':attribute 必须是有效的 IPv4 地址',
    'ipv6' => ':attribute 必须是有效的 IPv6 地址',
    'json' => ':attribute 必须是有效的 JSON 字符串',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个',
        'file' => ':attribute 必须小于 :value KB',
        'numeric' => ':attribute 必须小于 :value',
        'string' => ':attribute 必须少于 :value 个字符',
    ],
    'lte' => [
        'array' => ':attribute 必须不多于 :value 个',
        'file' => ':attribute 必须小于或等于 :value KB',
        'numeric' => ':attribute 必须小于或等于 :value',
        'string' => ':attribute 必须不多于 :value 个字符',
    ],
    'max' => [
        'array' => ':attribute 最多 :max 个',
        'file' => ':attribute 不能大于 :max KB',
        'numeric' => ':attribute 不能大于 :max',
        'string' => ':attribute 不能超过 :max 个字符',
    ],
    'mimes' => ':attribute 必须是 :values 格式的文件',
    'mimetypes' => ':attribute 必须是 :values 格式的文件',
    'min' => [
        'array' => ':attribute 至少 :min 个',
        'file' => ':attribute 不能小于 :min KB',
        'numeric' => ':attribute 不能小于 :min',
        'string' => ':attribute 不能少于 :min 个字符',
    ],
    'not_in' => '选定的 :attribute 无效',
    'not_regex' => ':attribute 格式不正确',
    'numeric' => ':attribute 必须是数字',
    'present' => ':attribute 必须存在',
    'regex' => ':attribute 格式不正确',
    'required' => ':attribute 不能为空',
    'required_if' => '当 :other 为 :value 时 :attribute 不能为空',
    'required_unless' => '除非 :other 为 :values，否则 :attribute 不能为空',
    'required_with' => '当 :values 存在时 :attribute 不能为空',
    'required_with_all' => '当 :values 都存在时 :attribute 不能为空',
    'required_without' => '当 :values 不存在时 :attribute 不能为空',
    'required_without_all' => '当 :values 都不存在时 :attribute 不能为空',
    'same' => ':attribute 和 :other 必须一致',
    'size' => [
        'array' => ':attribute 必须包含 :size 个',
        'file' => ':attribute 必须是 :size KB',
        'numeric' => ':attribute 必须是 :size',
        'string' => ':attribute 必须是 :size 个字符',
    ],
    'starts_with' => ':attribute 必须以 :values 为开头',
    'string' => ':attribute 必须是字符串',
    'timezone' => ':attribute 必须是有效的时区',
    'unique' => ':attribute 已存在',
    'uploaded' => ':attribute 上传失败',
    'url' => ':attribute 格式不正确',

    /*
     * 字段名映射。
     *
     * 后台常见字段的默认中文名 —— 命中就直接用，不必每个 Resource
     * 都去定义 attributes。未命中的字段仍由 Laravel 按原样显示
     * （下划线转空格），前端表单的 label 才是主要的可读来源。
     */
    'attributes' => [
        'name' => '名称',
        'title' => '标题',
        'slug' => '别名',
        'email' => '邮箱',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'username' => '用户名',
        'phone' => '手机号',
        'status' => '状态',
        'sort' => '排序',
        'description' => '描述',
        'content' => '内容',
        'body' => '正文',
        'remark' => '备注',
        'amount' => '金额',
        'price' => '价格',
        'quantity' => '数量',
        'stock' => '库存',
        'category_id' => '所属分类',
        'parent_id' => '上级',
        'user_id' => '用户',
        'order_no' => '订单号',
        'published_at' => '发布时间',
    ],

    'custom' => [],
];

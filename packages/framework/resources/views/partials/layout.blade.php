{{--
  统一布局 —— 所有后台页面的唯一骨架。

  ## 消灭重复
    改造前：index.blade.php / resource.blade.php / auth/login.blade.php
    各自写了一套 CSS，共 3 份重复。
    现在：全部 @include 本文件 + design-system。

  ## 关键：防深色闪屏（FOUC）
    内联脚本必须**在引入 CSS 之前**写好 data-theme / data-mode，
    否则深色用户会先看到白色再跳黑。

  ## AI 提示
    新增页面请 @extends('aimanong::partials.layout')，
    不要自己写 <html>/<head>/<body>。
--}}
@php
  use Aimanong\Ui\ThemeConfig;
  $ui = $ui ?? ThemeConfig::defaults();
  $asset = fn (string $p): string => \Aimanong\Aimanong::asset()->url($p);
@endphp
<!DOCTYPE html>
<html lang="zh-CN" data-theme="{{ $ui['style'] }}" data-mode="{{ $ui['dark'] === 'auto' ? 'auto' : $ui['dark'] }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="{{ $ui['dark'] === 'dark' ? 'dark' : 'light' }}">
<title>{{ $title ?? '控制台' }} · {{ \Aimanong\Aimanong::brand() }}</title>

{{-- 防闪屏：先定主题，再加载 CSS --}}
<script>
(function () {
  try {
    var s = JSON.parse(localStorage.getItem('aimanong.ui') || '{}');
    var root = document.documentElement;
    if (s.style) root.dataset.theme = s.style;
    if (s.dark) {
      root.dataset.mode = s.dark;
      if (s.dark === 'dark') root.style.colorScheme = 'dark';
      if (s.dark === 'light') root.style.colorScheme = 'light';
    }
    // 显式深色/浅色；auto 交给 CSS 的 prefers-color-scheme
    if (!s.dark || s.dark === 'auto') {
      root.dataset.mode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
  } catch (e) {}
})();
</script>

{{--
  加载顺序很重要：
    1. design-system.css —— 组件 + **颜色兜底值**（:root）
    2. themes.css        —— 主题色令牌，必须在其后加载才能覆盖兜底值
    3. user-prefs.css    —— 用户偏好（密度/圆角/自定义主色），最后覆盖

  若把 themes 放在 design-system 之前，兜底值会反向覆盖主题，
  表现为「切换主题无效」（已踩过一次）。
--}}
<link rel="stylesheet" href="{{ $asset('css/design-system.css') }}">
<link rel="stylesheet" href="{{ $asset('css/themes.css') }}">
<link rel="stylesheet" href="{{ $asset('css/user-prefs.css') }}">
<link rel="icon" href="{{ $asset('favicon-32.png') }}">
@stack('head')
</head>
<body>
<div class="am-shell">
  @include('aimanong::partials.sidebar')

  <div class="am-main">
    @include('aimanong::partials.topbar')
    <div class="am-content">
      @yield('content')
    </div>
    @if($ui['footer'] ?? true)
      <footer class="am-footer">
        <span>{{ \Aimanong\Aimanong::brand() }} <span class="am-text-muted">v{{ \Aimanong\Aimanong::version() }}</span></span>
      </footer>
    @endif
  </div>
</div>

@include('aimanong::partials.theme-panel')
@stack('scripts')
</body>
</html>

{{--
  登录页。

  设计要点：
    - 用设计系统的令牌，随主题/深色模式自动适配
    - 不引用 layout（登录页没有侧边栏），但复用同一套 CSS
    - 防闪屏：内联脚本先定主题
--}}
@php
  use Aimanong\Ui\ThemeConfig;
  $ui = ThemeConfig::defaults();
  $asset = fn (string $p): string => \Aimanong\Aimanong::asset()->url($p);
@endphp
<!DOCTYPE html>
<html lang="zh-CN" data-theme="{{ $ui['style'] }}" data-mode="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>登录 · {{ \Aimanong\Aimanong::brand() }}</title>

<script>
(function () {
  try {
    var s = JSON.parse(localStorage.getItem('aimanong.ui') || '{}');
    var r = document.documentElement;
    if (s.style) r.dataset.theme = s.style;
    var m = s.dark && s.dark !== 'auto' ? s.dark
          : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    r.dataset.mode = m;
    r.style.colorScheme = m;
    if (s.primary) r.style.setProperty('--brand-solid', s.primary);
  } catch (e) {}
})();
</script>

{{-- 顺序：design-system（含兜底色）→ themes（覆盖）→ user-prefs --}}
<link rel="stylesheet" href="{{ $asset('css/design-system.css') }}">
<link rel="stylesheet" href="{{ $asset('css/themes.css') }}">
<link rel="stylesheet" href="{{ $asset('css/user-prefs.css') }}">
<link rel="icon" href="{{ $asset('favicon-32.png') }}">

<style>
  body {
    min-height: 100vh;
    display: grid;
    place-items: center;
    background: var(--bg-canvas);
    position: relative;
    overflow: hidden;
  }

  /* 背景装饰：品牌色光晕 + 网格，给出"科技感"但不喧宾夺主 */
  body::before {
    content: '';
    position: fixed;
    inset: -20% -10%;
    background:
      radial-gradient(38% 42% at 22% 26%, color-mix(in srgb, var(--brand-solid) 16%, transparent), transparent 70%),
      radial-gradient(32% 36% at 78% 74%, color-mix(in srgb, var(--brand-solid) 11%, transparent), transparent 70%);
    filter: blur(28px);
    pointer-events: none;
    z-index: 0;
  }
  [data-mode="dark"] body::before { opacity: .55; }

  body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(var(--border-subtle) 1px, transparent 1px),
      linear-gradient(90deg, var(--border-subtle) 1px, transparent 1px);
    background-size: 56px 56px;
    mask-image: radial-gradient(60% 60% at 50% 45%, #000 20%, transparent 100%);
    -webkit-mask-image: radial-gradient(60% 60% at 50% 45%, #000 20%, transparent 100%);
    opacity: .5;
    pointer-events: none;
    z-index: 0;
  }

  .lg-wrap { position: relative; z-index: 1; width: 100%; max-width: 400px; padding: var(--sp-5); }

  .lg-brand { display: flex; flex-direction: column; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-6); }
  .lg-logo { height: 60px; width: auto; }
  .lg-title { font-size: var(--fs-xl); font-weight: 680; letter-spacing: -.02em; }
  .lg-sub { font-size: var(--fs-sm); color: var(--text-tertiary); margin-top: -6px; }

  .lg-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-default);
    border-radius: var(--r-xl);
    box-shadow: 0 24px 48px rgba(16,24,40,.12), 0 1px 2px rgba(16,24,40,.04);
    padding: var(--sp-6);
  }

  .lg-field { margin-bottom: var(--sp-4); }
  .lg-field label { display: block; font-size: var(--fs-sm); font-weight: 500; color: var(--text-secondary); margin-bottom: var(--sp-2); }

  /* 输入框内嵌前置图标（借鉴 owladmin 的细节） */
  .lg-input-wrap { position: relative; }
  .lg-input-wrap svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; }
  .lg-input-wrap .am-input { padding-left: 36px; height: 40px; }

  .lg-btn { width: 100%; height: 42px; font-size: var(--fs-md); font-weight: 600; margin-top: var(--sp-2); }

  .lg-foot { margin-top: var(--sp-5); text-align: center; font-size: var(--fs-xs); color: var(--text-tertiary); }

  /* 入场动画 */
  @keyframes lgIn { from { opacity: 0; transform: translateY(10px); } }
  .lg-wrap { animation: lgIn .4s cubic-bezier(.16,1,.3,1); }
  @media (prefers-reduced-motion: reduce) { .lg-wrap { animation: none; } }
</style>
</head>
<body>
<div class="lg-wrap">
  <div class="lg-brand">
    <img class="lg-logo" src="{{ $asset('logo.png') }}" alt="{{ \Aimanong\Aimanong::brand() }}">
    <div class="lg-title">{{ \Aimanong\Aimanong::brand() }}</div>
    <div class="lg-sub">AI 码农 · 后台开发框架</div>
  </div>

  <div class="lg-card">
    @if($errors->any())
      <div class="am-alert am-alert--danger" style="margin-bottom:var(--sp-4)">
        @include('aimanong::partials.icon', ['name' => 'alert', 'size' => 16])
        <span>{{ $errors->first() }}</span>
      </div>
    @endif

    <form method="POST" action="{{ url(\Aimanong\Aimanong::url('auth/login')) }}">
      @csrf

      <div class="lg-field">
        <label for="username">用户名</label>
        <div class="lg-input-wrap">
          @include('aimanong::partials.icon', ['name' => 'user', 'size' => 16])
          <input class="am-input" type="text" id="username" name="username"
                 value="{{ old('username') }}" required autofocus autocomplete="username">
        </div>
      </div>

      <div class="lg-field">
        <label for="password">密码</label>
        <div class="lg-input-wrap">
          @include('aimanong::partials.icon', ['name' => 'lock', 'size' => 16])
          <input class="am-input" type="password" id="password" name="password"
                 required autocomplete="current-password">
        </div>
      </div>

      <button type="submit" class="am-btn am-btn--primary lg-btn">登 录</button>
    </form>
  </div>

  <div class="lg-foot">
    {{ \Aimanong\Aimanong::brand() }} v{{ \Aimanong\Aimanong::version() }} ·
    Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}
  </div>
</div>
</body>
</html>

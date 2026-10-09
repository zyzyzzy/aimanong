{{--
  登录页 —— 左右分割布局。

  ## 为什么是左右分割
    优设《登录页设计的 12 种切入点》指出：左右分割是登录页
    「最稳健高效的布局之一」，12 个案例中有 10 个采用。
    相比居中卡片，它能同时承载**品牌表达**与**功能表单**。

  ## 结构
    左（品牌区）：深色渐变 + 品牌主张 + 能力亮点 —— 负责「大气」
    右（表单区）：干净留白 + 清晰表单 —— 负责「好用」

  ## 主题适配
    左侧用主色派生的深色渐变，随三套风格自动变色；
    右侧用设计系统令牌，深色模式下自动反转。
--}}
@php
  use Aimanong\Ui\ThemeConfig;
  $ui = ThemeConfig::defaults();
  $asset = fn (string $p): string => \Aimanong\Aimanong::asset()->url($p);
  $brand = \Aimanong\Aimanong::brand();
@endphp
<!DOCTYPE html>
<html lang="zh-CN" data-theme="{{ $ui['style'] }}" data-mode="auto">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>登录 · {{ $brand }}</title>

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

<link rel="stylesheet" href="{{ $asset('css/design-system.css') }}">
<link rel="stylesheet" href="{{ $asset('css/themes.css') }}">
<link rel="stylesheet" href="{{ $asset('css/user-prefs.css') }}">
<link rel="icon" href="{{ $asset('favicon-32.png') }}">

<style>
/* ══════════ 布局骨架 ══════════ */
.lg { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(420px, .85fr); min-height: 100vh; }

/* ══════════ 左侧：品牌区 ══════════ */
.lg__brand {
  position: relative; overflow: hidden;
  display: flex; flex-direction: column;
  padding: 56px 64px;
  background: linear-gradient(150deg,
      color-mix(in srgb, var(--brand-solid) 88%, #04120c) 0%,
      color-mix(in srgb, var(--brand-solid) 52%, #04120c) 45%,
      color-mix(in srgb, var(--brand-solid) 26%, #020a07) 100%);
  color: #fff;
}

/* 网格纹理 */
.lg__grid {
  position: absolute; inset: 0; pointer-events: none; opacity: .5;
  background-image:
    linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
  background-size: 54px 54px;
  mask-image: radial-gradient(85% 75% at 30% 25%, #000 40%, transparent 100%);
  -webkit-mask-image: radial-gradient(85% 75% at 30% 25%, #000 40%, transparent 100%);
}

/* 光晕球（缓慢浮动，营造"活着"的感觉） */
.lg__orb { position: absolute; border-radius: 50%; filter: blur(64px); pointer-events: none; }
.lg__orb--1 { width: 460px; height: 460px; top: -140px; right: -120px; background: rgba(255,255,255,.22); animation: float1 17s ease-in-out infinite; }
.lg__orb--2 { width: 340px; height: 340px; bottom: -110px; left: -80px;  background: rgba(255,255,255,.14); animation: float2 21s ease-in-out infinite; }
.lg__orb--3 { width: 240px; height: 240px; top: 44%; left: 52%; background: rgba(255,255,255,.10); animation: float1 25s ease-in-out infinite reverse; }
@keyframes float1 { 50% { transform: translate(-34px, 30px) scale(1.09); } }
@keyframes float2 { 50% { transform: translate(30px, -26px) scale(1.13); } }

.lg__inner { position: relative; z-index: 1; }

/* 品牌标识 */
.lg__logo-row { display: flex; align-items: center; gap: 14px; margin-bottom: 44px; }
.lg__logo-box {
  width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
  display: grid; place-items: center;
  background: rgba(255,255,255,.16);
  border: 1px solid rgba(255,255,255,.24);
  backdrop-filter: blur(10px);
  font-weight: 700; font-size: 17px; letter-spacing: -.5px;
}
.lg__logo-name { font-size: 19px; font-weight: 650; letter-spacing: -.2px; }
.lg__logo-desc { font-size: 12.5px; opacity: .7; margin-top: 1px; }

/* 主张 */
.lg__headline { font-size: 42px; font-weight: 700; line-height: 1.28; letter-spacing: -.025em; margin-bottom: 18px; }
.lg__headline em { font-style: normal; display: block; opacity: .92; font-weight: 500; font-size: 30px; margin-top: 6px; }
/* 深色底上不要用纯白，略降一点更柔和 */
.lg__headline { color: #fff; }
.lg__sub { font-size: 15px; line-height: 1.85; opacity: .8; max-width: 27em; }

/* 能力亮点 */
/* 主内容占据剩余空间并垂直居中，meta 贴底 —— 避免底部出现大片空白 */
.lg__inner--main { flex: 1; display: flex; flex-direction: column; justify-content: center; }
.lg__inner--meta { flex-shrink: 0; }
.lg__points { display: grid; gap: 13px; margin-top: 44px; }
.lg__point { display: flex; align-items: flex-start; gap: 11px; font-size: 14px; opacity: .9; }
.lg__point-ico {
  width: 21px; height: 21px; border-radius: 6px; flex-shrink: 0; margin-top: 1px;
  display: grid; place-items: center;
  background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.2);
}
.lg__point-ico svg { width: 12px; height: 12px; }

/* 底部元信息 */
.lg__meta { display: flex; align-items: center; gap: 20px; font-size: 12.5px; opacity: .58; }
.lg__meta span { display: flex; align-items: center; gap: 6px; }

/* ══════════ 右侧：表单区 ══════════ */
.lg__form-side {
  display: flex; flex-direction: column; justify-content: center;
  padding: 48px 56px;
  background: var(--bg-canvas);
}

.lg__form-box { width: 100%; max-width: 372px; margin: 0 auto; }

/* 窄屏时补一个品牌头（左侧品牌区被隐藏了） */
.lg__mobile-brand { display: none; align-items: center; gap: 12px; margin-bottom: 32px; }
@media (max-width: 960px) { .lg__mobile-brand { display: flex; } }
.lg__mobile-mark {
  width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
  display: grid; place-items: center; font-weight: 700; font-size: 16px;
  background: var(--brand-solid); color: var(--text-on-brand);
}

.lg__title { font-size: 27px; font-weight: 680; letter-spacing: -.025em; }
.lg__hint { font-size: 14px; color: var(--text-tertiary); margin-top: 7px; margin-bottom: 34px; }

.lg__field { margin-bottom: 19px; }
.lg__field label { display: block; font-size: 13.5px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px; }
.lg__iw { position: relative; }
.lg__iw > svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-tertiary); pointer-events: none; transition: color .15s; }
.lg__iw .am-input { padding-left: 40px; height: 46px; font-size: 14.5px; border-radius: var(--r-md); }
.lg__iw:focus-within > svg { color: var(--brand-solid); }

.lg__submit {
  width: 100%; height: 46px; margin-top: 10px;
  font-size: 15px; font-weight: 600; letter-spacing: .04em;
  border-radius: var(--r-md);
  box-shadow: 0 4px 14px color-mix(in srgb, var(--brand-solid) 34%, transparent);
  transition: transform .12s, box-shadow .2s, background .15s;
}
.lg__submit:hover { transform: translateY(-1px); box-shadow: 0 7px 20px color-mix(in srgb, var(--brand-solid) 42%, transparent); }
.lg__submit:active { transform: translateY(0); }

.lg__foot-note { margin-top: 34px; text-align: center; font-size: 12px; color: var(--text-tertiary); line-height: 1.9; }
.lg__foot-note kbd {
  font-family: var(--font-mono); font-size: 11px; padding: 1px 5px;
  border: 1px solid var(--border-default); border-radius: 4px; background: var(--bg-subtle);
}

/* 入场动画 */
@keyframes lgUp { from { opacity: 0; transform: translateY(14px); } }
.lg__inner > * { animation: lgUp .55s cubic-bezier(.16,1,.3,1) backwards; }
.lg__inner > *:nth-child(1) { animation-delay: .04s; }
.lg__inner > *:nth-child(2) { animation-delay: .1s; }
.lg__inner > *:nth-child(3) { animation-delay: .16s; }
.lg__form-box { animation: lgUp .5s .06s cubic-bezier(.16,1,.3,1) backwards; }
@media (prefers-reduced-motion: reduce) {
  .lg__inner > *, .lg__form-box, .lg__orb { animation: none !important; }
}

/* ══════════ 响应式 ══════════
 * 必须放在所有基础规则之后 —— 否则后面的 .lg__brand { display:flex }
 * 会反向覆盖 @media 里的 display:none（已踩过一次）。
 * 窄屏隐藏品牌区，改用表单顶部的紧凑品牌头。 */
@media (max-width: 960px) {
  .lg { grid-template-columns: 1fr; }
  .lg__brand { display: none; }
  .lg__form-side { padding: 40px 24px; }
  .lg__form-box { max-width: 100%; }
}
</style>
</head>
<body>
<div class="lg">

  {{-- ══════ 左：品牌区 ══════ --}}
  <section class="lg__brand" aria-hidden="true">
    <div class="lg__grid"></div>
    <div class="lg__orb lg__orb--1"></div>
    <div class="lg__orb lg__orb--2"></div>
    <div class="lg__orb lg__orb--3"></div>

    <div class="lg__inner lg__inner--main">
      <div class="lg__logo-row">
        <div class="lg__logo-box">AI</div>
        <div>
          <div class="lg__logo-name">{{ $brand }}</div>
          <div class="lg__logo-desc">AI 码农 · 后台开发框架</div>
        </div>
      </div>

      <h1 class="lg__headline">
        让 AI 独立完成
        <em>后台系统的开发</em>
      </h1>

      <p class="lg__sub">
        一份 PHP 声明，编译出接口、页面、文档与 AI 上下文。
        低歧义、可自省、可验证 —— 专为 AI Agent 设计的开发框架。
      </p>

      <div class="lg__points">
        <div class="lg__point">
          <span class="lg__point-ico">@include('aimanong::partials.icon', ['name' => 'check', 'size' => 12, 'stroke' => 3])</span>
          <span><b>声明式开发</b> —— 一个 Resource 类 = 一套完整后台</span>
        </div>
        <div class="lg__point">
          <span class="lg__point-ico">@include('aimanong::partials.icon', ['name' => 'check', 'size' => 12, 'stroke' => 3])</span>
          <span><b>单一数据源</b> —— 接口、类型、文档、AI 提示词同源</span>
        </div>
        <div class="lg__point">
          <span class="lg__point-ico">@include('aimanong::partials.icon', ['name' => 'check', 'size' => 12, 'stroke' => 3])</span>
          <span><b>可自省</b> —— MCP Server + 自省接口，AI 直接查询能力</span>
        </div>
        <div class="lg__point">
          <span class="lg__point-ico">@include('aimanong::partials.icon', ['name' => 'check', 'size' => 12, 'stroke' => 3])</span>
          <span><b>开箱即用</b> —— 登录 / 用户 / 角色 / 权限 / 菜单已内置</span>
        </div>
      </div>
    </div>

    <div class="lg__inner lg__inner--meta">
      <div class="lg__meta">
        <span>@include('aimanong::partials.icon', ['name' => 'layers', 'size' => 13]) v{{ \Aimanong\Aimanong::version() }}</span>
        <span>Laravel {{ app()->version() }}</span>
        <span>PHP {{ PHP_VERSION }}</span>
      </div>
    </div>
  </section>

  {{-- ══════ 右：表单区 ══════ --}}
  <main class="lg__form-side">
    <div class="lg__form-box">
      <div class="lg__mobile-brand">
        <div class="lg__mobile-mark">AI</div>
        <div>
          <div style="font-weight:650">{{ $brand }}</div>
          <div style="font-size:12.5px;color:var(--text-tertiary)">AI 码农 · 后台开发框架</div>
        </div>
      </div>

      <h2 class="lg__title">欢迎回来</h2>
      <p class="lg__hint">登录后进入控制台</p>

      @if($errors->any())
        <div class="am-alert am-alert--danger" style="margin-bottom:var(--sp-5)">
          @include('aimanong::partials.icon', ['name' => 'alert', 'size' => 16])
          <span>{{ $errors->first() }}</span>
        </div>
      @endif

      <form method="POST" action="{{ url(\Aimanong\Aimanong::url('auth/login')) }}">
        @csrf

        <div class="lg__field">
          <label for="username">用户名</label>
          <div class="lg__iw">
            @include('aimanong::partials.icon', ['name' => 'user', 'size' => 17])
            <input class="am-input" type="text" id="username" name="username"
                   value="{{ old('username') }}" required autofocus autocomplete="username"
                   placeholder="请输入用户名">
          </div>
        </div>

        <div class="lg__field">
          <label for="password">密码</label>
          <div class="lg__iw">
            @include('aimanong::partials.icon', ['name' => 'lock', 'size' => 17])
            <input class="am-input" type="password" id="password" name="password"
                   required autocomplete="current-password" placeholder="请输入密码">
          </div>
        </div>

        <button type="submit" class="am-btn am-btn--primary lg__submit">登 录</button>
      </form>

      <div class="lg__foot-note">
        按 <kbd>Enter</kbd> 直接登录 · 遇到问题请联系系统管理员
      </div>
    </div>
  </main>

</div>
</body>
</html>

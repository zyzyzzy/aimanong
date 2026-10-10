{{--
  顶栏：面包屑 + 用户区 + 主题配置入口。
--}}
@php
  $user = $user ?? \Aimanong\Aimanong::user();
  $label = $label ?? null;
  $current = $uri ?? '';
@endphp
<header class="am-topbar">
  <div class="am-topbar__left">
    {{--
      汉堡按钮 —— 窄屏唤出抽屉导航。
      ⚠️ 此前 CSS 里有 `.am-shell.is-nav-open` 的抽屉规则但**没有这个按钮**，
      整段响应式逻辑是死代码（实测发现）。补上按钮 + 脚本才真正可用。
    --}}
    <button type="button" class="am-menu-toggle" id="am-menu-toggle"
            aria-label="打开导航" title="打开导航">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>

    <div class="am-crumb">
      <a href="{{ url(\Aimanong\Aimanong::url()) }}">控制台</a>
      @if($label)
        <span class="am-crumb__sep">/</span>
        <span class="am-crumb__current">{{ $label }}</span>
      @endif
    </div>
  </div>

  <div class="am-topbar__right">
    <button type="button" class="am-btn am-btn--ghost am-btn--icon" data-theme-open title="界面配置">
      @include('aimanong::partials.icon', ['name' => 'palette', 'size' => 17])
    </button>

    @if($user)
      <span class="am-text-sm am-text-secondary">{{ $user->name ?? $user->username ?? '' }}</span>
      <form method="POST" action="{{ url(\Aimanong\Aimanong::url('auth/logout')) }}" style="display:inline">
        @csrf
        <button type="submit" class="am-btn am-btn--ghost am-btn--sm">
          @include('aimanong::partials.icon', ['name' => 'logout', 'size' => 15])
          退出
        </button>
      </form>
    @endif
  </div>
</header>

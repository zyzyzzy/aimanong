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

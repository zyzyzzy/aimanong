@extends('aimanong::partials.layout')

@php
  $title = '控制台';
@endphp

@section('content')
<div class="am-page-head">
  <div>
    <h1 class="am-page-head__title">你好，{{ $user->name ?? $user->username ?? '' }}</h1>
    <div class="am-page-head__desc">
      @if($isSuper)
        你是超级管理员，可以访问全部 {{ $resourceCount }} 个模块
      @elseif($permCount > 0)
        你当前有 {{ $permCount }} 项权限，可访问 {{ count($quickLinks) }} 个模块
      @else
        你还未被分配任何权限
      @endif
    </div>
  </div>
</div>

{{-- 未授权提示：这是新用户最需要看到的 --}}
@if(! $isSuper && $permCount === 0)
  <div class="am-alert am-alert--warning" style="margin-bottom:var(--sp-5)">
    @include('aimanong::partials.icon', ['name' => 'alert', 'size' => 17])
    <div>
      <div class="am-font-medium">你还没有任何权限</div>
      <div class="am-text-sm">请联系管理员在「角色」里为你分配权限后，左侧菜单才会出现对应模块。</div>
    </div>
  </div>
@endif

{{-- 统计卡 --}}
<div class="am-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:var(--sp-5)">
  <div class="am-stat am-stat--accent">
    <div class="am-stat__label">可访问模块</div>
    <div class="am-stat__value">{{ count($quickLinks) }}</div>
    <div class="am-stat__delta">按你的权限显示</div>
  </div>
  <div class="am-stat am-stat--success">
    <div class="am-stat__label">我的权限</div>
    <div class="am-stat__value">{{ $isSuper ? '全部' : $permCount }}</div>
    <div class="am-stat__delta">{{ $isSuper ? '超级管理员' : '已分配' }}</div>
  </div>
  <div class="am-stat">
    <div class="am-stat__label">已注册模块</div>
    <div class="am-stat__value">{{ $resourceCount }}</div>
    <div class="am-stat__delta">框架已加载</div>
  </div>
  <div class="am-stat am-stat--info">
    <div class="am-stat__label">后台账号</div>
    <div class="am-stat__value">{{ $userCount }}</div>
    <div class="am-stat__delta">可登录用户</div>
  </div>
</div>

{{-- 快捷入口 --}}
<div class="am-card">
  <div class="am-card__head">
    <span class="am-card__title">快捷入口</span>
    <span class="am-text-sm am-text-tertiary" style="margin-left:auto">按你的权限显示</span>
  </div>
  <div class="am-card__body">
    @if(count($quickLinks) === 0)
      <div class="am-empty">
        <div class="am-empty__icon">@include('aimanong::partials.icon', ['name' => 'inbox', 'size' => 22])</div>
        <div class="am-empty__title">暂无可用模块</div>
        <div class="am-empty__desc">等你被分配权限后，这里会列出可以访问的功能</div>
      </div>
    @else
      <div class="am-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--sp-3)">
        @foreach($quickLinks as $link)
          <a class="am-tile" href="{{ url(\Aimanong\Aimanong::url($link['uri'])) }}">
            <div class="am-tile__icon">
              @include('aimanong::partials.icon', ['name' => $link['icon'] ?: 'file', 'size' => 17])
            </div>
            <div class="am-tile__label">{{ $link['label'] }}</div>
          </a>
        @endforeach
      </div>
    @endif
  </div>
</div>

{{-- 当前身份 --}}
<div class="am-card">
  <div class="am-card__head"><span class="am-card__title">当前身份</span></div>
  <div class="am-card__body">
    <div class="am-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
      <div class="am-field" style="margin:0">
        <div class="am-field__label">账号</div>
        <div class="am-font-medium">{{ $user->username ?? '' }}</div>
      </div>
      <div class="am-field" style="margin:0">
        <div class="am-field__label">角色</div>
        <div>
          @forelse($roles as $role)
            <span class="am-badge am-badge--brand">{{ $role }}</span>
          @empty
            <span class="am-text-muted">（未分配角色）</span>
          @endforelse
        </div>
      </div>
      <div class="am-field" style="margin:0">
        <div class="am-field__label">权限</div>
        <div>
          @if($isSuper)
            <span class="am-badge am-badge--success">超级管理员 · 全部权限</span>
          @else
            <span class="am-badge {{ $permCount > 0 ? 'am-badge--info' : 'am-badge--warning' }}">{{ $permCount }} 项</span>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

{{-- 系统信息 --}}
<div class="am-card">
  <div class="am-card__head"><span class="am-card__title">系统信息</span></div>
  <div class="am-card__body">
    <div class="am-desc am-desc--3">
      <div class="am-desc__item">
        <div class="am-desc__label">框架</div>
        <div class="am-desc__value am-mono">{{ \Aimanong\Aimanong::brand() }} v{{ \Aimanong\Aimanong::version() }}</div>
      </div>
      <div class="am-desc__item">
        <div class="am-desc__label">Laravel</div>
        <div class="am-desc__value am-mono">{{ app()->version() }}</div>
      </div>
      <div class="am-desc__item">
        <div class="am-desc__label">PHP</div>
        <div class="am-desc__value am-mono">{{ PHP_VERSION }}</div>
      </div>
      <div class="am-desc__item">
        <div class="am-desc__label">权限控制</div>
        <div class="am-desc__value">
          <span class="am-badge {{ $rbacEnabled ? 'am-badge--success' : 'am-badge--muted' }}">
            {{ $rbacEnabled ? '已开启' : '已关闭' }}
          </span>
        </div>
      </div>
    </div>

    @if($isSuper)
      <div class="am-alert am-alert--info" style="margin-top:var(--sp-4)">
        @include('aimanong::partials.icon', ['name' => 'info', 'size' => 16])
        <div class="am-text-sm">
          AI Agent 请阅读项目根目录的 <code>llms.txt</code> 与 <code>AGENTS.md</code>，
          或调用 <code>php artisan mcp:start aimanong</code>。
        </div>
      </div>
    @endif
  </div>
</div>
@endsection

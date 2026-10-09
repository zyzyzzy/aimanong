{{--
  侧边栏导航。

  菜单来自 MenuRegistry（按当前用户权限过滤），已在控制器里算好。
  分组、图标、排序都是 Resource::menu() 声明的。
--}}
@php
  $menu = $menu ?? [];
  $current = $uri ?? '';
@endphp
<aside class="am-sidebar">
  <a class="am-sidebar__brand" href="{{ url(\Aimanong\Aimanong::url()) }}">
    <span class="am-sidebar__mark">AI</span>
    <span>
      <span class="am-font-semibold">{{ \Aimanong\Aimanong::brand() }}</span>
    </span>
  </a>

  <nav class="am-sidebar__nav">
    @foreach($menu as $item)
      @if(!empty($item['isGroup']))
        <div class="am-nav__group">{{ $item['label'] }}</div>
        @foreach($item['children'] as $child)
          <a class="am-nav__item {{ ($child['uri'] ?? '') === $current ? 'is-active' : '' }}"
             href="{{ url(\Aimanong\Aimanong::url($child['uri'] ?? '')) }}">
            @include('aimanong::partials.icon', ['name' => $child['icon'] ?? 'file', 'size' => 16])
            <span class="am-truncate">{{ $child['label'] }}</span>
          </a>
        @endforeach
      @else
        <a class="am-nav__item {{ ($item['uri'] ?? '') === $current ? 'is-active' : '' }}"
           href="{{ url(\Aimanong\Aimanong::url($item['uri'] ?? '')) }}">
          @include('aimanong::partials.icon', ['name' => $item['icon'] ?? 'file', 'size' => 16])
          <span class="am-truncate">{{ $item['label'] }}</span>
        </a>
      @endif
    @endforeach
  </nav>
</aside>

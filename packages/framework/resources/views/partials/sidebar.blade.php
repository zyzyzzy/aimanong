{{--
  侧边栏导航 —— 支持「整体缩窄」与「分组折叠」。

  ## 两个独立的状态
    1. `is-collapsed` —— 整条侧栏缩成图标条（64px），鼠标悬停临时展开
    2. 分组折叠 —— 每个分组可单独收起/展开

  ## 为什么用原生 JS 而不是 Vue
    侧边栏在所有页面共用（包括非 Vue 的首页），
    用独立的小脚本更简单，也避免和页面级 Vue 实例耦合。

  ## 状态持久化
    localStorage：刷新后保持用户的展开/收起选择。
    折叠状态用 class 写在 DOM 上，CSS 负责表现。
--}}
@php
  $menu = $menu ?? [];
  $current = $uri ?? '';
  // 当前页所属分组：默认展开，方便用户立刻看到自己在哪
  $activeGroup = null;
  foreach ($menu as $item) {
      if (! empty($item['isGroup'])) {
          foreach ($item['children'] as $child) {
              if (($child['uri'] ?? '') === $current) {
                  $activeGroup = $item['label'];
              }
          }
      }
  }
@endphp
<aside class="am-sidebar" id="am-sidebar">
  <div class="am-sidebar__brand">
    <a class="am-sidebar__brandlink" href="{{ url(\Aimanong\Aimanong::url()) }}">
      <span class="am-sidebar__mark">AI</span>
      <span class="am-sidebar__brandtext am-font-semibold">{{ \Aimanong\Aimanong::brand() }}</span>
    </a>
    {{-- 缩窄开关 --}}
    <button type="button" class="am-sidebar__toggle" id="am-nav-toggle"
            title="缩窄侧边栏" aria-label="缩窄侧边栏">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M15 6l-6 6 6 6"/>
      </svg>
    </button>
  </div>

  <nav class="am-sidebar__nav">
    @foreach($menu as $item)
      @if(!empty($item['isGroup']))
        @php
          /*
           * 分组 ID：不能用 Str::slug —— 它对纯中文返回空字符串，
           * 导致所有分组拿到同一个 id（"g-"），折叠状态互相串。
           * 用 md5 保证唯一且对中文安全。
           */
          $gid = 'g-'.substr(md5($item['label']), 0, 8);
          $isActive = $activeGroup === $item['label'];
        @endphp
        {{-- 分组：可点击折叠 --}}
        <div class="am-nav__groupwrap {{ $isActive ? 'is-open' : '' }}" data-group="{{ $gid }}">
          <button type="button" class="am-nav__group" aria-expanded="{{ $isActive ? 'true' : 'false' }}"
                  title="{{ $item['label'] }}">
            <span class="am-truncate am-nav__grouplabel">{{ $item['label'] }}</span>
            <svg class="am-nav__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <div class="am-nav__children">
            <div class="am-nav__inner">
              @foreach($item['children'] as $child)
                <a class="am-nav__item {{ ($child['uri'] ?? '') === $current ? 'is-active' : '' }}"
                   href="{{ url(\Aimanong\Aimanong::url($child['uri'] ?? '')) }}"
                   title="{{ $child['label'] }}">
                  @include('aimanong::partials.icon', ['name' => $child['icon'] ?? 'file', 'size' => 16])
                  <span class="am-truncate am-nav__label">{{ $child['label'] }}</span>
                </a>
              @endforeach
            </div>
          </div>
        </div>
      @else
        <a class="am-nav__item {{ ($item['uri'] ?? '') === $current ? 'is-active' : '' }}"
           href="{{ url(\Aimanong\Aimanong::url($item['uri'] ?? '')) }}"
           title="{{ $item['label'] }}">
          @include('aimanong::partials.icon', ['name' => $item['icon'] ?? 'file', 'size' => 16])
          <span class="am-truncate am-nav__label">{{ $item['label'] }}</span>
        </a>
      @endif
    @endforeach
  </nav>
</aside>

<style>
/* ══════════════════════════════════════════════════════════
   侧栏：缩窄 + 分组折叠
   ══════════════════════════════════════════════════════════ */

.am-sidebar__brand { display: flex; align-items: center; gap: var(--sp-2); }
.am-sidebar__brandlink {
  display: flex; align-items: center; gap: var(--sp-2);
  min-width: 0; color: inherit; text-decoration: none;
}
.am-sidebar__brandtext { min-width: 0; overflow: hidden; white-space: nowrap; }

.am-sidebar__toggle {
  margin-left: auto; flex-shrink: 0;
  width: 24px; height: 24px; border-radius: 6px;
  display: grid; place-items: center;
  border: none; background: transparent; cursor: pointer;
  color: var(--text-tertiary);
  transition: background .14s var(--ease), color .14s var(--ease), transform .22s var(--ease);
}
.am-sidebar__toggle:hover { background: var(--bg-hover); color: var(--text-primary); }
.am-sidebar__toggle svg { width: 14px; height: 14px; }

/* ── 分组折叠 ── */
.am-nav__groupwrap { margin-bottom: 2px; }
.am-nav__group {
  display: flex; align-items: center; gap: var(--sp-2); width: 100%;
  padding: var(--sp-2) var(--sp-3);
  border: none; background: transparent; cursor: pointer;
  font: inherit; font-size: var(--fs-xs); font-weight: var(--fw-semibold);
  color: var(--text-tertiary); text-transform: uppercase; letter-spacing: .07em;
  text-align: left; border-radius: var(--r-sm);
  transition: color .14s var(--ease), background .14s var(--ease);
}
.am-nav__group:hover { color: var(--text-secondary); background: var(--bg-hover); }
.am-nav__chev {
  width: 12px; height: 12px; margin-left: auto; flex-shrink: 0;
  transition: transform .22s var(--ease);
}
.am-nav__groupwrap.is-open .am-nav__chev { transform: rotate(180deg); }

/*
 * 折叠动画用 grid-template-rows: 0fr → 1fr。
 * 比 max-height 更好：不需要猜一个足够大的高度值，
 * 也不会因为内容变化而露馅（max-height 猜小了会裁切）。
 */
.am-nav__children {
  display: grid; grid-template-rows: 0fr;
  transition: grid-template-rows .22s var(--ease);
}
.am-nav__groupwrap.is-open .am-nav__children { grid-template-rows: 1fr; }
.am-nav__inner { overflow: hidden; min-height: 0; }

/* ── 缩窄态：图标条 64px ── */
.am-shell.is-collapsed { grid-template-columns: var(--w-sidebar-mini) minmax(0, 1fr); }
.am-shell.is-collapsed .am-sidebar { padding-inline: var(--sp-2); }
.am-shell.is-collapsed .am-sidebar__brandtext,
.am-shell.is-collapsed .am-nav__grouplabel,
.am-shell.is-collapsed .am-nav__chev,
.am-shell.is-collapsed .am-nav__label { display: none; }
.am-shell.is-collapsed .am-sidebar__brand {
  /* 缩窄时品牌行改为纵向：logo 在上、开关在下并居中。
     横排放不下（64px 减 padding 只剩 ~48px，logo 就占 34px）。 */
  flex-direction: column; gap: var(--sp-1); padding-inline: 0; justify-content: center;
}
.am-shell.is-collapsed .am-sidebar__toggle { transform: rotate(180deg); margin-left: 0; }
.am-shell.is-collapsed .am-nav__item { justify-content: center; padding-inline: 0; }
.am-shell.is-collapsed .am-nav__group { display: none; }
/* 缩窄时分组一律展开 —— 否则只剩图标、无从点开分组 */
.am-shell.is-collapsed .am-nav__children { grid-template-rows: 1fr; }

/* ── 缩窄 + 悬停：浮层临时展开全文 ──
 * 这样「收窄省空间」与「随时看文字」两者兼得，
 * 比"缩窄后永远只剩图标"更好用。 */
@media (min-width: 961px) {
  /*
   * 悬停临时展开必须是**浮层**，不能撑开 grid 列 ——
   * 否则会把主内容区挤走，用户视线里的表格会突然位移。
   * 做法：侧栏脱离文档流（absolute），主区由 grid 的第一列占位保持稳定。
   */
  .am-shell.is-collapsed { position: relative; }
  .am-shell.is-collapsed .am-sidebar {
    position: absolute; left: 0; top: 0; bottom: 0;
    width: var(--w-sidebar-mini);
    transition: width var(--dur-slow) var(--ease), box-shadow var(--dur) var(--ease);
  }
  .am-shell.is-collapsed .am-sidebar:hover {
    width: var(--w-sidebar);
    box-shadow: var(--shadow-lg);
    z-index: 80;
  }
  .am-shell.is-collapsed .am-sidebar:hover .am-sidebar__brandtext,
  .am-shell.is-collapsed .am-sidebar:hover .am-nav__grouplabel,
  .am-shell.is-collapsed .am-sidebar:hover .am-nav__chev,
  .am-shell.is-collapsed .am-sidebar:hover .am-nav__label { display: block; }
  .am-shell.is-collapsed .am-sidebar:hover .am-nav__chev { display: inline-block; }
  .am-shell.is-collapsed .am-sidebar:hover .am-sidebar__brand {
    flex-direction: row; justify-content: flex-start; padding-inline: var(--sp-3);
  }
  .am-shell.is-collapsed .am-sidebar:hover .am-sidebar__toggle { margin-left: auto; }
  .am-shell.is-collapsed .am-sidebar:hover .am-nav__item { justify-content: flex-start; padding-inline: var(--sp-3); }
}

/* ── 窄屏（抽屉模式）：不显示缩窄开关，分组正常显示 ── */
@media (max-width: 960px) {
  .am-sidebar__toggle { display: none; }
  .am-shell.is-collapsed { grid-template-columns: minmax(0, 1fr); }
  .am-shell.is-collapsed .am-sidebar { width: var(--w-sidebar); padding-inline: 0; }
  .am-shell.is-collapsed .am-sidebar__brandtext,
  .am-shell.is-collapsed .am-nav__grouplabel,
  .am-shell.is-collapsed .am-nav__label { display: block; }
  .am-shell.is-collapsed .am-nav__group { display: flex; }
  .am-shell.is-collapsed .am-nav__item { justify-content: flex-start; padding-inline: var(--sp-3); }
  .am-shell.is-collapsed .am-sidebar__brand { justify-content: flex-start; }
}
</style>

<script>
(function () {
  var KEY = 'aimanong.nav';
  var shell = document.querySelector('.am-shell');
  var side = document.getElementById('am-sidebar');
  var toggle = document.getElementById('am-nav-toggle');
  if (!shell || !side) return;

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; }
  }
  function write(o) {
    try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {}
  }

  var state = read();

  /* ── 1. 整体缩窄 ── */
  if (state.collapsed) shell.classList.add('is-collapsed');

  if (toggle) {
    toggle.addEventListener('click', function () {
      var on = shell.classList.toggle('is-collapsed');
      state.collapsed = on;
      write(state);
      toggle.setAttribute('title', on ? '展开侧边栏' : '缩窄侧边栏');
    });
  }

  /* ── 2. 分组折叠 ──
   * closed 记录「哪些分组被用户手动收起」，而不是记录展开的，
   * 这样新增分组时默认是展开的（更符合预期）。 */
  var groups = side.querySelectorAll('.am-nav__groupwrap');
  var closed = state.closed || {};

  groups.forEach(function (g) {
    if (closed[g.dataset.group]) {
      g.classList.remove('is-open');
      var b = g.querySelector('.am-nav__group');
      if (b) b.setAttribute('aria-expanded', 'false');
    }
  });

  groups.forEach(function (g) {
    var btn = g.querySelector('.am-nav__group');
    if (!btn) return;
    btn.addEventListener('click', function () {
      // 缩窄态下点分组：先展开侧栏，否则点了个看不见的东西
      if (shell.classList.contains('is-collapsed')) {
        shell.classList.remove('is-collapsed');
        state.collapsed = false;
      }
      var open = g.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      closed[g.dataset.group] = !open;
      state.closed = closed;
      write(state);
    });
  });
})();
</script>

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
      <img class="am-sidebar__mark" alt="{{ \Aimanong\Aimanong::brand() }}"
           src="{{ \Aimanong\Aimanong::asset()->url('logo.png') }}">
      {{-- 显示中文名「AI码农」—— LOGO 图片本身是拉丁文 "Ai manong"，
           文字用中文名互补，避免重复。 --}}
      <span class="am-sidebar__brandtext am-font-semibold">AI码农</span>
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
        @php
          // 缩窄态下分组只用图标表示，取该组第一个子项的图标作代表
          $groupIcon = 'folder';
          foreach ($item['children'] as $c) {
              if (! empty($c['icon'])) { $groupIcon = $c['icon']; break; }
          }
        @endphp
        <div class="am-nav__groupwrap {{ $isActive ? 'is-open' : '' }}" data-group="{{ $gid }}">
          <button type="button" class="am-nav__group" aria-expanded="{{ $isActive ? 'true' : 'false' }}"
                  title="{{ $item['label'] }}">
            <span class="am-nav__groupicon" aria-hidden="true">
              @include('aimanong::partials.icon', ['name' => $groupIcon, 'size' => 16])
            </span>
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

.am-sidebar__brand {
  display: flex; align-items: center; gap: var(--sp-2);
  position: relative;              /* 供开关 absolute 定位 */
  padding-right: 34px;             /* 给开关留位，避免文字压到它 */
}
/* 未缩窄：侧栏宽度固定，用 right 固定在右上角 */
.am-sidebar:not(.am-shell.is-collapsed *) .am-sidebar__toggle { }
.am-sidebar__brandlink {
  display: flex; align-items: center; gap: var(--sp-2);
  min-width: 0; color: inherit; text-decoration: none;
}
.am-sidebar__brandtext { min-width: 0; overflow: hidden; white-space: nowrap; }

/*
 * 缩窄开关。
 *
 * ⚠️ 必须用 absolute 固定在侧栏右上角，**不能靠 flex 排布** ——
 * 缩窄态悬停时侧栏会变宽，若开关参与 flex 布局就会跟着位移，
 * 导致「鼠标移上去想点它，它却跑了」（实测复现，用户反馈"点不到"）。
 * absolute + 固定 right 值 → 两种状态下坐标一致，稳定可点。
 */
/*
 * ⚠️ 开关的定位方式**必须两态不同**，这是一个踩了三次的坑：
 *
 * 【正常态（侧栏 196px）】用 `right`
 *   品牌行里 logo 在左、文字居中偏左，右侧是空的 → 放这里不挡任何东西。
 *   侧栏宽度固定，right 定位稳定。
 *
 * 【缩窄态（侧栏 64px）】用 `left`
 *   若仍用 right：悬停展开时侧栏变宽 → 按钮跟着右移 128px，
 *   鼠标追不上、点不到（实测复现）。
 *   改用 left 后按钮相对**左边缘**固定，而左边缘在两态都不变 → 稳定可点。
 *
 * 【正常态若误用 left】按钮会落在 logo 上被遮住 ——
 *   表现为「看不到 ‹」（用户反馈）。
 */
.am-sidebar__toggle {
  position: absolute; top: 14px; right: 10px; z-index: 5;
  width: 26px; height: 26px; border-radius: 6px;
  display: grid; place-items: center;
  border: none; background: transparent; cursor: pointer;
  color: var(--text-tertiary);
  transition: background .14s var(--ease), color .14s var(--ease), transform .22s var(--ease);
}
/* 悬停时给一个实底，避免和下面的元素视觉混淆 */
.am-sidebar__toggle:hover { background: var(--bg-hover); color: var(--text-primary); }
.am-sidebar__toggle svg { width: 14px; height: 14px; }

/*
 * 箭头方向随状态翻转：
 *   正常态（展开）→ ‹  提示"点击向左收窄"
 *   缩窄态        → ›  提示"点击向右展开"
 * 图标本身是 ‹（M15 6l-6 6 6 6），缩窄时旋转 180° 即变 ›。
 */
.am-shell.is-collapsed .am-sidebar__toggle svg { transform: rotate(180deg); }

/* ── 分组折叠 ── */
.am-nav__groupwrap { margin-bottom: 2px; }
/* 分组图标默认不显示 —— 展开态用文字标题即可；
   只有缩窄态才用图标代表一级菜单。 */
.am-nav__groupicon { display: none; place-items: center; flex-shrink: 0; }
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

/* ══════════════════════════════════════════════════════════
   缩窄态（仅桌面 ≥961px）
   ══════════════════════════════════════════════════════════

   ⚠️ 两个**踩过的严重坑**，改动前务必读：

   【坑 1】悬停展开**不能**把侧栏改成 position: absolute。
     侧栏脱离 grid 流后第一列空出，后面的 .am-main **被自动放进
     那个 64px 窄列** —— 主内容区塌成 64px（表格/顶栏/卡片全废）。
     ✅ 正确：侧栏保持 position: relative 留在流里；宽度 64px 正常显示，
        悬停加宽到 196px 时**溢出到主区上方**（overflow:visible + z-index）。
        主区布局不变，内容零位移。

   【坑 2】缩窄时**不能把子菜单全部强制展开、也不能隐藏分组标题**。
     用户反馈「缩窄后所有菜单都展开了、且没有一级菜单」——
     旧做法 `grid-template-rows: 1fr` + `.am-nav__group{display:none}` 正是如此。
     ✅ 正确：缩窄时**显示一级（分组图标）**、子项收起；
        悬停时展开全文并显示二级。 */

@media (min-width: 961px) {
  /* 缩窄 = grid 第一列收窄 */
  .am-shell.is-collapsed { grid-template-columns: var(--w-sidebar-mini) minmax(0, 1fr); }

  /*
   * ══════════════════════════════════════════════════════════
   * 缩窄态
   * ══════════════════════════════════════════════════════════
   *
   * ⚠️ 三个**踩过的坑**，改动前务必读：
   *
   * 【坑 1】悬停展开**不能**把侧栏改成 position: absolute。
   *   侧栏脱离 grid 流后第一列空出，.am-main **被自动放进那个 64px 窄列**
   *   —— 主内容区塌成 64px（表格/顶栏/卡片全废）。
   *   ✅ 侧栏保持 relative 留在流里，悬停加宽时溢出到主区上方。
   *
   * 【坑 2】缩窄时**不能**强制展开子菜单、也不能隐藏分组标题。
   *   ✅ 缩窄显示一级（分组图标）+ 子项收起；悬停才展开全文。
   *
   * 【坑 3】缩窄态下「悬停展开」与「点击开关」互相打架 ——
   *   鼠标一进入侧栏（就是为了点开关）就触发 :hover，侧栏从 64px
   *   瞬间变成 196px，**开关跟着右移，鼠标永远追不上**（实测复现）。
   *   ✅ 解决：**悬停展开只作用于内容区，不改变开关所在的位置**。
   *      具体做法：开关固定在侧栏右上角、缩窄/展开两态位置一致；
   *      悬停展开改为作用在 .am-sidebar__nav 上（不 displaced 开关）。
   *
   *   更稳的做法是把「开关」与「悬停区」解耦：
   *   悬停区只管导航，开关单独一个不随宽度变化的热区。
   *   这里用最简单的方案：**开关固定在侧栏顶部外侧**，两态同一位置。
   * ══════════════════════════════════════════════════════════ */

  .am-shell.is-collapsed .am-sidebar {
    position: relative;
    z-index: 60;
    width: var(--w-sidebar-mini);
    overflow: visible;
    padding-inline: var(--sp-1);
  }

  /*
   * ── 缩窄态的品牌行 ──
   *
   * 纵向排列：logo 在上、开关在下，两者**不重叠**。
   * 用 flex 正常排布（不要 absolute）—— absolute 会与 logo 抢空间，
   * 实测曾重叠 6px（用户反馈"顶部顶出去一小半"）。
   *
   * 开关的稳定性由「缩窄态下它始终在同一位置」保证：
   * 因为缩窄态侧栏宽度固定 64px，flex 居中的结果也是固定的。
   */
  /*
   * 缩窄态品牌行：纵向堆叠 —— logo 在上、开关在下，**两者都在行内**。
   *
   * 高度 = padding-top(14) + logo(28) + 间距(8) + 开关(26) + padding-bottom(10) = 86
   * 开关用 absolute 定位在 left:10 / top:50（= 14 + 28 + 8），
   * 这样它的坐标不随侧栏宽度变化 —— 悬停展开时不会跑，稳定可点。
   */
  .am-shell.is-collapsed .am-sidebar__brand {
    flex-direction: column; gap: 0;
    padding: 14px 0 10px;
    justify-content: flex-start; align-items: center;
    min-height: 86px;
  }
  .am-shell.is-collapsed .am-sidebar__brandlink { padding-top: 0; }
  /*
   * 缩窄态：品牌文字**完全隐藏**（含悬停展开时也不显示）。
   * 用户要求 —— 缩窄后只留 LOGO 图标，更干净。
   * 注意：这条规则不能被悬停态覆盖，否则 hover 时文字会突然冒出来。
   */
  .am-shell.is-collapsed .am-sidebar__brandtext { display: none !important; }
  /*
   * 缩窄态：开关放在**品牌行下方**（top: 56px），而不是左上角。
   *
   * ⚠️ 之前放左上角（top:14）会与 logo **重叠 16px** —— logo 也在顶部。
   * 用 left 定位（不用 right）：right 会随悬停展开把按钮推走 128px（实测）。
   * 用固定 top/left → 两态坐标一致，稳定可点。
   */
  .am-shell.is-collapsed .am-sidebar__toggle {
    /*
     * top = 品牌行 padding-top(14) + logo 高(28) + 间距(8) = 50
     * 水平**居中**（与上面的 LOGO 对齐）：
     *   left:50% + translateX(-50%) —— 无论侧栏/内边距怎么变都居中，
     *   比硬算 left 值稳健。
     * ⚠️ transform 在这里用于居中，箭头翻转改在 svg 上（互不干扰）。
     */
    left: 50%; right: auto; top: 50px; margin: 0;
    transform: translateX(-50%);
  }

  /* ── 缩窄且未悬停：只显示一级 ── */
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__children { grid-template-rows: 0fr; }
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__grouplabel,
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__chev,
  .am-shell.is-collapsed .am-sidebar:not(:hover) .am-nav__label { display: none; }

  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__groupicon { display: grid; }
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__group {
    justify-content: center; gap: 0; padding-inline: 0;
    height: 34px; border-radius: var(--r-sm); margin-bottom: 2px;
    text-transform: none; letter-spacing: 0;
  }
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__group > * { pointer-events: none; }
  .am-shell.is-collapsed .am-sidebar:not(:has(.am-sidebar__nav:hover)) .am-nav__item { justify-content: center; padding-inline: 0; }

  /*
   * ══════════════════════════════════════════════════════════
   * ── 悬停展开：**只感应导航区，不感应品牌行** ──
   * ══════════════════════════════════════════════════════════
   *
   * 为什么不用整条侧栏的 :hover？
   * 因为开关（‹）在品牌行里。鼠标移向开关时必然触发侧栏 :hover，
   * 侧栏一变宽开关就位移 —— 用户「看得到却点不到」。
   * 实测位移达 128-142px，鼠标根本追不上。
   *
   * 解决：把悬停感应绑在 **.am-sidebar__nav** 上（导航区），
   * 品牌行不参与。鼠标停在品牌行的开关上时侧栏不会展开，
   * 开关坐标恒定 → 稳定可点。
   *
   * 副作用：需要悬停导航区才能看到完整文字。这是可接受的 ——
   * 用户想点开关时不会误触发展开，想找菜单时把鼠标移到菜单区即可。
   */
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) {
    width: var(--w-sidebar);
    box-shadow: var(--shadow-lg);
  }
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) .am-sidebar__brand {
    flex-direction: row; justify-content: flex-start; align-items: center;
    padding: var(--sp-3) var(--sp-4) var(--sp-3) var(--sp-3); min-height: 0; gap: var(--sp-2);
  }
  /* 悬停展开态：开关回到品牌行右侧（此态品牌行是横排、宽度固定 196，稳定） */
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) .am-sidebar__toggle {
    left: auto; right: 10px; top: 14px; margin: 0;
    transform: none;
  }
  /* 展开后按钮保持在**距左边缘同一位置**（不跟随宽度变化），
     否则又会出现"鼠标追不上"。视觉上它落在品牌名右侧附近，
     因为 left 固定、品牌文字从 padding-left 开始，二者不重叠。 */
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) .am-nav__group {
    justify-content: flex-start; padding-inline: var(--sp-3);
    height: auto; text-transform: uppercase; letter-spacing: .07em;
  }
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) .am-nav__groupicon { display: none; }
  .am-shell.is-collapsed .am-sidebar:has(.am-sidebar__nav:hover) .am-nav__item {
    justify-content: flex-start; padding-inline: var(--sp-3);
  }
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

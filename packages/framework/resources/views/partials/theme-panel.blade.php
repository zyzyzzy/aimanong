{{--
  界面配置面板 —— 用户可实时调整外观。

  ## 配置项全部来自 ThemeConfig::options()（单一数据源）
    新增一个配置项只改 PHP，本文件会自动渲染出来。

  ## 双轨持久化
    - localStorage：即时生效、防闪屏（改完就变，不用等请求）
    - 服务端：跨设备同步（POST /api/ui/preferences）

  ## AI 提示
    不要在模板里硬编码配置项 —— 用 ThemeConfig::options() 循环。
--}}
@php
  use Aimanong\Ui\ThemeConfig;
  $ui = $ui ?? ThemeConfig::defaults();
  $allOptions = ThemeConfig::options();
@endphp

<aside class="am-themepanel" id="am-themepanel" hidden>
  <div class="am-themepanel__head">
    <span class="am-font-semibold">界面配置</span>
    <button type="button" class="am-btn am-btn--ghost am-btn--sm" data-theme-close>
      @include('aimanong::partials.icon', ['name' => 'x', 'size' => 15])
    </button>
  </div>

  <div class="am-themepanel__body">
    @foreach($allOptions as $key => $opt)
      <div class="am-themepanel__row">
        <div class="am-themepanel__label">
          {{ $opt['label'] }}
          @if(!empty($opt['help']))
            <div class="am-text-xs am-text-muted">{{ $opt['help'] }}</div>
          @endif
        </div>

        <div class="am-themepanel__ctrl">
          @switch($opt['type'])

            @case('style-picker')
              <div class="am-themepanel__styles">
                @foreach($opt['choices'] as $val => $choice)
                  <button type="button" class="am-stylecard {{ ($ui[$key] ?? '') === $val ? 'is-on' : '' }}"
                          data-pref="{{ $key }}" data-value="{{ $val }}">
                    <span class="am-stylecard__swatch" style="background:linear-gradient(135deg,{{ $choice['swatch'][0] }} 50%,{{ $choice['swatch'][1] }} 50%)"></span>
                    <span class="am-stylecard__name">{{ $choice['label'] }}</span>
                    <span class="am-stylecard__desc">{{ $choice['desc'] }}</span>
                  </button>
                @endforeach
              </div>
              @break

            @case('color')
              <div class="am-themepanel__color">
                <input type="color" value="{{ $ui[$key] ?: '#007643' }}" data-pref="{{ $key }}">
                <input type="text" class="am-input am-input--sm" value="{{ $ui[$key] }}"
                       placeholder="默认" data-pref-text="{{ $key }}" maxlength="7">
                <button type="button" class="am-btn am-btn--ghost am-btn--sm" data-pref-clear="{{ $key }}">重置</button>
              </div>
              @break

            @case('radio')
              <div class="am-segmented">
                @foreach($opt['choices'] as $val => $text)
                  <button type="button" data-pref="{{ $key }}" data-value="{{ $val }}"
                          @if(($ui[$key] ?? '') === $val) aria-pressed="true" @endif>{{ $text }}</button>
                @endforeach
              </div>
              @break

            @case('switch')
              <label class="am-switch">
                <input type="checkbox" data-pref-switch="{{ $key }}" @checked($ui[$key] ?? false)>
                <span></span>
              </label>
              @break
          @endswitch
        </div>
      </div>
    @endforeach
  </div>

  <div class="am-themepanel__foot">
    <button type="button" class="am-btn am-btn--primary am-btn--block" data-theme-save>保存配置</button>
    <div class="am-text-xs am-text-muted am-mt-2">保存在你的账号上，换设备也会同步</div>
  </div>
</aside>

<style>
.am-themepanel{position:fixed;top:0;right:0;bottom:0;width:320px;z-index:200;display:flex;flex-direction:column;
  background:var(--bg-surface);border-left:1px solid var(--border-default);box-shadow:-8px 0 32px rgba(16,24,40,.10);
  animation:amPanelIn .22s cubic-bezier(.16,1,.3,1)}
[hidden]{display:none!important}
@keyframes amPanelIn{from{transform:translateX(16px);opacity:0}}
.am-themepanel__head{display:flex;align-items:center;justify-content:space-between;
  padding:var(--sp-4) var(--sp-4);border-bottom:1px solid var(--border-default)}
.am-themepanel__body{flex:1;overflow-y:auto;padding:var(--sp-3) var(--sp-4)}
.am-themepanel__row{padding:var(--sp-3) 0;border-bottom:1px solid var(--border-subtle)}
.am-themepanel__row:last-child{border-bottom:0}
.am-themepanel__label{font-size:var(--fs-sm);font-weight:500;margin-bottom:var(--sp-2)}
.am-themepanel__ctrl{display:flex;flex-wrap:wrap;gap:var(--sp-2)}
.am-themepanel__styles{display:grid;grid-template-columns:repeat(3,1fr);gap:var(--sp-2);width:100%}
.am-stylecard{display:flex;flex-direction:column;align-items:center;gap:4px;padding:var(--sp-2);
  border:1px solid var(--border-default);border-radius:var(--r-md);background:var(--bg-surface);cursor:pointer;
  transition:all var(--dur) var(--ease);font:inherit}
.am-stylecard:hover{border-color:var(--brand-solid)}
.am-stylecard.is-on{border-color:var(--brand-solid);box-shadow:0 0 0 2px var(--brand-subtle-border)}
.am-stylecard__swatch{width:100%;height:26px;border-radius:var(--r-sm);border:1px solid var(--border-subtle)}
.am-stylecard__name{font-size:var(--fs-xs);font-weight:600}
.am-stylecard__desc{font-size:10px;color:var(--text-tertiary)}
.am-themepanel__color{display:flex;align-items:center;gap:var(--sp-2);width:100%}
.am-themepanel__color input[type=color]{width:38px;height:32px;padding:2px;border:1px solid var(--border-strong);
  border-radius:var(--r-sm);background:var(--bg-surface);cursor:pointer}
.am-input--sm{height:32px;font-size:var(--fs-xs)}
.am-segmented{display:inline-flex;border:1px solid var(--border-default);border-radius:var(--r-md);overflow:hidden;background:var(--bg-surface)}
.am-segmented button{border:0;background:none;font:inherit;font-size:var(--fs-xs);padding:0 12px;height:30px;
  cursor:pointer;color:var(--text-secondary);transition:all var(--dur-fast) var(--ease)}
.am-segmented button[aria-pressed="true"]{background:var(--brand-solid);color:var(--text-on-brand);font-weight:600}
.am-themepanel__foot{padding:var(--sp-4);border-top:1px solid var(--border-default)}
.am-mt-2{margin-top:var(--sp-2)}
</style>

<script>
(function () {
  var panel = document.getElementById('am-themepanel');
  if (!panel) return;
  var root = document.documentElement;
  var KEY = 'aimanong.ui';
  var saveUrl = @json(url(\Aimanong\Aimanong::url('api/ui/preferences')));
  var csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

  function read() { try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; } }
  function write(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }

  /** 把偏好应用到 <html>（即时预览，不等服务端） */
  function apply(s) {
    if (s.style) root.dataset.theme = s.style;
    if (s.dark) {
      root.dataset.mode = s.dark === 'auto'
        ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : s.dark;
      root.style.colorScheme = root.dataset.mode;
    }
    if (s.primary) {
      root.style.setProperty('--brand-solid', s.primary);
    } else {
      root.style.removeProperty('--brand-solid');
    }
    if (s.density === 'compact') root.dataset.density = 'compact'; else delete root.dataset.density;
    if (s.radius === 'sharp') root.dataset.radius = 'sharp';
    else if (s.radius === 'round') root.dataset.radius = 'round';
    else delete root.dataset.radius;
  }

  // 打开 / 关闭
  document.querySelectorAll('[data-theme-open]').forEach(function (b) {
    b.addEventListener('click', function () { panel.hidden = false; });
  });
  document.querySelectorAll('[data-theme-close]').forEach(function (b) {
    b.addEventListener('click', function () { panel.hidden = true; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') panel.hidden = true;
  });

  // 风格卡片
  panel.querySelectorAll('.am-stylecard').forEach(function (card) {
    card.addEventListener('click', function () {
      panel.querySelectorAll('.am-stylecard').forEach(function (c) { c.classList.remove('is-on'); });
      card.classList.add('is-on');
      var s = read(); s[card.dataset.pref] = card.dataset.value; write(s); apply(s);
    });
  });

  // 分段选择
  panel.querySelectorAll('.am-segmented button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.parentElement.querySelectorAll('button').forEach(function (x) { x.removeAttribute('aria-pressed'); });
      btn.setAttribute('aria-pressed', 'true');
      var s = read(); s[btn.dataset.pref] = btn.dataset.value; write(s); apply(s);
    });
  });

  // 开关
  panel.querySelectorAll('[data-pref-switch]').forEach(function (el) {
    el.addEventListener('change', function () {
      var s = read(); s[el.dataset.prefSwitch] = el.checked; write(s); apply(s);
    });
  });

  // 主题色
  panel.querySelectorAll('input[type=color][data-pref]').forEach(function (el) {
    el.addEventListener('input', function () {
      var s = read(); s[el.dataset.pref] = el.value; write(s); apply(s);
      var t = panel.querySelector('[data-pref-text="' + el.dataset.pref + '"]');
      if (t) t.value = el.value;
    });
  });
  panel.querySelectorAll('[data-pref-clear]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.dataset.prefClear;
      var s = read(); delete s[k]; write(s); apply(s);
      var t = panel.querySelector('[data-pref-text="' + k + '"]');
      if (t) t.value = '';
    });
  });

  // 保存到服务端
  var saveBtn = panel.querySelector('[data-theme-save]');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var s = read();
      saveBtn.disabled = true;
      saveBtn.textContent = '保存中…';
      fetch(saveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        credentials: 'same-origin',
        body: JSON.stringify(s)
      }).then(function (r) { return r.json(); }).then(function () {
        saveBtn.textContent = '已保存 ✓';
        setTimeout(function () { saveBtn.disabled = false; saveBtn.textContent = '保存配置'; }, 1600);
      }).catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = '保存失败，重试';
      });
    });
  }

  apply(read());
})();
</script>

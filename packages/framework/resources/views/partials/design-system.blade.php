{{--
  设计系统 —— 所有页面的唯一样式来源。

  ## 结构
    1. 三套风格的 token（data-theme）
    2. 用户可覆盖项（主题色 / 密度 / 圆角 / 深色）
    3. 基础层（reset / 排版 / 焦点 / 无障碍）
    4. 组件层（按钮 / 表格 / 表单 / 卡片 / 弹窗 / 标签 / 磁贴）

  ## 为什么内联
    保持零构建步骤。约 18KB，gzip 后约 6KB，可被浏览器缓存。

  ## AI 提示
    改样式只改本文件。所有颜色/间距/圆角都走 CSS 变量，
    不要写死色值 —— 否则切换风格时会失效。
--}}
@php
  use Aimanong\Ui\ThemeConfig;
  $ui = $ui ?? ThemeConfig::defaults();
@endphp
<style>
/* ══════════ 1. 风格 token ══════════ */

/* 青瓷：克制专业 */
[data-theme="celadon"] {
  --brand:#0d9488; --brand-hover:#0f766e; --brand-soft:#ccfbf1; --brand-text:#115e59;
  --bg:#f6f8f8; --surface:#fff; --surface-2:#f9fbfb; --sidebar-bg:#fff;
  --text:#0f1a1c; --text-2:#4a5c5e; --text-3:#8ba0a1; --text-4:#b8c7c8;
  --border:#e3ebeb; --border-2:#d3dede;
  --ok:#16a34a; --ok-soft:#dcfce7; --warn:#d97706; --warn-soft:#fef3c7;
  --danger:#dc2626; --danger-soft:#fee2e2; --info:#2563eb; --info-soft:#dbeafe;
  --shadow-1:0 1px 2px rgba(15,26,28,.04),0 1px 3px rgba(15,26,28,.06);
  --shadow-2:0 4px 12px rgba(15,26,28,.07),0 2px 4px rgba(15,26,28,.04);
  --shadow-3:0 12px 32px rgba(15,26,28,.12),0 4px 8px rgba(15,26,28,.06);
  --glow:none; --header-bg:rgba(255,255,255,.85); --blur:blur(12px); --row-hover:#f4f9f9;
}

/* 深空：科技炫酷 */
[data-theme="deepspace"] {
  --brand:#2dd4bf; --brand-hover:#5eead4; --brand-soft:#134e4a; --brand-text:#5eead4;
  --bg:#0a0f14; --surface:#111a21; --surface-2:#16212a; --sidebar-bg:#0d151b;
  --text:#e8f0f2; --text-2:#9fb3b8; --text-3:#6b8288; --text-4:#47595e;
  --border:#1e2d36; --border-2:#2a3d47;
  --ok:#4ade80; --ok-soft:#14351f; --warn:#fbbf24; --warn-soft:#3a2c0a;
  --danger:#f87171; --danger-soft:#3d1717; --info:#60a5fa; --info-soft:#12233d;
  --shadow-1:0 1px 2px rgba(0,0,0,.4); --shadow-2:0 4px 12px rgba(0,0,0,.5);
  --shadow-3:0 12px 32px rgba(0,0,0,.6);
  --glow:0 0 0 1px rgba(45,212,191,.15),0 0 24px rgba(45,212,191,.10);
  --header-bg:rgba(13,21,27,.8); --blur:blur(16px); --row-hover:#16212a;
}

/* 极光：现代活力 */
[data-theme="aurora"] {
  --brand:#7c3aed; --brand-hover:#8b5cf6; --brand-soft:#ede9fe; --brand-text:#5b21b6;
  --bg:#faf9ff; --surface:#fff; --surface-2:#faf9ff; --sidebar-bg:#fff;
  --text:#1a1526; --text-2:#57496e; --text-3:#9186a8; --text-4:#c4bcd4;
  --border:#ebe6f5; --border-2:#ddd5ee;
  --ok:#059669; --ok-soft:#d1fae5; --warn:#d97706; --warn-soft:#fef3c7;
  --danger:#e11d48; --danger-soft:#ffe4e6; --info:#0891b2; --info-soft:#cffafe;
  --shadow-1:0 1px 2px rgba(124,58,237,.06); --shadow-2:0 4px 16px rgba(124,58,237,.10);
  --shadow-3:0 16px 40px rgba(124,58,237,.18);
  --glow:0 0 0 1px rgba(124,58,237,.10),0 8px 32px rgba(124,58,237,.12);
  --header-bg:rgba(255,255,255,.75); --blur:blur(20px); --row-hover:#faf8ff;
}

/* ══════════ 2. 用户覆盖项 ══════════ */

/* 主题色覆盖（用户自选色） */
@if(!empty($ui['primary']))
:root { --brand:{{ $ui['primary'] }}; --brand-hover:{{ $ui['primary'] }}; }
@endif

/* 深色模式：显式选择，或跟随系统 */
@if(($ui['dark'] ?? 'auto') === 'dark')
:root { color-scheme:dark; }
@endif
@if(($ui['dark'] ?? 'auto') === 'auto')
@media (prefers-color-scheme: dark) {
  :root { color-scheme:dark; }
}
@endif
@if(($ui['dark'] ?? 'auto') === 'light')
:root { color-scheme:light; }
@endif

/* 圆角档位 */
@php $radiusScale = ['sharp'=>0.35,'normal'=>1,'round'=>1.6][$ui['radius'] ?? 'normal']; @endphp
:root {
  --radius-sm:calc(6px * {{ $radiusScale }});
  --radius:calc(10px * {{ $radiusScale }});
  --radius-lg:calc(14px * {{ $radiusScale }});
}

/* 间距（密度） */
:root { --gap:{{ ($ui['density'] ?? 'comfortable') === 'compact' ? '0.75' : '1' }}; }

/* ══════════ 3. 基础层 ══════════ */
*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans SC",sans-serif;
  background:var(--bg); color:var(--text);
  font-size:14px; line-height:1.7;
  -webkit-font-smoothing:antialiased;
  transition:background .25s ease,color .25s ease;
}
button,input,select,textarea{font:inherit;color:inherit}
a{color:inherit;text-decoration:none}
.num,td.num,.stat-num{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}
:focus-visible{outline:2px solid var(--brand);outline-offset:2px;border-radius:var(--radius-sm)}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}

/* ══════════ 4. 布局 ══════════ */
.app{display:flex;flex-direction:column;min-height:100vh}
.app-header{
  position:sticky;top:0;z-index:50;height:60px;flex-shrink:0;
  display:flex;align-items:center;gap:16px;padding:0 24px;
  background:var(--header-bg);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);
  border-bottom:1px solid var(--border);
  @if(($ui['layout'] ?? 'sidebar') === 'top') flex-wrap:wrap;height:auto;min-height:60px;padding:8px 24px; @endif
}
.brand{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px}
.brand-mark{
  width:28px;height:28px;border-radius:8px;display:grid;place-items:center;
  background:linear-gradient(135deg,var(--brand),var(--brand-hover));
  color:#fff;font-size:13px;font-weight:700;box-shadow:var(--glow);
}
.brand-sub{font-size:12px;color:var(--text-3);font-weight:400}
.header-right{margin-left:auto;display:flex;align-items:center;gap:12px}

.app-body{display:flex;flex:1;min-height:0}
@if(($ui['layout'] ?? 'sidebar') === 'top') .app-body{flex-direction:column} @endif

.sidebar{
  width:216px;flex-shrink:0;background:var(--sidebar-bg);
  border-right:1px solid var(--border);
  padding:16px 12px;overflow-y:auto;
  transition:width .2s ease;
}
@if(($ui['layout'] ?? 'sidebar') === 'top')
.sidebar{width:100%;border-right:none;border-bottom:1px solid var(--border);display:flex;gap:6px;padding:0 24px 8px;overflow-x:auto}
.sidebar .nav-group{display:flex;gap:6px;margin:0}
.sidebar .nav-group-title{display:none}
@endif

.nav-group{margin-bottom:20px}
.nav-group-title{padding:0 10px 8px;font-size:11px;font-weight:600;color:var(--text-4);letter-spacing:.8px;text-transform:uppercase}
.nav-item{
  display:flex;align-items:center;gap:10px;padding:9px 10px;
  border-radius:var(--radius-sm);font-size:13.5px;color:var(--text-2);
  cursor:pointer;transition:background .15s,color .15s;position:relative;
}
.nav-item:hover{background:var(--row-hover);color:var(--text)}
.nav-item.active{background:var(--brand-soft);color:var(--brand-text);font-weight:600;box-shadow:var(--glow)}
.nav-item svg{width:16px;height:16px;flex-shrink:0;opacity:.85}
.nav-item.active svg{opacity:1}
.nav-badge{margin-left:auto;font-size:11px;padding:1px 6px;border-radius:999px;background:var(--brand-soft);color:var(--brand-text)}

.main{flex:1;min-width:0;padding:calc(28px * var(--gap)) calc(32px * var(--gap))}

/* ══════════ 5. 组件 ══════════ */
.page-head{display:flex;align-items:flex-start;gap:16px;margin-bottom:24px}
.page-title{font-size:22px;font-weight:650;letter-spacing:-.3px}
.page-desc{font-size:13px;color:var(--text-3);margin-top:2px}
.page-actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}

.breadcrumb{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-3);margin-bottom:12px}
.breadcrumb a:hover{color:var(--brand)}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-1);overflow:hidden;transition:box-shadow .2s,border-color .2s}
.card+.card{margin-top:20px}
.card-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.card-title{font-size:15px;font-weight:600}
.card-body{padding:20px}

.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  height:36px;padding:0 14px;border-radius:var(--radius-sm);
  border:1px solid var(--border-2);background:var(--surface);color:var(--text-2);
  font-size:13.5px;font-weight:500;cursor:pointer;white-space:nowrap;
  transition:all .15s ease;
}
.btn:hover{border-color:var(--brand);color:var(--brand);background:var(--surface-2)}
.btn:active{transform:translateY(1px)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn svg{width:15px;height:15px}
.btn-primary{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:var(--shadow-1),var(--glow)}
.btn-primary:hover{background:var(--brand-hover);border-color:var(--brand-hover);color:#fff;box-shadow:var(--shadow-2),var(--glow)}
.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff}
.btn-danger:hover{background:var(--danger);border-color:var(--danger);color:#fff;filter:brightness(1.08)}
.btn-sm{height:30px;padding:0 10px;font-size:12.5px}
.btn-ghost{border-color:transparent;background:transparent}
.btn-ghost:hover{background:var(--row-hover)}
.btn-icon{width:36px;padding:0}
@if(($ui['density'] ?? 'comfortable') === 'compact')
.btn{height:32px;padding:0 11px;font-size:13px}
.btn-sm{height:27px;padding:0 8px;font-size:12px}
@endif

.input{width:100%;padding:9px 12px;border:1px solid var(--border-2);border-radius:var(--radius-sm);background:var(--surface);font-size:13.5px;transition:border-color .15s,box-shadow .15s}
.input::placeholder{color:var(--text-4)}
.input:hover{border-color:var(--text-4)}
.input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand) 15%,transparent)}
.input-error{border-color:var(--danger)}
.field{margin-bottom:18px}
.field-label{display:block;margin-bottom:7px;font-size:13px;font-weight:500;color:var(--text-2)}
.field-label .req{color:var(--danger);margin-left:2px}
.field-help{font-size:12px;color:var(--text-3);margin-top:5px}
.field-error{font-size:12px;color:var(--danger);margin-top:5px}

.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0}
thead th{
  position:sticky;top:0;z-index:1;background:var(--surface-2);
  padding:11px 16px;text-align:left;font-size:12px;font-weight:600;
  color:var(--text-3);letter-spacing:.3px;border-bottom:1px solid var(--border);white-space:nowrap;
}
thead th.num{text-align:right}
thead th.sortable{cursor:pointer;user-select:none}
thead th.sortable:hover{color:var(--brand)}
tbody td{padding:calc(13px * var(--gap)) 16px;font-size:13.5px;border-bottom:1px solid var(--border);vertical-align:middle}
tbody td.num{text-align:right}
tbody tr{transition:background .12s}
tbody tr:hover{background:var(--row-hover)}
tbody tr:last-child td{border-bottom:none}
.val-danger{color:var(--danger);font-weight:600}
.val-warning{color:var(--warn);font-weight:600}
.val-success{color:var(--ok);font-weight:600}

.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:500;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border)}
.tag-ok{background:var(--ok-soft);color:var(--ok);border-color:transparent}
.tag-warn{background:var(--warn-soft);color:var(--warn);border-color:transparent}
.tag-danger{background:var(--danger-soft);color:var(--danger);border-color:transparent}
.tag-info{background:var(--info-soft);color:var(--info);border-color:transparent}
.tag-brand{background:var(--brand-soft);color:var(--brand-text);border-color:transparent}
.dot{width:6px;height:6px;border-radius:50%;background:currentColor}

.modal-mask{position:fixed;inset:0;z-index:100;background:rgba(10,15,20,.45);backdrop-filter:blur(4px);display:grid;place-items:center;padding:24px;animation:fadeIn .18s ease}
@keyframes fadeIn{from{opacity:0}}
.modal{width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-3),var(--glow);animation:popIn .22s cubic-bezier(.16,1,.3,1)}
@keyframes popIn{from{opacity:0;transform:translateY(12px) scale(.98)}}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.modal-title{font-size:16px;font-weight:600}
.modal-body{padding:22px;overflow-y:auto;flex:1}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--surface-2)}
@if(($ui['animation'] ?? true) === false)
.modal-mask,.modal{animation:none}
@endif

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.stat{padding:18px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-1);position:relative;overflow:hidden}
.stat::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--brand);opacity:.8}
.stat-label{font-size:12.5px;color:var(--text-3)}
.stat-num{font-size:26px;font-weight:650;letter-spacing:-.5px;margin-top:4px}

.tile-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
.tile{padding:16px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);cursor:pointer;transition:all .18s cubic-bezier(.16,1,.3,1);box-shadow:var(--shadow-1)}
.tile:hover{border-color:var(--brand);box-shadow:var(--shadow-2),var(--glow);transform:translateY(-2px)}
.tile-icon{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;background:var(--brand-soft);color:var(--brand-text);margin-bottom:10px}
.tile-icon svg{width:17px;height:17px}
.tile-label{font-size:13.5px;font-weight:500}

.empty{padding:48px 24px;text-align:center;color:var(--text-3);font-size:13px}
.empty-icon{width:48px;height:48px;margin:0 auto 12px;border-radius:12px;display:grid;place-items:center;background:var(--surface-2);color:var(--text-4)}
.loading{padding:48px 24px;text-align:center;color:var(--text-3);font-size:13px}
.skeleton{background:linear-gradient(90deg,var(--surface-2) 25%,var(--border) 50%,var(--surface-2) 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:var(--radius-sm)}
@keyframes shimmer{to{background-position:-200% 0}}

.pagination{display:flex;gap:8px;align-items:center;padding:14px 16px;border-top:1px solid var(--border);font-size:13px}
.pagination .info{color:var(--text-3);margin-left:auto}

.note{padding:12px 16px;border-radius:var(--radius-sm);background:var(--info-soft);color:var(--info);font-size:13px}
.footer{padding:16px 24px;text-align:center;font-size:12px;color:var(--text-4);border-top:1px solid var(--border)}
.muted{color:var(--text-4)}

/* 极光风格：顶部流动光带 */
[data-theme="aurora"] .app-header{position:relative}
[data-theme="aurora"] .app-header::after{
  content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;
  background:linear-gradient(90deg,#7c3aed,#06b6d4,#ec4899,#7c3aed);
  background-size:300% 100%;animation:auroraShift 8s linear infinite;
}
@keyframes auroraShift{to{background-position:300% 0}}

/* 深空风格：网格纹理 */
[data-theme="deepspace"] body::before{
  content:'';position:fixed;inset:0;pointer-events:none;opacity:.35;z-index:0;
  background-image:linear-gradient(rgba(45,212,191,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(45,212,191,.03) 1px,transparent 1px);
  background-size:48px 48px;
}
</style>

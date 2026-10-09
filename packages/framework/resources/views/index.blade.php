<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 · AI 码农</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f7fa; color: #101B16;
        }
        .header {
            background: #fff; border-bottom: 1px solid #e8edea;
            padding: 0 24px; height: 56px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .header h1 { font-size: 17px; font-weight: 600; }
        .header .user { font-size: 13px; color: #6b746f; }
        .container { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
        /* 侧边栏（与 resource 页保持一致） */
        .layout { display: flex; min-height: calc(100vh - 56px); }
        .sidebar { width: 200px; flex-shrink: 0; background: #fff; border-right: 1px solid #e8edea; padding: 16px 0; overflow-y: auto; }
        .sidebar-main { flex: 1; min-width: 0; }
        .menu-group-title { padding: 14px 18px 6px; font-size: 11px; color: #a5aea9; letter-spacing: .5px; }
        .menu-item { display: flex; align-items: center; gap: 8px; padding: 9px 18px; font-size: 13px; color: #4a5550; text-decoration: none; }
        .menu-item:hover { background: #f4f7f5; }
        .menu-item.active { background: #eef4f1; color: #2e7d4f; font-weight: 600; border-right: 3px solid #6b9b7f; }
        .menu-icon { width: 16px; text-align: center; }
        /* 工作台卡片 */
        .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .tile { display: block; padding: 16px; background: #f8faf9; border-radius: 8px; text-decoration: none; color: #101B16; border: 1px solid transparent; }
        .tile:hover { border-color: #6b9b7f; background: #eef4f1; }
        .tile .t-icon { font-size: 20px; }
        .tile .t-label { font-size: 13px; margin-top: 6px; }
        .card {
            background: #fff; border-radius: 10px; padding: 24px;
            box-shadow: 0 2px 8px rgba(16, 27, 22, 0.05); margin-bottom: 20px;
        }
        .card h2 { font-size: 15px; margin-bottom: 14px; font-weight: 600; }
        .stats { display: flex; gap: 16px; flex-wrap: wrap; }
        .stat {
            flex: 1; min-width: 140px; background: #f8faf9;
            border-radius: 8px; padding: 16px; text-align: center;
        }
        .stat .num { font-size: 24px; font-weight: 600; color: #3FBF6F; }
        .stat .label { font-size: 12px; color: #8a948f; margin-top: 4px; }
        .milestone { display: flex; align-items: center; gap: 10px; padding: 7px 0; font-size: 13px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #dde3e0; }
        .dot.done { background: #3FBF6F; }
        .dot.active { background: #C98A4B; }
        code {
            background: #f0f3f1; padding: 2px 6px; border-radius: 4px;
            font-size: 12px; font-family: "SF Mono", Menlo, monospace;
        }
        .hint { font-size: 13px; color: #6b746f; line-height: 1.7; }
    </style>
</head>
<body>
    <div class="header">
        <div style="display:flex;align-items:center;gap:10px;">
            <img src="{{ \Aimanong\Aimanong::asset()->url('logo.png') }}" alt="Aimanong" style="height:30px;">
            <h1>Aimanong <span style="font-weight:400;color:#8a948f;font-size:13px;">AI 码农</span></h1>
        </div>
        <div class="user">
            {{ $user->name ?? $user->username }}
            <form method="POST" action="{{ url(\Aimanong\Aimanong::url('auth/logout')) }}" style="display:inline">
                @csrf
                <button type="submit" style="border:none;background:none;color:#8a948f;cursor:pointer;font-size:13px;">退出</button>
            </form>
        </div>
    </div>

    <div class="layout">
        @if(!empty($menu))
        <aside class="sidebar">
            @foreach($menu as $item)
                @if(!empty($item['isGroup']))
                    <div class="menu-group-title">{{ $item['label'] }}</div>
                    @foreach($item['children'] as $child)
                        <a class="menu-item" href="{{ url(\Aimanong\Aimanong::url($child['uri'] ?? '')) }}">
                            <span class="menu-icon">{{ $child['icon'] ?? '' }}</span>{{ $child['label'] }}
                        </a>
                    @endforeach
                @else
                    <a class="menu-item" href="{{ url(\Aimanong\Aimanong::url($item['uri'] ?? '')) }}">
                        <span class="menu-icon">{{ $item['icon'] ?? '' }}</span>{{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </aside>
        @endif

        <div class="sidebar-main">
    <div class="container">
        {{-- 快捷入口：按当前用户可见的菜单生成 --}}
        <div class="card">
            <h2>快捷入口</h2>
            <div class="tiles">
                @forelse($quickLinks as $link)
                    <a class="tile" href="{{ url(\Aimanong\Aimanong::url($link['uri'])) }}">
                        <div class="t-icon">{{ $link['icon'] ?: '📄' }}</div>
                        <div class="t-label">{{ $link['label'] }}</div>
                    </a>
                @empty
                    <div class="hint">你当前没有任何可访问的模块，请联系管理员分配权限。</div>
                @endforelse
            </div>
        </div>

        {{-- 当前身份：让用户清楚自己是谁、有什么权限 --}}
        <div class="card">
            <h2>当前身份</h2>
            <div class="stats">
                <div class="stat">
                    <div class="num" style="font-size:16px;">{{ $user->name ?? $user->username ?? '' }}</div>
                    <div class="label">{{ $user->username ?? '' }}</div>
                </div>
                <div class="stat">
                    <div class="num" style="font-size:16px;">{{ $roles === [] ? '（无角色）' : implode(' / ', $roles) }}</div>
                    <div class="label">角色</div>
                </div>
                <div class="stat">
                    <div class="num">{{ $isSuper ? '全部' : $permCount }}</div>
                    <div class="label">权限数{{ $isSuper ? '（超级管理员）' : '' }}</div>
                </div>
            </div>
            @if(! $isSuper && $permCount === 0)
                <div class="hint" style="margin-top:14px;color:#c0392b;">
                    ⚠️ 你还没有被分配任何权限，请联系管理员在「角色」里为你授权。
                </div>
            @endif
        </div>

        {{-- 系统信息 --}}
        <div class="card">
            <h2>系统信息</h2>
            <div class="stats">
                <div class="stat"><div class="num">{{ $resourceCount }}</div><div class="label">已注册模块</div></div>
                <div class="stat"><div class="num">{{ $userCount }}</div><div class="label">后台账号</div></div>
                <div class="stat"><div class="num">{{ $rbacEnabled ? '已开启' : '已关闭' }}</div><div class="label">权限控制</div></div>
            </div>
            <div class="hint" style="margin-top:14px;">
                框架 <code>Aimanong</code> v{{ \Aimanong\Aimanong::version() }} ·
                Laravel <code>{{ app()->version() }}</code> · PHP <code>{{ PHP_VERSION }}</code>
                @if($isSuper)
                    <br>AI Agent 请阅读项目根目录的 <code>llms.txt</code> 与 <code>AGENTS.md</code>，
                    或调用 <code>php artisan mcp:start aimanong</code>。
                @endif
            </div>
        </div>
    </div>
        </div>
    </div>
</body>
</html>

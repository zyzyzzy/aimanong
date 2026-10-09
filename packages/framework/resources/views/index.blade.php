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

    <div class="container">
        <div class="card">
            <h2>环境</h2>
            <div class="stats">
                <div class="stat"><div class="num">{{ app()->version() }}</div><div class="label">Laravel</div></div>
                <div class="stat"><div class="num">{{ PHP_VERSION }}</div><div class="label">PHP</div></div>
                <div class="stat"><div class="num">{{ $resourceCount }}</div><div class="label">已注册 Resource</div></div>
            </div>
        </div>

        <div class="card">
            <h2>里程碑</h2>
            <div class="milestone"><span class="dot done"></span>M0 地基 —— Laravel 12 骨架、admin guard、统一响应</div>
            <div class="milestone"><span class="dot done"></span>M1 Schema 编译层 —— 一份声明编译出四份产物</div>
            <div class="milestone"><span class="dot done"></span>M2 核心 DSL + Vue 端到端</div>
            <div class="milestone"><span class="dot done"></span>M3 AI 能力层 —— 自省 API + MCP Server</div>
            <div class="milestone"><span class="dot done"></span>M4 字段与组件库 —— 26 个字段类型 + 6 个展示器</div>
            <div class="milestone"><span class="dot done"></span>M5 增强 —— 导出 / 树形 / 分步表单 / 插件 / 多应用</div>
            <div class="milestone"><span class="dot done"></span>M6 生态与文档 —— 文档站 / Playground / 代码生成器</div>
        </div>

        <div class="card">
            <h2>下一步</h2>
            <p class="hint">
                框架主体（M0–M6）已完成，正在准备 v1.0 发布。<br><br>
                <strong>快速上手</strong>：从数据库表一键生成后台 ——
                <code>php artisan aimanong:make-resource {表名} --label=中文名 --register</code><br><br>
                AI Agent 请阅读项目根目录的 <code>llms.txt</code> 与 <code>AGENTS.md</code>，
                或调用 <code>php artisan mcp:start aimanong</code>。
            </p>
        </div>
    </div>
</body>
</html>

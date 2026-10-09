<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>登录 · AI 码农</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8f5e9 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(16, 27, 22, 0.08);
            padding: 40px;
            width: 100%;
            max-width: 380px;
        }
        .brand { text-align: center; margin-bottom: 28px; }
        .brand img { height: 72px; margin-bottom: 10px; }
        .brand h1 { font-size: 22px; color: #101B16; font-weight: 600; }
        .brand p { font-size: 13px; color: #8a948f; margin-top: 6px; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; font-size: 13px; color: #4a544e;
            margin-bottom: 6px; font-weight: 500;
        }
        .form-group input {
            width: 100%; padding: 10px 12px;
            border: 1px solid #dde3e0; border-radius: 6px;
            font-size: 14px; transition: border-color .2s;
        }
        .form-group input:focus {
            outline: none; border-color: #3FBF6F;
        }
        .btn {
            width: 100%; padding: 11px;
            background: #3FBF6F; color: #fff;
            border: none; border-radius: 6px;
            font-size: 15px; font-weight: 500; cursor: pointer;
            transition: background .2s;
        }
        .btn:hover { background: #35a75f; }
        .error {
            background: #fdecea; color: #c0392b;
            padding: 10px 12px; border-radius: 6px;
            font-size: 13px; margin-bottom: 16px;
        }
        .footer {
            text-align: center; margin-top: 20px;
            font-size: 12px; color: #a5aea9;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <img src="{{ \Aimanong\Aimanong::asset()->url('logo.png') }}" alt="Aimanong">
            <h1>Aimanong</h1>
            <p>AI 码农 · 后台开发框架</p>
        </div>

        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ url(\Aimanong\Aimanong::url('auth/login')) }}">
            @csrf
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn">登 录</button>
        </form>

        <div class="footer">Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}</div>
    </div>
</body>
</html>

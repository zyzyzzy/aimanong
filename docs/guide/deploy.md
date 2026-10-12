# 生产部署

> 本文面向**把 Aimanong 部署到真实服务器**的场景。
>
> 本地开发不需要这些 —— `php artisan serve` 就够了。

---

## 一、前置要求

| 项 | 要求 |
|---|---|
| PHP | 8.2+（8.3 更好），需 `pdo` / `mbstring` / `json` / `openssl` / `fileinfo` |
| Web 服务器 | Nginx（推荐）或 Apache |
| 数据库 | MySQL 8 / PostgreSQL 14 / SQLite（小规模可用） |
| 扩展 | `php-fpm` |

> ⚠️ 后台**不能**用 `php artisan serve` 对外提供服务 ——
> 那是单进程开发服务器，并发稍高就会阻塞。

---

## 二、部署步骤

### 1. 拉代码并装依赖

```bash
git clone <你的仓库> /var/www/my-admin
cd /var/www/my-admin

composer install --no-dev --optimize-autoloader
```

`--no-dev` 排除 PHPUnit / PHPStan 等开发依赖，
`--optimize-autoloader` 生成类映射，生产环境更快。

### 2. 配置环境变量

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env`，**生产环境必须改的项**：

```ini
APP_ENV=production
APP_DEBUG=false          # ← 千万别留 true，报错页会泄漏路径与配置
APP_URL=https://admin.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=my_admin
DB_USERNAME=my_admin
DB_PASSWORD=换成强密码

# 后台路径前缀（默认 admin；改掉能减少被扫描的概率）
AIMANONG_PREFIX=manage

# RBAC：生产建议开启
AIMANONG_RBAC=true

# 登录限流（默认已开，一般不用改）
AIMANONG_LOGIN_THROTTLE=true
AIMANONG_LOGIN_THROTTLE_ACCOUNT=5

# AI 自省接口：**生产环境务必关闭**
# 它会把你的 Resource 结构暴露出去，且默认无鉴权
AIMANONG_AI_ENABLE=false
```

> ⚠️ **`AIMANONG_AI_ENABLE` 必须显式设为 `false`。**
> 它的默认逻辑是「本地环境或 APP_DEBUG 时开启」——
> 只要你没关掉 `APP_DEBUG`，自省接口就是公开的。

### 3. 建库并迁移

```bash
# 先建好空库，或让 MySQL 用户有建库权限
php artisan migrate --force
```

`--force` 跳过「生产环境确认」的交互提示（自动化部署需要）。

### 4. 创建管理员账号

**如果这是首次部署**，`migrate` 之后还没有任何账号：

```bash
php artisan aimanong:install --force \
  --admin-user=admin \
  --admin-password='换成强密码' \
  --no-interaction
```

> 也可以交互式跑 `php artisan aimanong:install`，
> 密码回车会生成 16 位随机强密码并打印。

### 5. 生成缓存（生产必做）

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**这三条能显著提速**（省掉每次请求的配置解析与路由注册）。

> ✅ **本框架已兼容这三条命令。**
>
> 历史情况供参考：v1.7.1 之前跑完 `config:cache` 或 `route:cache`
> 会导致**整个后台 500**（`Auth driver [admin] is not defined` /
> `Target class [admin.admin] does not exist`）。原因是多应用模式的
> guard 与中间件组是**运行时注册**的，而缓存会跳过那段代码。
> 已于 v1.8.0 修复并有 e2e 断言锁定。
>
> 如果你用的是更早的版本，**请先升级**，或暂时不跑这两条命令。

### 6. 目录权限

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

PHP-FPM 的运行用户（通常是 `www-data`）必须能写这两个目录。

---

## 三、Nginx 配置

```nginx
server {
    listen 80;
    server_name admin.example.com;
    # 生产环境请配 HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name admin.example.com;

    ssl_certificate     /etc/letsencrypt/live/admin.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/admin.example.com/privkey.pem;

    # 后台根目录是 public/，不是项目根目录
    root /var/www/my-admin/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20m;   # 要能上传文件，按需调整

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;   # 导出大表时需要
    }

    # 别让外部访问到这些
    location ~ /\.(?!well-known).* { deny all; }
    location ~ ^/(storage/app|bootstrap/cache)/ { deny all; }

    access_log /var/log/nginx/my-admin.access.log;
    error_log  /var/log/nginx/my-admin.error.log;
}
```

---

## 四、文件上传

默认配置**不需要 `storage:link`**：

```ini
AIMANONG_UPLOAD_DISK=public
AIMANONG_UPLOAD_SERVE=auto    # 本地磁盘走框架读取路由
```

`auto` 模式下，上传的文件由框架的路由 `/admin/file/{path}` 提供 ——
好处是不依赖软链接，也不会因为 `public/storage` 没建而 404。

三种模式：

| 值 | 行为 | 适用 |
|---|---|---|
| `auto`（默认） | 本地磁盘走框架路由 | 大多数情况 |
| `route` | 强制走框架路由 | 无法建软链接的环境 |
| `url` | 直接返回磁盘 URL | 用 S3 / OSS 等对象存储 |

**用云存储时**：

```ini
AIMANONG_UPLOAD_DISK=s3
AIMANONG_UPLOAD_SERVE=url
# 再按 Laravel 文档配置 AWS_* 变量
```

---

## 五、安全清单

部署完请逐项确认：

- [ ] `APP_DEBUG=false`
- [ ] `AIMANONG_AI_ENABLE=false`（自省接口会暴露 Resource 结构）
- [ ] `APP_KEY` 已生成（`key:generate`）
- [ ] 数据库密码是强密码，且**不是 root**
- [ ] 已配 HTTPS
- [ ] 登录限流开启（默认开）：`AIMANONG_LOGIN_THROTTLE=true`
- [ ] 后台前缀不是默认 `admin`（可选，减少扫描）
- [ ] 服务器防火墙只开放 80/443
- [ ] 定期备份数据库（见下节）

### 登录限流

默认**每 IP 每分钟 20 次**、**每 IP 对每账号每分钟 5 次**。

超限后返回 422 + 「尝试过于频繁，请 N 秒后重试」。

被误封时（比如整个办公室共用一个出口 IP）：

```bash
php artisan cache:clear   # 清掉计数
```

调松：`AIMANONG_LOGIN_THROTTLE_IP=100`；关掉：`AIMANONG_LOGIN_THROTTLE=false`。

> ⚠️ 不建议完全关闭。没有限流时，暴露公网的后台可被无限次爆破密码。

---

## 六、升级框架

```bash
cd /var/www/my-admin

# 1. 先备份（数据库 + .env）
# 2. 拉新版本
composer update aimanong/framework --with-dependencies

# 3. 跑迁移（框架可能新增表/字段）
php artisan migrate --force

# 4. 重新生成缓存 —— 必须做，否则跑的还是旧配置
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 5. 权限节点可能新增
php artisan aimanong:permission sync
```

> **升级前务必看 [CHANGELOG](https://github.com/zyzyzzy/aimanong/blob/main/CHANGELOG.md)** ——
> 破坏性变更会单独标注。

### 回滚

```bash
composer require aimanong/framework:1.7.0   # 指定回退到的版本
php artisan migrate:rollback --step=1        # 如果新版本加了迁移
php artisan optimize:clear
```

---

## 七、备份

**至少要备份两样**：

```bash
# 1. 数据库
mysqldump -u my_admin -p my_admin > backup-$(date +%F).sql

# 2. 上传的文件（如果存本地）
tar czf uploads-$(date +%F).tar.gz storage/app/public

# 3. 还有 .env（含 APP_KEY，丢了所有加密数据都解不开）
cp .env .env.backup-$(date +%F)
```

写成 crontab：

```cron
0 3 * * * cd /var/www/my-admin && mysqldump -u my_admin -p'密码' my_admin | gzip > /backup/db-$(date +\%F).sql.gz
```

### 自动清理登录日志（可选）

登录日志会一直增长。配置保留天数：

```ini
AIMANONG_LOGIN_LOG_KEEP_DAYS=90
```

配合调度任务（Laravel 11+ 在 `routes/console.php` 或 `bootstrap/app.php` 注册）：

```php
Schedule::command('model:prune')->daily();
```

---

## 八、常见问题

### 页面白屏 / 500，但看不到错误

```bash
tail -50 storage/logs/laravel.log
```

生产环境 `APP_DEBUG=false` 时浏览器不显示详情，日志里才有。

临时排查可把 `APP_DEBUG` 改回 `true`，**排查完立刻改回来**。

### 改了 .env 但不生效

配置被缓存了：

```bash
php artisan config:clear
php artisan config:cache
```

### 上传的图片 404

检查 `AIMANONG_UPLOAD_SERVE`：
- `auto` / `route` → 应该走框架路由，确认 `AIMANONG_UPLOAD=true`
- `url` → 确认磁盘配置与 `APP_URL` 正确

### 登录总提示「尝试过于频繁」

见上文「登录限流」。共用出口 IP 的公司网络容易触发，可调高
`AIMANONG_LOGIN_THROTTLE_IP`。

### 忘记管理员密码

```bash
php artisan tinker
```

```php
$u = Aimanong\Models\Administrator::where('username', 'admin')->first();
$u->password = '新密码';
$u->save();
exit;
```

### 忘记后台路径前缀

看 `.env` 里的 `AIMANONG_PREFIX`，或：

```bash
php artisan tinker --execute="echo config('aimanong.route.prefix');"
```

---

## 九、检查部署是否成功

```bash
php artisan ai:verify
```

输出 `✅ 全部通过` 说明声明层没问题。

然后浏览器打开 `https://你的域名/{前缀}`，用管理员账号登录，
确认列表页能正常打开、能新增一条数据。

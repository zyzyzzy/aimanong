<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Auth;

use Aimanong\Contracts\Permissible;
use Aimanong\ThinkPHP\Application\ThinkApplication;
use Aimanong\ThinkPHP\Models\Administrator;
use think\facade\Config;
use think\facade\Cookie;
use think\facade\Session;

/**
 * 会话守卫 —— `Aimanong\ThinkPHP\NullRuntime` 里那个 `NullGuard` 的真正实现。
 *
 * ## 范围（如实说明）
 *
 * 已实现：登录 / 登出 / 取当前用户 / 记住我（基于 remember_token）。
 * **未实现**：登录限流、多应用 guard 切换、密码重置。
 *
 * 没做的部分**不假装有**：不存在的方法就是不存在，
 * 调用会得到一个明确的错误，而不是「静默放行」。
 */
final class ThinkGuard
{
    public function __construct(
        private readonly string $sessionKey = 'aimanong_admin_id',
        private readonly ?string $model = null,
    ) {}

    protected function modelClass(): string
    {
        /*
         * 多应用：当前后台用**自己的**用户模型。
         *
         * 优先级：应用配置 > 全局 auth.model > 构造参数 > Administrator。
         *
         * ⚠️ 少了这一层，`/agent` 后台会拿 `Administrator` 去查 id ——
         * 要么查不到（表现为「登录了但立刻掉线」），
         * 要么查到同 id 的另一个人（**越权**）。
         */
        $apps = app(ThinkApplication::class);

        if ($apps->enabled()) {
            $perApp = $apps->model($apps->current());

            if ($perApp !== '' && $perApp !== \stdClass::class && class_exists($perApp)) {
                return $perApp;
            }
        }

        /** @var mixed $configured */
        $configured = Config::get('aimanong.auth.model');

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            /** @var class-string<Administrator> $configured */
            return $configured;
        }

        if ($this->model !== null && class_exists($this->model)) {
            return $this->model;
        }

        return Administrator::class;
    }

    /**
     * 当前登录用户。
     *
     * ⚠️ 返回类型是 `object`，**不是** `Administrator`。
     *
     * 多应用下每个后台有自己的用户模型，硬判 `Administrator` 会把
     * 其它后台的用户当成「未登录」（Laravel 侧同源 bug：登录了但菜单全空）。
     * 判定改为基于**能力**（core 的 `Contracts\Permissible`）。
     *
     * 返回 `object` 而不是 `Permissible`，是因为调用方（登录流程）还需要
     * 模型自身的 `save()` / `getData()` —— 契约只约定权限判定那三个方法。
     * 但**返回的一定是 Permissible**（下面显式判过），权限判定方可直接当契约用。
     */
    public function user(): ?object
    {
        try {
            $id = Session::get($this->sessionKey);

            if ($id !== null && $id !== '') {
                $model = $this->modelClass();
                $user = $model::find($id);

                if ($user instanceof Permissible && $user->isEnabled()) {
                    return $user;
                }

                // 用户被禁用 / 已删除 → 立刻清掉会话，不要留着一个失效的登录态
                $this->logout();
            }

            return $this->viaRememberToken();
        } catch (\Throwable) {
            return null;
        }
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * 校验账号密码；成功则写入会话。
     */
    public function attempt(string $username, string $password, bool $remember = false): bool
    {
        $model = $this->modelClass();
        $user = $model::where('username', $username)->find();

        /*
         * ⚠️ 判 `Permissible`，不是判 `Administrator`。
         *
         * 多应用下每个后台有自己的用户模型；硬判具体类会让
         * `/agent` 的用户永远登不进来（实测：agent 登录 401）。
         */
        if (! $user instanceof Permissible) {
            /*
             * 用户不存在时也走一次哈希校验（对着一个固定串），
             * 让「用户不存在」与「密码错误」的耗时接近 ——
             * 否则响应时间差就是一个账号枚举漏洞。
             */
            password_verify($password, '$2y$10$usesomesillystringfoeahashcompareslow');

            return false;
        }

        // 被禁用的账号即使密码正确也不放行 —— 而且要给出与密码错误**不同**
        // 的提示，否则管理员查半天不知道是被停用了。
        if (! $user->isEnabled()) {
            throw new \RuntimeException('账号已被禁用，请联系管理员。');
        }

        /*
         * 密码校验**直接读属性**，不依赖模型自带的 `verifyPassword()`。
         *
         * 原因：那不在 `Permissible` 契约里 —— 要求每个后台的用户模型
         * 都实现它，等于给「接入多应用」加了一道隐藏门槛（实测踩到：
         * 代理商的模型没这个方法是登录 401 的原因）。
         *
         * 模型**有**该方法时仍优先用它（允许自定义，如多算法兼容）。
         */
        $ok = method_exists($user, 'verifyPassword')
            ? (bool) $user->verifyPassword($password)
            : password_verify($password, (string) $user->getAttr('password'));

        if (! $ok) {
            return false;
        }

        $this->login($user, $remember);

        return true;
    }

    /**
     * 写入登录态。
     *
     * 返回 `object`（而非具体类）—— 多应用下用户模型不唯一；
     * 这里只用它的主键与「记住我」相关属性。
     */
    public function login(object $user, bool $remember = false): void
    {
        Session::set($this->sessionKey, $user->getKey());

        /*
         * ⚠️ 会话固定（session fixation）防护 —— 登录成功后**必须**换一个 session id。
         *
         * 不换的话：攻击者先让受害者带着一个已知的 session id 访问站点，
         * 受害者登录后那个 id 仍然是有效的 —— 攻击者直接用它就是登录态。
         *
         * 参数 `true` = 同时销毁旧 id 上的数据（等价于 Laravel 的
         * `session()->migrate(true)`）。当前请求的数据在内存里，会写到新 id 下。
         */
        Session::regenerate(true);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $user->save(['remember_token' => hash('sha256', $token)]);
            Cookie::set('aimanong_remember', $user->getKey().'|'.$token, 86400 * 30);
        }
    }

    public function logout(): void
    {
        // 彻底销毁（清数据 + 换 id），而不是只删一个 key ——
        // 只删 key 会留下一个仍然有效的会话，登出形同虚设
        Session::destroy();
        Cookie::delete('aimanong_remember');
    }

    /**
     * 记住我：cookie 里是「id|明文 token」，库里存的是它的 sha256。
     *
     * 只存哈希，等于库被读走也换不到登录态。
     */
    protected function viaRememberToken(): ?Administrator
    {
        $raw = (string) Cookie::get('aimanong_remember', '');

        if ($raw === '' || ! str_contains($raw, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $raw, 2);
        $model = $this->modelClass();
        $user = $model::find($id);

        if (! $user instanceof Administrator || ! (bool) $user->getAttr('enabled')) {
            return null;
        }

        $stored = (string) $user->getAttr('remember_token');

        if ($stored === '' || ! hash_equals($stored, hash('sha256', $token))) {
            return null;
        }

        Session::set($this->sessionKey, $user->getKey());

        return $user;
    }
}

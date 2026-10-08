<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * 管理员 Guard。
 *
 * GuardHelpers trait 已提供：
 *   $user, $provider, check(), guest(), id(), hasUser(), setUser(),
 *   getProvider(), setProvider(), authenticate(), forgetUser()
 * 因此本类只实现接口要求的 user() 与 validate()，
 * 并额外提供 login() / logout() / attempt()。
 *
 * 注意：不可重复声明 trait 已定义的属性，否则 PHP fatal。
 */
class AdminGuard implements Guard
{
    use GuardHelpers;

    /**
     * 是否已登出（trait 未提供，需自行声明）。
     */
    protected bool $loggedOut = false;

    protected Session $session;

    protected Request $request;

    protected string $name;

    public function __construct(
        string $name,
        UserProvider $provider,
        Session $session,
        Request $request,
    ) {
        $this->name = $name;
        $this->provider = $provider;
        $this->session = $session;
        $this->request = $request;
    }

    public function user(): ?AuthenticatableContract
    {
        if ($this->loggedOut) {
            return null;
        }

        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->session->get($this->sessionName());

        if ($id === null) {
            return null;
        }

        $this->user = $this->provider->retrieveById($id);

        return $this->user;
    }

    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    /**
     * 登录。签名唯一，不做重载。
     */
    public function login(AuthenticatableContract $user, bool $remember = false): void
    {
        $this->session->put($this->sessionName(), $user->getAuthIdentifier());
        $this->session->migrate(true);

        $this->setUser($user);
        $this->loggedOut = false;
    }

    public function logout(): void
    {
        $this->session->remove($this->sessionName());

        $this->user = null;
        $this->loggedOut = true;
    }

    /**
     * 通过凭证尝试登录。
     *
     * @param  array<string, mixed>  $credentials
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null || ! $this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        // Laravel 11+ 契约：登录成功后按需重哈希密码
        $this->provider->rehashPasswordIfRequired($user, $credentials);

        $this->login($user, $remember);

        return true;
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function sessionName(): string
    {
        return 'aimanong_login_'.$this->name;
    }
}

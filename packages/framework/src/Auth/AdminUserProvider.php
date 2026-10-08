<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Database\Eloquent\Model;

/**
 * 管理员用户提供者。
 *
 * 必须实现 Laravel 11+ 新增的契约方法：
 *   - rehashPasswordIfRequired()：登录时按需重新哈希密码
 * 否则会 fatal（接口方法未实现）。
 */
class AdminUserProvider implements UserProvider
{
    protected string $model;

    public function __construct(
        protected HasherContract $hasher,
        string $model,
    ) {
        $this->model = $model;
    }

    public function retrieveById($identifier): ?AuthenticatableContract
    {
        /** @var class-string<Model> $model */
        $model = $this->model;

        return $model::query()->find($identifier);
    }

    public function retrieveByToken($identifier, $token): ?AuthenticatableContract
    {
        $user = $this->retrieveById($identifier);

        if (! $user) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $user : null;
    }

    public function updateRememberToken(AuthenticatableContract $user, $token): void
    {
        $user->setRememberToken($token);

        if ($user instanceof Model) {
            $user->save();
        }
    }

    public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
    {
        if (($credentials['password'] ?? null) === null
            || ($credentials['password'] ?? null) === '') {
            return null;
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (str_contains($key, 'password')) {
                continue;
            }

            if (is_array($value) || $value instanceof \Closure) {
                continue;
            }

            $query->where($key, $value);
        }

        return $query->first();
    }

    public function validateCredentials(AuthenticatableContract $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? '';

        return $this->hasher->check($plain, $user->getAuthPassword());
    }

    /**
     * Laravel 11+ 契约新增方法：登录时按需重新哈希密码。
     *
     * 若不开此方法，Laravel 12 会在认证流程中因接口未满足而报错。
     * 默认关闭（config: aimanong.auth.rehash_on_login），
     * 因为 Laravel 11+ 骨架不再包含 hashing.php，需自行约定默认值。
     */
    public function rehashPasswordIfRequired(AuthenticatableContract $user, array $credentials, bool $force = false): void
    {
        if (! config('aimanong.auth.rehash_on_login', false) && ! $force) {
            return;
        }

        $plain = $credentials['password'] ?? null;

        if ($plain === null || $plain === '') {
            return;
        }

        if (! $this->hasher->needsRehash($user->getAuthPassword())) {
            return;
        }

        $this->updatePassword($user, $plain);
    }

    protected function updatePassword(AuthenticatableContract $user, string $plain): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $passwordName = method_exists($user, 'getAuthPasswordName')
            ? $user->getAuthPasswordName()
            : 'password';

        $user->setAttribute($passwordName, $this->hasher->make($plain));
        $user->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    protected function newModelQuery()
    {
        /** @var class-string<Model> $model */
        $model = $this->model;

        return $model::query();
    }
}

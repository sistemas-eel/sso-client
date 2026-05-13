<?php

namespace PortalSistemas\SSOClient\Tests\Fakes;

use Illuminate\Contracts\Auth\Authenticatable;

class FakeUser implements Authenticatable
{
    /** @var array<string, self> */
    public static array $usersByCodpes = [];

    public string $codpes = '';
    public string $name = '';
    public string $email = '';
    public string $authPassword = '';

    /** @var array<int, string> */
    public array $permissions = ['local:keep'];

    public static function resetStore(): void
    {
        static::$usersByCodpes = [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreate(array $attributes, array $values): self
    {
        $codpes = (string) ($attributes['codpes'] ?? '');
        if (! isset(static::$usersByCodpes[$codpes])) {
            $user = new self();
            $user->codpes = $codpes;
            static::$usersByCodpes[$codpes] = $user;
        }

        $user = static::$usersByCodpes[$codpes];
        $user->name = (string) ($values['name'] ?? $user->name);
        $user->email = (string) ($values['email'] ?? $user->email);

        return $user;
    }

    public function givePermissionTo(string $permission): void
    {
        if (! in_array($permission, $this->permissions, true)) {
            $this->permissions[] = $permission;
        }
    }

    public function revokePermissionTo(string $permission): void
    {
        $this->permissions = array_values(array_filter(
            $this->permissions,
            static fn ($existing) => $existing !== $permission
        ));
    }

    public function getAuthIdentifierName()
    {
        return 'codpes';
    }

    public function getAuthIdentifier()
    {
        return $this->codpes;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return $this->authPassword;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }
}

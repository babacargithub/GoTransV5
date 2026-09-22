<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PermissionName;
use Database\Factories\UserFactory;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;

    const ROLE_HIERARCHY = [
        'ROLE_AMBASSADOR' => ['ROLE_USER'],
        'ROLE_API' => ['ROLE_USER'],
        'ROLE_EMPLOYEE' => ['ROLE_USER', 'ROLE_AMBASSADOR'],
        'ROLE_ADMIN' => ['ROLE_EMPLOYEE'],
        'ROLE_SENIOR_STAFF' => ['ROLE_ADMIN'],
        'ROLE_MANAGER' => ['ROLE_SENIOR_STAFF'],
        'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
        'ROLE_CEO' => ['ROLE_MANAGER', 'ROLE_SUPER_ADMIN'],
        'ROLE_OWNER' => ['ROLE_CEO'],
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    public static function requireMobileAppUser(): User
    {
        return User::where('username', 'appli_mobile')->firstOrFail();
    }

    /**
     * @throws Exception
     */
    public static function requiredLoggedInUser(): User
    {
        return auth()->user() ?? throw new InvalidArgumentException('User not logged in');
    }

    /**
     * Whether this user holds the super-admin "full access" permission.
     *
     * A `Gate::before` hook grants every ability to users for whom this is true,
     * so a `full-access` holder passes every permission check without each guard
     * having to name the permission explicitly.
     */
    public function hasFullAccess(): bool
    {
        try {
            return $this->hasPermissionTo(PermissionName::FullAccess->value);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Expand a list of roles to include every parent role in the hierarchy.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    public static function resolveRoles(array $roles): array
    {
        $allRoles = [];

        foreach ($roles as $role) {
            $allRoles = array_merge($allRoles, self::expandRole($role));
        }

        return array_values(array_unique($allRoles));
    }

    /**
     * @return array<int, string>
     */
    private static function expandRole(string $role): array
    {
        $allRoles = [$role];

        foreach (self::ROLE_HIERARCHY[$role] ?? [] as $parentRole) {
            $allRoles = array_merge($allRoles, self::expandRole($parentRole));
        }

        return $allRoles;
    }
}

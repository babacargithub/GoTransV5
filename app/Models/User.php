<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
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

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasProfilePhoto;
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

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
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
        return auth()->user() ?? throw new InvalidArgumentException("User not logged in");
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

    public static function resolveRoles($role): array
    {
        function resolveRoles($role): array
        {
            $hierarchy = User::ROLE_HIERARCHY;
            $allRoles = [$role];

            if (isset($hierarchy[$role])) {
                foreach ($hierarchy[$role] as $parentRole) {
                    $allRoles = array_merge($allRoles, resolveRoles($parentRole, $hierarchy));
                }
            }

            return array_unique($allRoles);
        }

// Example usage:
        $userRoles = $role;
        $allUserRoles = [];

        foreach ($userRoles as $userRole) {
            $allUserRoles = array_merge($allUserRoles, resolveRoles($userRole));
        }

        return $allUserRoles;

    }
}

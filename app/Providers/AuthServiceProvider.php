<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Super-admin: a user holding the `full-access` permission passes every
        // ability check, so guards can check a single specific permission with an
        // implicit "OR full-access".
        Gate::before(function (User $user): ?bool {
            return $user->hasFullAccess() ? true : null;
        });
    }
}

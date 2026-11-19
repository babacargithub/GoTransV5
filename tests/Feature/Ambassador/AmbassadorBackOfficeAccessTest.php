<?php

namespace Tests\Feature\Ambassador;

use App\Http\Middleware\RejectAmbassadorAccounts;
use App\Models\Ambassador;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class AmbassadorBackOfficeAccessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Fortify's own account routes (logout, password, two-factor) stay open: ambassadors need them.
     */
    public function test_every_authenticated_route_outside_the_ambassador_space_rejects_ambassador_accounts(): void
    {
        $unguardedRouteUris = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => collect($route->gatherMiddleware())
                ->contains(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'auth')))
            ->reject(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'ambassador.'))
            ->reject(fn (Route $route): bool => $route->uri() === 'logout' || str_starts_with($route->uri(), 'user/'))
            ->reject(fn (Route $route): bool => in_array(RejectAmbassadorAccounts::class, $route->gatherMiddleware(), true))
            ->map(fn (Route $route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unguardedRouteUris, 'These authenticated routes do not reject ambassador accounts.');
    }

    public function test_an_ambassador_gets_a_403_on_the_back_office(): void
    {
        $ambassadorUser = User::factory()->create();
        $ambassadorUser->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        $this->actingAs($ambassadorUser)
            ->get(route('back-office.departs.index'))
            ->assertForbidden();
    }
}

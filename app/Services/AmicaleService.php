<?php

namespace App\Services;

use App\Http\Middleware\CachePublicHtmlResponse;
use App\Models\Amicale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Back-office management of amicales and the resolution of the amicale a website host belongs to.
 * Callers are responsible for checking permissions before invoking a mutation.
 */
class AmicaleService
{
    /**
     * The active amicale whose subdomain of the public website is the given host, if any.
     */
    public function findActiveAmicaleByHost(string $host): ?Amicale
    {
        $publicWebsiteDomain = config('app.public_website_domain');
        $subdomainSuffix = '.'.$publicWebsiteDomain;

        if (! Str::endsWith($host, $subdomainSuffix)) {
            return null;
        }

        $slug = Str::beforeLast($host, $subdomainSuffix);

        return Amicale::query()->where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * Create an amicale together with the user account its managers log in with.
     *
     * @param  array{name: string, slug: string, welcome_message: ?string, commission_per_ticket: int}  $amicaleAttributes
     * @param  array{username: string, password: string}  $accountCredentials
     * @param  array<int, int>  $servedTrajetIds  empty = the amicale's site offers every trajet
     */
    public function createAmicaleWithAdminAccount(array $amicaleAttributes, array $accountCredentials, array $servedTrajetIds = []): Amicale
    {
        return DB::transaction(function () use ($amicaleAttributes, $accountCredentials, $servedTrajetIds): Amicale {
            $username = mb_strtolower($accountCredentials['username']);

            // The users table requires a unique email, which amicales are not asked for.
            $userAccount = (new User)->forceFill([
                'name' => $amicaleAttributes['name'],
                'username' => $username,
                'email' => $username.'@users.invalid',
                'password' => $accountCredentials['password'],
                'active' => true,
            ]);
            $userAccount->save();
            $userAccount->assignRole(Amicale::ACCOUNT_ROLE_NAME);

            $amicale = Amicale::create([
                ...$amicaleAttributes,
                'slug' => Str::lower($amicaleAttributes['slug']),
                'is_active' => true,
                'user_account_id' => $userAccount->id,
            ]);
            $this->syncServedTrajets($amicale, $servedTrajetIds);

            return $amicale;
        });
    }

    /**
     * Choose the trajets the amicale's site offers (none = all of them) and drop its cached public pages.
     *
     * @param  array<int, int>  $servedTrajetIds
     */
    public function syncServedTrajets(Amicale $amicale, array $servedTrajetIds): void
    {
        $amicale->trajets()->sync($servedTrajetIds);

        CachePublicHtmlResponse::flushAll();
    }

    public function setActive(Amicale $amicale, bool $isActive): void
    {
        $amicale->update(['is_active' => $isActive]);
    }
}

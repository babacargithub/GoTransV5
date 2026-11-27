<?php

namespace Tests\Feature\Amicale;

use App\Models\Amicale;
use App\Models\Depart;
use App\Models\Trajet;
use App\Services\AmicaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AmicaleWebsiteTest extends TestCase
{
    use DatabaseTransactions;

    private function amicaleSiteUrl(string $slug, string $path = '/'): string
    {
        return 'http://'.$slug.'.'.config('app.public_website_domain').$path;
    }

    public function test_an_active_amicale_site_shows_its_welcome_message(): void
    {
        $amicale = Amicale::factory()->create(['slug' => 'amicale-lettres', 'name' => 'Amicale des Lettres']);

        $this->get($this->amicaleSiteUrl('amicale-lettres'))
            ->assertOk()
            ->assertSee('Amicale des Lettres en partenariat avec Globe One Transports');
    }

    public function test_the_welcome_message_chosen_by_the_amicale_replaces_the_default_one(): void
    {
        Amicale::factory()->create(['slug' => 'amicale-droit', 'welcome_message' => 'Bienvenue aux juristes !']);

        $this->get($this->amicaleSiteUrl('amicale-droit'))
            ->assertOk()
            ->assertSee('Bienvenue aux juristes !');
    }

    public function test_links_on_an_amicale_site_stay_on_the_amicale_subdomain(): void
    {
        Amicale::factory()->create(['slug' => 'amicale-sciences']);

        $this->get($this->amicaleSiteUrl('amicale-sciences'))
            ->assertOk()
            ->assertSee($this->amicaleSiteUrl('amicale-sciences', '/yobante'), false);
    }

    public function test_an_unknown_subdomain_answers_404(): void
    {
        $this->get($this->amicaleSiteUrl('inconnue'))->assertNotFound();
    }

    public function test_a_deactivated_amicale_site_answers_404(): void
    {
        Amicale::factory()->inactive()->create(['slug' => 'amicale-eteinte']);

        $this->get($this->amicaleSiteUrl('amicale-eteinte'))->assertNotFound();
    }

    public function test_the_public_domain_shows_no_amicale_message(): void
    {
        $this->get('http://'.config('app.public_website_domain').'/')
            ->assertOk()
            ->assertDontSee('en partenariat avec Globe One Transports');
    }

    public function test_an_amicale_site_is_kept_out_of_search_engines(): void
    {
        Amicale::factory()->create(['slug' => 'amicale-seo']);

        $this->get($this->amicaleSiteUrl('amicale-seo'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    public function test_the_service_creates_an_amicale_with_an_account_carrying_the_amicale_role(): void
    {
        $amicale = app(AmicaleService::class)->createAmicaleWithAdminAccount(
            ['name' => 'Amicale Test', 'slug' => 'Amicale-Test', 'welcome_message' => null, 'commission_per_ticket' => 250],
            ['username' => 'AmicaleTest', 'password' => 'secret-password'],
        );

        $this->assertSame('amicale-test', $amicale->slug);
        $this->assertTrue($amicale->userAccount->hasRole(Amicale::ACCOUNT_ROLE_NAME));
        $this->assertSame('amicaletest', $amicale->userAccount->username);
    }

    private function createTrajet(string $publicName): Trajet
    {
        return Trajet::create(['name' => $publicName.uniqid(), 'public_name' => $publicName, 'departure_city' => 'DAKAR', 'arrival_city' => 'MBOUR']);
    }

    public function test_an_amicale_restricted_to_some_trajets_only_lists_those(): void
    {
        $servedTrajet = $this->createTrajet('Trajet-Servi-Unique');
        $hiddenTrajet = $this->createTrajet('Trajet-Cache-Unique');
        $amicale = Amicale::factory()->create(['slug' => 'amicale-filtree']);
        $amicale->trajets()->attach($servedTrajet->id);

        $this->get($this->amicaleSiteUrl('amicale-filtree'))
            ->assertOk()
            ->assertSee('Trajet-Servi-Unique')
            ->assertDontSee('Trajet-Cache-Unique');

        $this->get('http://'.config('app.public_website_domain').'/')
            ->assertSee('Trajet-Servi-Unique')
            ->assertSee('Trajet-Cache-Unique');
    }

    public function test_an_amicale_without_chosen_trajets_lists_all_of_them(): void
    {
        $this->createTrajet('Trajet-Libre-Unique');
        Amicale::factory()->create(['slug' => 'amicale-libre']);

        $this->get($this->amicaleSiteUrl('amicale-libre'))->assertSee('Trajet-Libre-Unique');
    }

    public function test_the_page_of_a_trajet_the_amicale_does_not_serve_answers_404(): void
    {
        $servedTrajet = $this->createTrajet('Servi');
        $hiddenTrajet = $this->createTrajet('Cache');
        Amicale::factory()->create(['slug' => 'amicale-404'])->trajets()->attach($servedTrajet->id);

        $this->get($this->amicaleSiteUrl('amicale-404', '/caravanes/'.$servedTrajet->slug))->assertOk();
        $this->get($this->amicaleSiteUrl('amicale-404', '/caravanes/'.$hiddenTrajet->slug))->assertNotFound();
    }

    public function test_booking_a_depart_of_a_trajet_the_amicale_does_not_serve_answers_404(): void
    {
        $servedTrajet = $this->createTrajet('Servi');
        $hiddenTrajet = $this->createTrajet('Cache');
        $hiddenDepart = Depart::create(['name' => 'DEPART CACHE', 'date' => now()->addDays(3), 'trajet_id' => $hiddenTrajet->id, 'closed' => false, 'locked' => false, 'canceled' => false]);
        Amicale::factory()->create(['slug' => 'amicale-resa'])->trajets()->attach($servedTrajet->id);

        $this->get($this->amicaleSiteUrl('amicale-resa', '/reserver/'.$hiddenDepart->id))->assertNotFound();
    }
}

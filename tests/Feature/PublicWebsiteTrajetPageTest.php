<?php

namespace Tests\Feature;

use App\Models\Depart;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicWebsiteTrajetPageTest extends TestCase
{
    use DatabaseTransactions;

    private function websiteUrl(string $path = '/'): string
    {
        return 'http://'.config('app.public_website_domain').$path;
    }

    private function createTrajet(array $overrides = []): Trajet
    {
        return Trajet::create(array_merge([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ], $overrides));
    }

    public function test_the_home_page_lists_trajets_linking_to_their_caravane_page(): void
    {
        $trajet = $this->createTrajet();

        $response = $this->get($this->websiteUrl('/'));

        $response->assertStatus(200);
        $response->assertViewIs('website.home');
        $response->assertSee($trajet->name);
        $response->assertSee($this->websiteUrl('/caravanes/'.$trajet->slug), false);
    }

    public function test_the_home_page_hides_disabled_trajets(): void
    {
        $visibleTrajet = $this->createTrajet(['name' => 'Caravane visible '.uniqid()]);
        $disabledTrajet = $this->createTrajet(['name' => 'Caravane cachée '.uniqid(), 'disabled' => true]);

        $response = $this->get($this->websiteUrl('/'));

        $response->assertStatus(200);
        $response->assertSee($visibleTrajet->name);
        $response->assertDontSee($disabledTrajet->name);
    }

    public function test_the_home_page_orders_trajets_by_display_position(): void
    {
        $second = $this->createTrajet(['name' => 'AAA Caravane '.uniqid(), 'display_position' => 2]);
        $first = $this->createTrajet(['name' => 'ZZZ Caravane '.uniqid(), 'display_position' => 1]);

        $response = $this->get($this->websiteUrl('/'));

        $response->assertSeeInOrder([$first->name, $second->name]);
    }

    public function test_the_home_page_shows_the_ugb_alias_for_a_saint_louis_city(): void
    {
        $trajet = $this->createTrajet([
            'name' => 'Caravane alias '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);

        $response = $this->get($this->websiteUrl('/'));

        $response->assertSee('UGB');
        $response->assertDontSee('SAINT-LOUIS');
        // The stored value is untouched.
        $this->assertSame('SAINT-LOUIS', $trajet->fresh()->arrival_city);
    }

    public function test_trajet_city_label_maps_saint_louis_to_ugb_and_leaves_others_alone(): void
    {
        $this->assertSame('UGB', Trajet::cityLabel('SAINT-LOUIS'));
        $this->assertSame('UGB', Trajet::cityLabel(' saint-louis '));
        $this->assertSame('DAKAR', Trajet::cityLabel('DAKAR'));
        $this->assertNull(Trajet::cityLabel(null));
    }

    public function test_a_disabled_trajet_caravane_page_returns_404(): void
    {
        $trajet = $this->createTrajet(['disabled' => true]);

        $this->get($this->websiteUrl('/caravanes/'.$trajet->slug))->assertNotFound();
    }

    public function test_the_mobile_departs_endpoint_still_serves_a_disabled_trajet(): void
    {
        $trajet = $this->createTrajet(['disabled' => true]);

        $this->getJson('/api/mobile/departs/trajet/'.$trajet->id)->assertStatus(200);
    }

    public function test_a_caravane_page_resolves_by_slug_and_reuses_the_mobile_resource(): void
    {
        $trajet = $this->createTrajet();

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        $response->assertViewIs('website.caravanes.show');
        $response->assertViewHas('trajet', fn (Trajet $viewTrajet) => $viewTrajet->is($trajet));
        $response->assertViewHas('trajetDeparts', fn (array $data) => array_key_exists('departs', $data));
        $response->assertSee('Départs — '.$trajet->name);
        $response->assertSee("Aucun départ n'est programmé", false);
    }

    public function test_a_caravane_page_shows_a_visible_upcoming_depart_with_its_bus_price(): void
    {
        $trajet = $this->createTrajet();
        $depart = Depart::create([
            'name' => 'DEPART WEB '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create([
            'name' => 'Bus Web Test',
            'nombre_place' => 50,
            'ticket_price' => 4200,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        // The mobile resource labels a bus with no vehicule "Bus ordinaire".
        $response->assertSee('Bus ordinaire');
        $response->assertSee('4 200');
        $response->assertSee('Réserver');
        $response->assertSee('Heures de départ');
        $response->assertDontSee('Sélectionnez un bus pour continuer');
    }

    public function test_a_caravane_page_is_not_reachable_by_id(): void
    {
        $trajet = $this->createTrajet();

        $this->get($this->websiteUrl('/caravanes/'.$trajet->id))->assertNotFound();
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get($this->websiteUrl('/caravanes/caravane-inexistante-'.uniqid()))->assertNotFound();
    }

    public function test_the_legacy_trajets_path_is_not_registered(): void
    {
        $this->get($this->websiteUrl('/trajets/saint-louis-dakar'))->assertNotFound();
    }

    public function test_the_mobile_departs_endpoint_still_returns_json(): void
    {
        $trajet = $this->createTrajet();

        $response = $this->getJson('/api/mobile/departs/trajet/'.$trajet->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'name', 'departs', 'pointDeparts', 'destinations']);
    }
}

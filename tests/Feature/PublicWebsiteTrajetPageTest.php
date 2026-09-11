<?php

namespace Tests\Feature;

use App\Models\Depart;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    private function createVisibleUpcomingDepart(Trajet $trajet, array $busOverrides = []): Depart
    {
        $depart = Depart::create([
            'name' => 'DEPART WEB '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $depart->buses()->create(array_merge([
            'name' => 'Bus Web Test',
            'nombre_place' => 50,
            'ticket_price' => 4200,
            'closed' => false,
        ], $busOverrides));

        return $depart->fresh();
    }

    public function test_a_caravane_page_resolves_by_slug_and_exposes_its_departs(): void
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

    public function test_the_caravane_page_does_not_embed_pickup_times_and_loads_them_lazily(): void
    {
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $bus = $depart->buses()->first();
        $pointDep = PointDep::create(['name' => 'Gare Routière Lazy '.uniqid(), 'trajet_id' => $trajet->id]);
        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '07:30',
        ]);

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        // The pickup point is fetched only when the visitor expands "Heures de départ".
        $response->assertDontSee($pointDep->name);
        $response->assertSee('data-schedule-url', false);
        $response->assertSee(route('website.caravanes.schedule', ['depart' => $depart->id]).'?bus='.$bus->id, false);
    }

    public function test_the_caravane_page_runs_a_bounded_number_of_queries_regardless_of_depart_count(): void
    {
        $trajet = $this->createTrajet();
        for ($departNumber = 1; $departNumber <= 6; $departNumber++) {
            $this->createVisibleUpcomingDepart($trajet);
        }

        DB::enableQueryLog();
        $this->get($this->websiteUrl('/caravanes/'.$trajet->slug))->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The lean resource eager-loads départs + buses in a handful of queries; the old mobile
        // resource issued several per bus (hundreds on a busy trajet).
        $this->assertLessThan(40, $queryCount, "Caravane page ran {$queryCount} queries");
    }

    public function test_the_caravane_schedule_endpoint_returns_a_bus_pickup_schedule_as_json(): void
    {
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $bus = $depart->buses()->first();
        $pointDep = PointDep::create([
            'name' => 'Gare de Thiès '.uniqid(),
            'trajet_id' => $trajet->id,
            'arret_bus' => 'Devant la station',
        ]);
        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '06:15',
        ]);

        $response = $this->getJson($this->websiteUrl('/caravanes/horaires/'.$depart->id.'?bus='.$bus->id));

        $response->assertStatus(200);
        $response->assertExactJson([[
            'name' => $pointDep->name,
            'arret_bus' => 'Devant la station',
            'schedule' => '06h15',
        ]]);
    }

    public function test_the_caravane_schedule_endpoint_falls_back_to_the_depart_schedule(): void
    {
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $bus = $depart->buses()->first();
        $pointDep = PointDep::create(['name' => 'Arrêt Départ '.uniqid(), 'trajet_id' => $trajet->id]);
        // A départ-wide pickup time only — the bus has none of its own.
        HeureDepart::create([
            'depart_id' => $depart->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '08:00',
        ]);

        $response = $this->getJson($this->websiteUrl('/caravanes/horaires/'.$depart->id.'?bus='.$bus->id));

        $response->assertStatus(200);
        $response->assertExactJson([[
            'name' => $pointDep->name,
            'arret_bus' => null,
            'schedule' => '08h00',
        ]]);
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

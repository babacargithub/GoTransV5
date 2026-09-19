<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
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

    public function test_a_full_bus_still_shows_an_active_reserver_link(): void
    {
        // createVisibleUpcomingDepart's bus has no bus_seats rows at all, so it has zero free
        // seats — i.e. it's "full". The website must still offer it as bookable: the backend
        // waitlists the customer instead of rejecting the booking outright.
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $bus = $depart->buses()->first();
        $this->assertTrue($bus->isFull());

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        $response->assertDontSee('cursor-not-allowed', false);
        $response->assertSee(
            route('website.bookings.create', ['depart' => $depart->id, 'bus_id' => $bus->id]),
            false,
        );
    }

    public function test_a_closed_bus_still_shows_an_active_reserver_link(): void
    {
        // Closing a bus/départ is a back-office action, not a "sold out" signal — same treatment
        // as "full": the customer can still start a booking and the backend decides what to do.
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet, ['closed' => true]);
        $bus = $depart->buses()->first();
        $this->assertTrue($bus->isClosed());

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        $response->assertDontSee('cursor-not-allowed', false);
        $response->assertSee(
            route('website.bookings.create', ['depart' => $depart->id, 'bus_id' => $bus->id]),
            false,
        );
    }

    public function test_a_passed_departure_shows_as_unavailable(): void
    {
        // The only remaining "unavailable" reason: a départ that has already left cannot be
        // booked, no matter what — full/closed buses stay bookable (see the two tests above).
        $trajet = $this->createTrajet();
        $depart = Depart::create([
            'name' => 'DEPART PASSE '.uniqid(),
            'date' => now()->subDay(),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create(['name' => 'Bus Passé', 'nombre_place' => 50, 'ticket_price' => 4200, 'closed' => false]);

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        // A past départ never matches the resource's `date >= now()` filter, so the page simply
        // shows no upcoming trip for it — this locks in that behaviour rather than a "Complet" row.
        $response->assertDontSee($depart->name);
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

    public function test_the_caravane_page_carries_no_session_cookie(): void
    {
        $trajet = $this->createTrajet();

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        // The read-only public pages strip session/cookie middleware entirely.
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_the_caravane_page_is_served_from_cache_on_the_second_request(): void
    {
        config(['app.public_page_cache_enabled' => true]);
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $url = $this->websiteUrl('/caravanes/'.$trajet->slug);

        $first = $this->get($url);
        $first->assertStatus(200);
        $this->assertSame('MISS', $first->headers->get('X-Cache'));

        DB::enableQueryLog();
        $second = $this->get($url);
        $queriesOnHit = count(DB::getQueryLog());
        DB::disableQueryLog();

        $second->assertStatus(200);
        $this->assertSame('HIT', $second->headers->get('X-Cache'));
        // Not a byte-for-byte comparison against $first: Laravel's own RequestHandled listeners
        // (e.g. Livewire's global asset injector) can mutate a response's content after our
        // middleware has already cached it, which is cosmetic and unrelated to whether the page
        // itself was actually served from cache. Assert on the page's own content instead.
        $second->assertSee($depart->name);
        // Only the {trajet:slug} route-model binding still runs on a cache hit.
        $this->assertLessThanOrEqual(1, $queriesOnHit);
    }

    public function test_the_caravane_page_sends_public_cache_control_headers(): void
    {
        config(['app.public_page_cache_enabled' => true, 'app.public_page_cache_ttl' => 60]);
        $trajet = $this->createTrajet();

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        // Symfony's ResponseHeaderBag reorders Cache-Control directives, so assert on content
        // rather than the exact header string.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('s-maxage=60', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=600', $cacheControl);
    }

    public function test_editing_a_depart_invalidates_the_cached_caravane_page(): void
    {
        config(['app.public_page_cache_enabled' => true]);
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $url = $this->websiteUrl('/caravanes/'.$trajet->slug);

        $this->get($url)->assertSee($depart->name);

        $newName = 'DEPART RENAMED '.uniqid();
        $depart->update(['name' => $newName]);

        $response = $this->get($url);
        $this->assertSame('MISS', $response->headers->get('X-Cache'));
        $response->assertSee($newName);
    }

    public function test_a_new_booking_does_not_invalidate_the_cached_caravane_page(): void
    {
        config(['app.public_page_cache_enabled' => true]);
        $trajet = $this->createTrajet();
        $depart = $this->createVisibleUpcomingDepart($trajet);
        $bus = $depart->buses()->first();
        $pointDep = PointDep::create(['name' => 'Point '.uniqid(), 'trajet_id' => $trajet->id]);
        $destination = Destination::create(['name' => 'Destination '.uniqid(), 'trajet_id' => $trajet->id]);
        $customer = Customer::create(['prenom' => 'Test', 'nom' => 'Client', 'phone_number' => '77'.random_int(1000000, 9999999)]);
        $url = $this->websiteUrl('/caravanes/'.$trajet->slug);

        $this->get($url)->assertStatus(200);

        Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'destination_id' => $destination->id,
            'paye' => false,
            'group_id' => random_int(1, PHP_INT_MAX),
        ]);

        $response = $this->get($url);
        // Bookings are deliberately excluded from the invalidation set (see AppServiceProvider) —
        // seat availability is allowed to lag by up to the cache TTL.
        $this->assertSame('HIT', $response->headers->get('X-Cache'));
    }
}

<?php

namespace Tests\Feature;

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

    public function test_the_home_page_lists_trajets_linking_to_their_caravane_page(): void
    {
        $trajet = Trajet::create([
            'name' => 'Slug List '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);

        $response = $this->get($this->websiteUrl('/'));

        $response->assertStatus(200);
        $response->assertViewIs('website.home');
        $response->assertSee($trajet->name);
        $response->assertSee($this->websiteUrl('/caravanes/'.$trajet->slug), false);
    }

    public function test_a_caravane_page_resolves_by_slug(): void
    {
        $trajet = Trajet::create([
            'name' => 'Resolve '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'KAOLACK',
        ]);

        $response = $this->get($this->websiteUrl('/caravanes/'.$trajet->slug));

        $response->assertStatus(200);
        $response->assertViewIs('website.caravanes.show');
        $response->assertViewHas('trajet', fn (Trajet $viewTrajet) => $viewTrajet->is($trajet));
        $response->assertSee('Départs — '.$trajet->name);
    }

    public function test_a_caravane_page_is_not_reachable_by_id(): void
    {
        $trajet = Trajet::create([
            'name' => 'ById '.uniqid(),
            'departure_city' => 'MBOUR',
            'arrival_city' => 'DAKAR',
        ]);

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
}

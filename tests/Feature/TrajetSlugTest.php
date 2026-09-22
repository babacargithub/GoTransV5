<?php

namespace Tests\Feature;

use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrajetSlugTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_generates_a_slug_from_the_city_pair_on_create(): void
    {
        $trajet = Trajet::create([
            'name' => 'Test '.uniqid(),
            'departure_city' => 'THIÈS',
            'arrival_city' => 'ZIGUINCHOR',
        ]);

        $this->assertSame('thies-ziguinchor', $trajet->slug);
    }

    public function test_it_prefers_the_public_name_when_one_is_set(): void
    {
        $trajet = Trajet::create([
            'name' => 'UGB-DK',
            'public_name' => 'Caravane Express '.uniqid(),
            'departure_city' => 'SAINT-LOUIS',
            'arrival_city' => 'DAKAR',
        ]);

        $this->assertSame(Str::slug($trajet->public_name), $trajet->slug);
    }

    public function test_it_falls_back_to_the_name_when_no_city_pair_is_set(): void
    {
        $uniqueName = 'Navette Interne '.uniqid();

        $trajet = Trajet::create(['name' => $uniqueName]);

        $this->assertSame(Str::slug($uniqueName), $trajet->slug);
    }

    public function test_it_appends_a_numeric_suffix_when_the_slug_is_already_taken(): void
    {
        $suffix = uniqid();
        $first = Trajet::create([
            'name' => "First $suffix",
            'departure_city' => "Ville$suffix",
            'arrival_city' => 'Autre',
        ]);
        $second = Trajet::create([
            'name' => "Second $suffix",
            'departure_city' => "Ville$suffix",
            'arrival_city' => 'Autre',
        ]);

        $this->assertSame("{$first->slug}-2", $second->slug);
    }

    public function test_it_keeps_an_existing_slug_untouched_on_update(): void
    {
        $trajet = Trajet::create([
            'name' => 'Test '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'MBOUR',
        ]);
        $originalSlug = $trajet->slug;

        $trajet->update(['departure_city' => 'KAOLACK']);

        $this->assertSame($originalSlug, $trajet->fresh()->slug);
    }
}
